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
        $official = $this->officialEvidence($candidate, $data);
        $body = $this->callClaude(
            $apiKey,
            $this->payload($candidate, $data, $missing, $official),
            $candidate
        );

        $structured = $this->structuredOutput($body);
        $searchedUrls = $this->sourceUrls($body);
        $webSearchesUsed = (int) data_get($body, 'usage.server_tool_use.web_search_requests', 0);

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
                if ($official === null) continue;
                $sourceUrl = $official['url'];
                $provider = 'claude_official_evidence';
                $sourceTitle = $this->nullableString($row['source_title'] ?? null) ?: $official['title'];
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
            'source_mode'=>$official !== null ? 'official_evidence_first' : 'web_search_only',
            'web_searches_used'=>$webSearchesUsed,
            'requires_admin_approval'=>count($accepted) > 0,
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

    private function officialEvidence(JobCandidate $candidate, array $data): ?array
    {
        foreach ([
            ['url'=>(string)$candidate->notification_pdf_url,'kind'=>'official_notification_pdf','title'=>'Official notification / PDF'],
            ['url'=>(string)$candidate->official_source_url,'kind'=>'official_source','title'=>'Official recruitment source'],
        ] as $source) {
            $url = trim($source['url']);
            if (!$this->validUrl($url)) continue;

            try {
                $result = $this->httpFetcher->fetch($url);
                $isPdf = str_contains(strtolower((string)$result->contentType), 'application/pdf')
                    || str_ends_with(strtolower((string)(parse_url($result->url, PHP_URL_PATH) ?: '')), '.pdf');

                $text = $isPdf
                    ? (string)($this->pdfTextExtractor->extract($result->body)['text'] ?? '')
                    : $this->contentNormalizer->text($result->body);

                if (mb_strlen(trim($text)) >= 120) {
                    return [
                        'kind'=>$source['kind'],
                        'url'=>$result->url ?: $url,
                        'title'=>$source['title'],
                        'text'=>mb_substr(trim($text), 0, 60000),
                    ];
                }
            } catch (Throwable $e) {
                Log::warning('Official evidence read failed before Claude fetch', [
                    'candidate_id'=>$candidate->id,
                    'url'=>$url,
                    'error'=>$e->getMessage(),
                ]);
            }
        }

        $raw = trim((string)($data['raw_text'] ?? ''));
        $url = $this->validUrl((string)$candidate->notification_pdf_url)
            ? (string)$candidate->notification_pdf_url
            : (string)$candidate->official_source_url;

        if (mb_strlen($raw) >= 120 && $this->validUrl($url)) {
            return [
                'kind'=>'official_source_cached',
                'url'=>$url,
                'title'=>'Official recruitment evidence',
                'text'=>mb_substr($raw, 0, 60000),
            ];
        }

        return null;
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

    private function payload(JobCandidate $candidate, array $data, array $missing, ?array $official): array
    {
        $officialText = $official !== null
            ? "OFFICIAL EVIDENCE URL:\n".$official['url']."\n\nOFFICIAL EVIDENCE TEXT:\n".$official['text']
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
10. Return ONLY valid JSON with this shape:
{"results":[{"field":"...","value":"...","confidence":0.0,"source_kind":"official_evidence|web_search","source_url":"...","source_title":"...","source_page":null,"source_excerpt":"..."}],"not_found":["field_name"]}
TXT;

        return [
            'model'=>config('services.claude.model','claude-sonnet-5-5'),
            'max_tokens'=>7000,
            'system'=>$system,
            'messages'=>[[
                'role'=>'user',
                'content'=>"Job context:\n"
                    .json_encode($this->context($candidate,$data), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)
                    ."\n\nPENDING FIELDS TO FILL:\n".implode(', ', $missing)
                    ."\n\n".$officialText,
            ]],
            'tools'=>[[
                'type'=>'web_search_20250305',
                'name'=>'web_search',
                'max_uses'=>2,
                'user_location'=>[
                    'type'=>'approximate',
                    'country'=>'IN',
                    'timezone'=>'Asia/Kolkata',
                ],
            ]],
        ];
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

    private function structuredOutput(array $body): array
    {
        $parts = [];
        foreach (($body['content'] ?? []) as $part) {
            if (($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        $text = trim(implode("\n", $parts));
        if ($text === '') {
            throw new RuntimeException('Claude returned no structured data.');
        }

        $text = preg_replace('/^\`\`\`(?:json)?\s*|\s*\`\`\`$/i', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end >= $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        try {
            $decoded = json_decode($text, true, flags:JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException('Claude returned invalid structured data.', previous:$e);
        }

        if (!is_array($decoded)) $decoded = [];
        $decoded['results'] = is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
        $decoded['not_found'] = is_array($decoded['not_found'] ?? null) ? $decoded['not_found'] : [];
        return $decoded;
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
