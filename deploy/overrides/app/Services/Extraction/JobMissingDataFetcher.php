<?php
namespace App\Services\Extraction;

use App\Models\JobCandidate;
use App\Services\Crawling\ContentNormalizer;
use App\Services\Crawling\HttpFetcher;
use App\Services\Crawling\PdfTextExtractor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class JobMissingDataFetcher
{
    private const SKIP_FIELDS = ['featured_job','urgent_hiring','seo_title','seo_description','seo_keywords'];
    private const INTEGER_FIELDS = ['total_vacancies','age_minimum','age_maximum','salary_minimum','salary_maximum','application_fee'];
    private const DATE_FIELDS = ['application_start_date','application_end_date','exam_date','admit_card_date'];

    public function __construct(
        private HttpFetcher $httpFetcher,
        private PdfTextExtractor $pdfTextExtractor,
        private ContentNormalizer $contentNormalizer,
    ) {}

    public function fetch(JobCandidate $candidate): array
    {
        $apiKey = trim((string) config('services.claude.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('CLAUDE_API_KEY is not configured on the server.');
        }

        $data = is_array($candidate->extracted_data) ? $candidate->extracted_data : [];
        $missing = $this->missingFields($data);

        if ($missing === []) {
            return [
                'fetched_count'=>0,
                'fetched_fields'=>[],
                'not_found'=>[],
                'source_mode'=>'none',
                'web_searches_used'=>0,
                'requires_admin_approval'=>false,
            ];
        }

        // Read official evidence locally first, then send ALL pending fields in ONE Claude API request.
        // Claude may web-search only for fields that the official evidence cannot verify.
        $officialBundle = $this->officialEvidenceBundle($candidate, $data);
        $body = $this->callClaude(
            $apiKey,
            $this->payload($candidate, $data, $missing, $officialBundle),
            $candidate
        );

        $research = $this->structuredOutput($body, 'submit_job_research', $candidate);
        $searchedUrls = $this->sourceUrls($body);
        $webSearchesUsed = (int) data_get($body, 'usage.server_tool_use.web_search_requests', 0);

        // Stage 2: cheap Haiku pass converts Sonnet research into final form-ready values.
        // No tools are exposed to Haiku, so all web research remains on Sonnet.
        $structured = $this->fillWithHaiku(
            $apiKey,
            $candidate,
            $data,
            $missing,
            $research,
            $officialBundle !== [] ? 'official_evidence_first' : 'web_search_only'
        );
        $structured = $this->mergeWithVerifiedResearch($structured, $research, $missing);

        $references = is_array($data['field_sources'] ?? null) ? $data['field_sources'] : [];
        $pendingApproval = is_array($data['claude_pending_approval'] ?? null) ? $data['claude_pending_approval'] : [];
        $accepted = [];

        foreach (($structured['results'] ?? []) as $row) {
            if (!is_array($row)) continue;

            $field = (string)($row['field'] ?? '');
            if (!in_array($field, $missing, true) || !$this->isMissing($data[$field] ?? null)) {
                continue;
            }

            $confidence = (float)($row['confidence'] ?? 0);
            $value = $this->normalizeValue($field, $row['value'] ?? null);
            if ($confidence < 0.72 || $value === null || $value === '') {
                continue;
            }

            $sourceUrl = trim((string)($row['source_url'] ?? ''));
            $sourceKind = (string)($row['source_kind'] ?? '');

            if ($sourceKind === 'official_evidence') {
                if ($officialBundle === []) continue;
                $bundleUrls = array_map(fn($item) => $this->normalizeUrl($item['url']), $officialBundle);
                if (!$this->validUrl($sourceUrl) || !in_array($this->normalizeUrl($sourceUrl), $bundleUrls, true)) {
                    $sourceUrl = $officialBundle[0]['url'];
                }
                $provider = 'claude_official_evidence';
                $sourceTitle = $this->nullableString($row['source_title'] ?? null) ?: 'Official recruitment evidence';
            } elseif ($sourceKind === 'web_search') {
                if (!$this->validUrl($sourceUrl)) continue;
                if ($searchedUrls !== [] && !in_array($this->normalizeUrl($sourceUrl), $searchedUrls, true)) {
                    continue;
                }
                $provider = 'claude_web_search';
                $sourceTitle = $this->nullableString($row['source_title'] ?? null) ?: 'Claude web source';
            } else {
                continue;
            }

            $data[$field] = $value;
            $references[$field] = [
                'provider'=>$provider,
                'source_url'=>$sourceUrl,
                'source_title'=>$sourceTitle,
                'source_page'=>$this->nullableString($row['source_page'] ?? null),
                'reference'=>$this->nullableString($row['source_excerpt'] ?? null),
                'confidence'=>$confidence,
                'needs_admin_approval'=>true,
                'fetched_at'=>now()->toIso8601String(),
            ];
            $accepted[] = $field;
            $pendingApproval[] = $field;
        }

        $accepted = array_values(array_unique($accepted));
        $notFound = array_values(array_unique(array_filter(
            array_merge($structured['not_found'] ?? [], array_diff($missing, $accepted)),
            fn($field) => in_array($field, $missing, true)
        )));

        // Keep unresolved fields empty/pending. Never guess. Explicitly show "Source unavailable".
        foreach ($notFound as $field) {
            if (!$this->isMissing($data[$field] ?? null)) continue;
            $references[$field] = [
                'provider'=>'claude_unavailable',
                'source_url'=>null,
                'source_title'=>'Source unavailable',
                'source_page'=>null,
                'reference'=>'Reliable information could not be verified from the official evidence or permitted web searches.',
                'confidence'=>null,
                'needs_admin_approval'=>false,
                'checked_at'=>now()->toIso8601String(),
            ];
        }

        $data['field_sources'] = $references;
        $data['claude_pending_approval'] = array_values(array_unique($pendingApproval));
        $data['pending_fields'] = array_values(array_filter(
            JobDetailsEnricher::FIELDS,
            fn($field) => $this->isMissing($data[$field] ?? null)
        ));

        $candidate->extracted_data = $data;
        if (!empty($data['job_title'])) $candidate->job_title = $data['job_title'];
        if (!empty($data['organization'])) $candidate->organization = $data['organization'];
        if (array_key_exists('total_vacancies',$data)) $candidate->total_vacancies = $data['total_vacancies'];
        if (!empty($data['application_end_date'])) $candidate->application_last_date = $data['application_end_date'];
        if (!empty($data['apply_url'])) $candidate->application_url = $data['apply_url'];
        $candidate->save();

        return [
            'fetched_count'=>count($accepted),
            'fetched_fields'=>$accepted,
            'not_found'=>$notFound,
            'source_mode'=>$officialBundle !== [] ? 'official_evidence_first' : 'web_search_only',
            'web_searches_used'=>$webSearchesUsed,
            'requires_admin_approval'=>count($accepted) > 0,
        ];
    }

    public function retryMissingOnly(JobCandidate $candidate): array
    {
        $apiKey = trim((string) config('services.claude.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('CLAUDE_API_KEY is not configured on the server.');
        }

        $data = is_array($candidate->extracted_data) ? $candidate->extracted_data : [];
        $references = is_array($data['field_sources'] ?? null) ? $data['field_sources'] : [];

        // Retry ONLY fields that are still empty/pending and were previously marked unavailable.
        $retryFields = array_values(array_filter(
            JobDetailsEnricher::FIELDS,
            fn($field) =>
                !in_array($field, self::SKIP_FIELDS, true)
                && $this->isMissing($data[$field] ?? null)
                && (($references[$field]['provider'] ?? null) === 'claude_unavailable')
        ));

        if ($retryFields === []) {
            return [
                'fetched_count'=>0,
                'fetched_fields'=>[],
                'not_found'=>[],
                'web_searches_used'=>0,
                'message'=>'No Source unavailable fields need retry.',
            ];
        }

        // Priority order: all already-known official/job URLs first, then ONE combined web search.
        $officialBundle = $this->relatedOfficialEvidence($candidate, $data);
        $body = $this->callClaude(
            $apiKey,
            $this->retryPayload($candidate, $data, $retryFields, $officialBundle),
            $candidate
        );

        $research = $this->structuredOutput($body, 'submit_job_research', $candidate);
        $searchedUrls = $this->sourceUrls($body);
        $webSearchesUsed = (int) data_get($body, 'usage.server_tool_use.web_search_requests', 0);

        // Retry also uses Sonnet for research/search and Haiku only for final form filling.
        $structured = $this->fillWithHaiku(
            $apiKey,
            $candidate,
            $data,
            $retryFields,
            $research,
            'retry_missing_only'
        );
        $structured = $this->mergeWithVerifiedResearch($structured, $research, $retryFields);

        $officialUrls = array_map(fn($item) => $this->normalizeUrl($item['url']), $officialBundle);

        $pendingApproval = is_array($data['claude_pending_approval'] ?? null) ? $data['claude_pending_approval'] : [];
        $accepted = [];

        foreach (($structured['results'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $field = (string)($row['field'] ?? '');

            // Never touch fields outside this retry set or fields that acquired a value meanwhile.
            if (!in_array($field, $retryFields, true) || !$this->isMissing($data[$field] ?? null)) continue;

            $confidence = (float)($row['confidence'] ?? 0);
            $value = $this->normalizeValue($field, $row['value'] ?? null);
            if ($confidence < 0.72 || $value === null || $value === '') continue;

            $sourceUrl = trim((string)($row['source_url'] ?? ''));
            $sourceKind = (string)($row['source_kind'] ?? '');

            if ($sourceKind === 'official_evidence') {
                if (!$this->validUrl($sourceUrl) || !in_array($this->normalizeUrl($sourceUrl), $officialUrls, true)) continue;
                $provider = 'claude_retry_official';
                $sourceTitle = $this->nullableString($row['source_title'] ?? null) ?: 'Related official source';
            } elseif ($sourceKind === 'web_search') {
                if (!$this->validUrl($sourceUrl)) continue;
                if ($searchedUrls !== [] && !in_array($this->normalizeUrl($sourceUrl), $searchedUrls, true)) continue;
                $provider = 'claude_retry_web_search';
                $sourceTitle = $this->nullableString($row['source_title'] ?? null) ?: 'Claude targeted retry source';
            } else {
                continue;
            }

            $data[$field] = $value;
            $references[$field] = [
                'provider'=>$provider,
                'source_url'=>$sourceUrl,
                'source_title'=>$sourceTitle,
                'source_page'=>$this->nullableString($row['source_page'] ?? null),
                'reference'=>$this->nullableString($row['source_excerpt'] ?? null),
                'confidence'=>$confidence,
                'needs_admin_approval'=>true,
                'retry_fetched_at'=>now()->toIso8601String(),
            ];
            $accepted[] = $field;
            $pendingApproval[] = $field;
        }

        $accepted = array_values(array_unique($accepted));
        $notFound = array_values(array_unique(array_filter(
            array_merge($structured['not_found'] ?? [], array_diff($retryFields, $accepted)),
            fn($field) => in_array($field, $retryFields, true)
        )));

        foreach ($notFound as $field) {
            if (!$this->isMissing($data[$field] ?? null)) continue;
            $references[$field] = [
                'provider'=>'claude_unavailable',
                'source_url'=>null,
                'source_title'=>'Source unavailable',
                'source_page'=>null,
                'reference'=>'Still not verifiable after checking related official sources and one targeted fallback web search. Admin may approve this field as Not Available.',
                'confidence'=>null,
                'needs_admin_approval'=>false,
                'retry_checked_at'=>now()->toIso8601String(),
            ];
        }

        $data['field_sources'] = $references;
        $data['claude_pending_approval'] = array_values(array_unique($pendingApproval));
        $data['pending_fields'] = array_values(array_filter(
            JobDetailsEnricher::FIELDS,
            fn($field) => $this->isMissing($data[$field] ?? null)
                && !in_array($field, $data['admin_not_available_fields'] ?? [], true)
        ));

        $candidate->extracted_data = $data;
        if (!empty($data['job_title'])) $candidate->job_title = $data['job_title'];
        if (!empty($data['organization'])) $candidate->organization = $data['organization'];
        if (array_key_exists('total_vacancies',$data)) $candidate->total_vacancies = $data['total_vacancies'];
        if (!empty($data['application_end_date'])) $candidate->application_last_date = $data['application_end_date'];
        if (!empty($data['apply_url'])) $candidate->application_url = $data['apply_url'];
        $candidate->save();

        return [
            'fetched_count'=>count($accepted),
            'fetched_fields'=>$accepted,
            'not_found'=>$notFound,
            'web_searches_used'=>$webSearchesUsed,
            'official_sources_checked'=>count($officialBundle),
            'requires_admin_approval'=>count($accepted) > 0,
        ];
    }

    public function approveUnavailable(JobCandidate $candidate): array
    {
        $data = is_array($candidate->extracted_data) ? $candidate->extracted_data : [];
        $references = is_array($data['field_sources'] ?? null) ? $data['field_sources'] : [];
        $approved = is_array($data['admin_not_available_fields'] ?? null) ? $data['admin_not_available_fields'] : [];

        foreach (JobDetailsEnricher::FIELDS as $field) {
            if (!$this->isMissing($data[$field] ?? null)) continue;
            if (($references[$field]['provider'] ?? null) !== 'claude_unavailable') continue;

            $references[$field] = [
                'provider'=>'admin_not_available',
                'source_url'=>null,
                'source_title'=>'Not Available · Admin approved',
                'source_page'=>null,
                'reference'=>'Admin approved this field as Not Available after retry.',
                'confidence'=>null,
                'needs_admin_approval'=>false,
                'approved_at'=>now()->toIso8601String(),
            ];
            $approved[] = $field;
        }

        $approved = array_values(array_unique($approved));
        $data['field_sources'] = $references;
        $data['admin_not_available_fields'] = $approved;
        $data['pending_fields'] = array_values(array_filter(
            JobDetailsEnricher::FIELDS,
            fn($field) => $this->isMissing($data[$field] ?? null) && !in_array($field, $approved, true)
        ));

        $candidate->extracted_data = $data;
        $candidate->save();

        return ['approved_count'=>count($approved),'approved_fields'=>$approved];
    }

    private function relatedOfficialEvidence(JobCandidate $candidate, array $data): array
    {
        $urls = array_values(array_unique(array_filter([
            (string)$candidate->notification_pdf_url,
            (string)$candidate->official_source_url,
            (string)$candidate->application_url,
            (string)($data['official_notification_url'] ?? ''),
            (string)($data['apply_url'] ?? ''),
        ], fn($url) => $this->validUrl(trim($url)))));

        $items = [];
        foreach ($urls as $url) {
            try {
                $result = $this->httpFetcher->fetch($url);
                $isPdf = str_contains(strtolower((string)$result->contentType), 'application/pdf')
                    || str_ends_with(strtolower((string)(parse_url($result->url, PHP_URL_PATH) ?: '')), '.pdf');
                $text = $isPdf
                    ? (string)($this->pdfTextExtractor->extract($result->body)['text'] ?? '')
                    : $this->contentNormalizer->text($result->body);

                if (mb_strlen(trim($text)) < 120) continue;
                $items[] = [
                    'url'=>$result->url ?: $url,
                    'title'=>$isPdf ? 'Official notification / related PDF' : 'Official / related recruitment page',
                    'text'=>mb_substr(trim($text), 0, 30000),
                ];
            } catch (Throwable $e) {
                Log::warning('Retry official source read failed', [
                    'candidate_id'=>$candidate->id,
                    'url'=>$url,
                    'error'=>$e->getMessage(),
                ]);
            }
        }

        return $items;
    }

    private function retryPayload(JobCandidate $candidate, array $data, array $retryFields, array $officialBundle): array
    {
        $officialText = $officialBundle === []
            ? 'No additional readable official/job source was available.'
            : implode("\n\n--- OFFICIAL/RELATED SOURCE ---\n", array_map(
                fn($item) => "URL: ".$item['url']."\n".$item['text'],
                $officialBundle
            ));

        $system = <<<'TXT'
You are performing a SECOND-PASS retry for Indian government recruitment data.

STRICT RETRY RULES:
1. Work ONLY on the listed Source unavailable fields. Do not change, restate, or propose edits to any existing field.
2. Check the supplied official/related job sources FIRST and extract as many retry fields as they can verify.
3. Only after exhausting those supplied sources, use web search for the remaining retry fields.
4. You have at most ONE web search use. Combine ALL still-unresolved retry fields into that single targeted search.
5. Prefer official government/recruitment sources in search results.
6. Never guess or infer unsupported values. If not verified, put the field in not_found.
7. Every result requires a supporting source URL and source_kind "official_evidence" or "web_search".
8. After retry research is complete, call the submit_job_research tool EXACTLY ONCE with the complete structured result. Do not return the final result as prose or markdown.
TXT;

        return [
            'model'=>config('services.claude.research_model','claude-sonnet-5-5'),
            'max_tokens'=>5000,
            'system'=>$system,
            'messages'=>[[
                'role'=>'user',
                'content'=>"Job context:\n"
                    .json_encode($this->context($candidate,$data), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
                    ."\n\nSOURCE UNAVAILABLE FIELDS TO RETRY ONLY:\n".implode(', ', $retryFields)
                    ."\n\nOFFICIAL/RELATED SOURCES TO CHECK FIRST:\n".$officialText,
            ]],
            'tools'=>[
                [
                    'type'=>'web_search_20250305',
                    'name'=>'web_search',
                    'max_uses'=>1,
                    'user_location'=>[
                        'type'=>'approximate',
                        'country'=>'IN',
                        'timezone'=>'Asia/Kolkata',
                    ],
                ],
                $this->structuredResultTool(
                    'submit_job_research',
                    'Submit the final verified retry research for the unavailable job fields.'
                ),
            ],
        ];
    }

    private function missingFields(array $data): array
    {
        $references = is_array($data['field_sources'] ?? null) ? $data['field_sources'] : [];

        return array_values(array_filter(
            JobDetailsEnricher::FIELDS,
            fn($field) =>
                !in_array($field, self::SKIP_FIELDS, true)
                && $this->isMissing($data[$field] ?? null)
                && !$this->hasSuccessfulSource($references[$field] ?? null)
        ));
    }

    private function hasSuccessfulSource(mixed $source): bool
    {
        if (!is_array($source)) return false;
        if (($source['provider'] ?? null) === 'claude_unavailable') return false;
        return $this->validUrl((string)($source['source_url'] ?? ''));
    }

    private function isMissing(mixed $value): bool
    {
        if ($value === null) return true;
        if (!is_string($value)) return false;
        $v = Str::of($value)->trim()->lower()->squish()->toString();
        return $v === '' || in_array($v, [
            'pending','unknown','not known','not available','n/a','na','tbd','to be updated','-','--'
        ], true);
    }

    private function officialEvidenceBundle(JobCandidate $candidate, array $data): array
    {
        $urls = array_values(array_unique(array_filter([
            (string)$candidate->notification_pdf_url,
            (string)$candidate->official_source_url,
            (string)($data['discovered_from_url'] ?? ''),
            (string)($data['source_url'] ?? ''),
        ], fn($url) => $this->validUrl(trim($url)))));

        $items = [];
        foreach ($urls as $url) {
            try {
                $result = $this->httpFetcher->fetch($url);
                $isPdf = str_contains(strtolower((string)$result->contentType), 'application/pdf')
                    || str_ends_with(strtolower((string)(parse_url($result->url, PHP_URL_PATH) ?: '')), '.pdf');

                $text = $isPdf
                    ? (string)($this->pdfTextExtractor->extract($result->body)['text'] ?? '')
                    : $this->contentNormalizer->text($result->body);

                $text = trim($text);
                if (mb_strlen($text) < 120) continue;

                $items[] = [
                    'kind'=>$isPdf ? 'official_notification_pdf' : 'official_source_page',
                    'url'=>$result->url ?: $url,
                    'title'=>$isPdf ? 'Official notification / PDF' : 'Official recruitment/source page',
                    'text'=>mb_substr($text, 0, 45000),
                ];
            } catch (Throwable $e) {
                Log::warning('Official evidence bundle read failed', [
                    'candidate_id'=>$candidate->id,
                    'url'=>$url,
                    'error'=>$e->getMessage(),
                ]);
            }
        }

        $raw = trim((string)($data['raw_text'] ?? ''));
        $fallbackUrl = $this->validUrl((string)$candidate->official_source_url)
            ? (string)$candidate->official_source_url
            : (string)$candidate->notification_pdf_url;

        if ($items === [] && mb_strlen($raw) >= 120 && $this->validUrl($fallbackUrl)) {
            $items[] = [
                'kind'=>'official_source_cached',
                'url'=>$fallbackUrl,
                'title'=>'Cached official recruitment evidence',
                'text'=>mb_substr($raw, 0, 45000),
            ];
        }

        return $items;
    }

    private function context(JobCandidate $candidate, array $data): array
    {
        $known = [];
        foreach (JobDetailsEnricher::FIELDS as $field) {
            if (array_key_exists($field, $data) && !$this->isMissing($data[$field])) {
                $known[$field] = is_string($data[$field])
                    ? Str::limit($data[$field], 2500, '')
                    : $data[$field];
            }
        }

        return [
            'job_title'=>$candidate->job_title,
            'organization'=>$candidate->organization,
            'official_source_url'=>$candidate->official_source_url,
            'notification_pdf_url'=>$candidate->notification_pdf_url,
            'application_url'=>$candidate->application_url,
            'application_last_date'=>optional($candidate->application_last_date)->format('Y-m-d'),
            'current_known_fields'=>$known,
        ];
    }

    private function mergeWithVerifiedResearch(array $filled, array $research, array $targetFields): array
    {
        // Sonnet is authoritative. Haiku only formats values.
        $filledByField = [];
        foreach (($filled['results'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $field = (string)($row['field'] ?? '');
            if ($field !== '' && in_array($field, $targetFields, true)) {
                $filledByField[$field] = $row;
            }
        }

        $results = [];
        foreach (($research['results'] ?? []) as $sonnetRow) {
            if (!is_array($sonnetRow)) continue;
            $field = (string)($sonnetRow['field'] ?? '');
            if ($field === '' || !in_array($field, $targetFields, true)) continue;

            // Use Haiku's formatted value when present, but keep every other property from Sonnet.
            if (isset($filledByField[$field]['value'])) {
                $sonnetRow['value'] = $filledByField[$field]['value'];
            }

            $results[] = $sonnetRow;
        }

        // Only Sonnet can declare a field unavailable.
        $notFound = array_values(array_filter(
            array_unique($research['not_found'] ?? []),
            fn($field) => in_array($field, $targetFields, true)
        ));

        return ['results'=>$results,'not_found'=>$notFound];
    }

    private function structuredResultTool(string $name, string $description): array
    {
        return [
            'name'=>$name,
            'description'=>$description,
            'input_schema'=>[
                'type'=>'object',
                'additionalProperties'=>false,
                'properties'=>[
                    'results'=>[
                        'type'=>'array',
                        'items'=>[
                            'type'=>'object',
                            'additionalProperties'=>false,
                            'properties'=>[
                                'field'=>['type'=>'string'],
                                'value'=>['type'=>'string'],
                                'confidence'=>['type'=>'number','minimum'=>0,'maximum'=>1],
                                'source_kind'=>['type'=>'string','enum'=>['official_evidence','web_search']],
                                'source_url'=>['type'=>'string'],
                                'source_title'=>['type'=>'string'],
                                'source_page'=>['type'=>['string','null']],
                                'source_excerpt'=>['type'=>'string'],
                            ],
                            'required'=>[
                                'field','value','confidence','source_kind','source_url',
                                'source_title','source_page','source_excerpt'
                            ],
                        ],
                    ],
                    'not_found'=>[
                        'type'=>'array',
                        'items'=>['type'=>'string'],
                    ],
                ],
                'required'=>['results','not_found'],
            ],
        ];
    }

    private function payload(JobCandidate $candidate, array $data, array $missing, array $officialBundle): array
    {
        $officialText = $officialBundle !== []
            ? implode("\n\n--- OFFICIAL EVIDENCE SOURCE ---\n", array_map(
                fn($item) => "URL: ".$item['url']."\nTITLE: ".$item['title']."\nTEXT:\n".$item['text'],
                $officialBundle
            ))
            : "OFFICIAL EVIDENCE: unavailable or unreadable.";

        $system = <<<'TXT'
You extract Indian government recruitment facts for an admin review system.

STRICT RULES:
1. Fill ALL requested missing fields together in this single request. Never make one API workflow per field.
2. Use the provided official notification/PDF or official recruitment source FIRST.
3. For any field that cannot be verified from the official evidence, you MAY use web search. Do not search if the official evidence already supports the field.
4. Group unresolved fields into as few searches as possible. Never exceed the web-search tool's configured max_uses.
5. Never overwrite or propose changes to existing non-empty values.
6. Never guess, infer from convention, or invent values. If a value cannot be verified, put the field in not_found.
7. Every result must include its source. Set source_kind to "official_evidence" when supported by the supplied official evidence, or "web_search" when supported by a web result.
8. For web_search results, source_url must be the actual supporting URL returned by web search.
9. Dates must be YYYY-MM-DD. Numeric fields must contain only the numeric value.
10. After research is complete, call the submit_job_research tool EXACTLY ONCE with the complete structured result. Do not return the final result as prose or markdown.
TXT;

        return [
            'model'=>config('services.claude.research_model','claude-sonnet-5-5'),
            'max_tokens'=>7000,
            'system'=>$system,
            'messages'=>[[
                'role'=>'user',
                'content'=>"Job context:\n"
                    .json_encode($this->context($candidate,$data), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
                    ."\n\nPENDING FIELDS TO FILL:\n".implode(', ', $missing)
                    ."\n\n".$officialText,
            ]],
            'tools'=>[
                [
                    'type'=>'web_search_20250305',
                    'name'=>'web_search',
                    'max_uses'=>2,
                    'user_location'=>[
                        'type'=>'approximate',
                        'country'=>'IN',
                        'timezone'=>'Asia/Kolkata',
                    ],
                ],
                $this->structuredResultTool(
                    'submit_job_research',
                    'Submit the final verified research for all requested missing job fields.'
                ),
            ],
        ];
    }

    private function fillWithHaiku(
        string $apiKey,
        JobCandidate $candidate,
        array $data,
        array $targetFields,
        array $research,
        string $mode
    ): array {
        $researchResults = is_array($research['results'] ?? null) ? $research['results'] : [];

        // Sonnet is the ONLY authority for verification, sources, confidence and not_found.
        // Haiku is used only as a cheap form formatter/filler.
        if ($researchResults === []) {
            return ['results'=>[], 'not_found'=>[]];
        }

        $authoritative = [];
        foreach ($researchResults as $row) {
            if (!is_array($row)) continue;
            $field = (string)($row['field'] ?? '');
            if ($field === '' || !in_array($field, $targetFields, true)) continue;
            $authoritative[$field] = $row;
        }

        if ($authoritative === []) {
            return ['results'=>[], 'not_found'=>[]];
        }

        $payload = [
            'model'=>config('services.claude.fill_model','claude-haiku-5-5'),
            'max_tokens'=>2500,
            'system'=><<<'TXT'
You are a FORM FILLER only.

The Sonnet research supplied to you is authoritative and already verified.
You MUST NOT verify facts, judge correctness, reject fields, perform research, infer missing information, change sources, change confidence, or decide whether a field is available.

Your only task:
- copy each supplied Sonnet field into a simple form-ready field/value pair;
- normalize obvious formatting only when required by the field type;
- never omit a supplied field intentionally;
- never add any field that Sonnet did not supply;
- never output not_found;
- never use tools other than the required submit_form_fill tool.

Return the result only through submit_form_fill.
TXT,
            'messages'=>[[
                'role'=>'user',
                'content'=>"MODE: {$mode}\n"
                    ."TARGET FIELDS:\n".implode(', ', $targetFields)
                    ."\n\nAUTHORITATIVE SONNET RESULTS:\n"
                    .json_encode(array_values($authoritative), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
            ]],
            'tools'=>[[
                'name'=>'submit_form_fill',
                'description'=>'Copy Sonnet-verified values into form-ready field/value pairs only.',
                'input_schema'=>[
                    'type'=>'object',
                    'additionalProperties'=>false,
                    'properties'=>[
                        'results'=>[
                            'type'=>'array',
                            'items'=>[
                                'type'=>'object',
                                'additionalProperties'=>false,
                                'properties'=>[
                                    'field'=>['type'=>'string'],
                                    'value'=>['type'=>'string'],
                                ],
                                'required'=>['field','value'],
                            ],
                        ],
                    ],
                    'required'=>['results'],
                ],
            ]],
            'tool_choice'=>[
                'type'=>'tool',
                'name'=>'submit_form_fill',
                'disable_parallel_tool_use'=>true,
            ],
        ];

        $body = $this->callClaude($apiKey, $payload, $candidate);

        $filledPairs = [];
        try {
            foreach (($body['content'] ?? []) as $part) {
                if (($part['type'] ?? null) !== 'tool_use' || ($part['name'] ?? null) !== 'submit_form_fill') continue;
                $input = $part['input'] ?? null;
                if (!is_array($input)) continue;
                foreach (($input['results'] ?? []) as $pair) {
                    if (!is_array($pair)) continue;
                    $field = (string)($pair['field'] ?? '');
                    if ($field === '' || !isset($authoritative[$field])) continue;
                    $filledPairs[$field] = $pair['value'] ?? null;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Haiku form-fill parsing failed; Sonnet fallback will be used', [
                'candidate_id'=>$candidate->id,
                'error'=>$e->getMessage(),
            ]);
        }

        // Build final rows from Sonnet authority.
        // Haiku may only provide the final display/form value; all other metadata remains Sonnet's.
        $results = [];
        foreach ($authoritative as $field=>$sonnetRow) {
            $value = array_key_exists($field, $filledPairs)
                ? $filledPairs[$field]
                : ($sonnetRow['value'] ?? null);

            $normalized = $this->normalizeValue($field, $value);
            if ($normalized === null || $normalized === '') {
                // If Haiku formatting was unusable, fall back to Sonnet's original verified value.
                $normalized = $this->normalizeValue($field, $sonnetRow['value'] ?? null);
            }
            if ($normalized === null || $normalized === '') continue;

            $row = $sonnetRow;
            $row['value'] = is_scalar($normalized) ? (string)$normalized : $normalized;
            $results[] = $row;
        }

        return ['results'=>$results, 'not_found'=>[]];
    }

    private function callClaude(string $apiKey, array $payload, JobCandidate $candidate): array
    {
        $headers = [
            'x-api-key'=>$apiKey,
            'anthropic-version'=>'2023-06-01',
            'content-type'=>'application/json',
        ];

        $workspaceId = trim((string) config('services.claude.workspace_id', ''));
        if ($workspaceId !== '') {
            $headers['anthropic-workspace-id'] = $workspaceId;
        }

        $response = Http::withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->timeout(180)
            ->retry(2, 900, throw:false)
            ->post('https://api.anthropic.com/v1/messages', $payload);

        if (!$response->successful()) {
            Log::error('Claude Fetch All Data failed', [
                'candidate_id'=>$candidate->id,
                'status'=>$response->status(),
                'body'=>Str::limit($response->body(), 3000),
            ]);
            $message = (string)data_get($response->json(), 'error.message', '');
            throw new RuntimeException(
                'Claude request failed with HTTP '.$response->status().($message !== '' ? ': '.$message : '.')
            );
        }

        $body = $response->json();
        if (!is_array($body)) {
            throw new RuntimeException('Claude returned an invalid response.');
        }

        return $body;
    }

    private function structuredOutput(
        array $body,
        ?string $expectedTool = null,
        ?JobCandidate $candidate = null
    ): array {
        if ($expectedTool !== null) {
            foreach (($body['content'] ?? []) as $part) {
                if (($part['type'] ?? null) !== 'tool_use') continue;
                if (($part['name'] ?? null) !== $expectedTool) continue;

                $input = $part['input'] ?? null;
                if (!is_array($input)) continue;

                $input['results'] = is_array($input['results'] ?? null) ? $input['results'] : [];
                $input['not_found'] = is_array($input['not_found'] ?? null) ? $input['not_found'] : [];
                return $input;
            }
        }

        // Compatibility fallback only: parse text if an older/model response ignored
        // the structured tool instruction. Never trigger another Sonnet/web search here.
        $parts = [];
        foreach (($body['content'] ?? []) as $part) {
            if (($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        $text = trim(implode("\n", $parts));
        if ($text !== '') {
            $clean = preg_replace('/^\`\`\`(?:json)?\s*|\s*\`\`\`$/i', '', $text) ?? $text;
            $start = strpos($clean, '{');
            $end = strrpos($clean, '}');

            if ($start !== false && $end !== false && $end >= $start) {
                $clean = substr($clean, $start, $end - $start + 1);
                try {
                    $decoded = json_decode($clean, true, flags:JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $decoded['results'] = is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
                        $decoded['not_found'] = is_array($decoded['not_found'] ?? null) ? $decoded['not_found'] : [];
                        return $decoded;
                    }
                } catch (Throwable $e) {
                    // Log below with a short, secret-safe response excerpt.
                }
            }
        }

        Log::warning('Claude returned invalid structured data', [
            'candidate_id'=>$candidate?->id,
            'expected_tool'=>$expectedTool,
            'stop_reason'=>$body['stop_reason'] ?? null,
            'model'=>$body['model'] ?? null,
            'response_excerpt'=>Str::limit($text, 1200),
        ]);

        throw new RuntimeException('Claude returned invalid structured data.');
    }

    private function sourceUrls(array $body): array
    {
        $urls = [];
        $walk = function ($node) use (&$walk, &$urls) {
            if (!is_array($node)) return;
            foreach ($node as $key=>$value) {
                if ($key === 'url' && is_string($value) && $this->validUrl($value)) {
                    $urls[] = $this->normalizeUrl($value);
                } elseif (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($body['content'] ?? []);
        return array_values(array_unique($urls));
    }

    private function normalizeValue(string $field, mixed $value): mixed
    {
        if (!is_scalar($value)) return null;
        $v = trim((string)$value);
        if ($v === '') return null;

        if (in_array($field, self::INTEGER_FIELDS, true)) {
            $digits = preg_replace('/[^0-9]/', '', $v);
            return $digits === '' ? null : (int)$digits;
        }

        if (in_array($field, self::DATE_FIELDS, true)) {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        }

        return $v;
    }

    private function validUrl(string $url): bool
    {
        return $url !== ''
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && preg_match('#^https?://#i', $url);
    }

    private function normalizeUrl(string $url): string
    {
        return rtrim(Str::lower(trim($url)), '/');
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $value = trim((string)$value);
        return $value === '' ? null : Str::limit($value, 1000, '');
    }
}
