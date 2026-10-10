<?php
namespace App\Services\Extraction;

use App\Models\DiscoveredDocument;
use App\Models\GovernmentSource;
use App\Models\JobCandidate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PreviousQuestionPaperFinder
{
    public function refreshForCandidate(JobCandidate $candidate): void
    {
        $source = $candidate->government_source_id
            ? GovernmentSource::find($candidate->government_source_id)
            : null;
        $document = $candidate->discovered_document_id
            ? DiscoveredDocument::find($candidate->discovered_document_id)
            : null;

        if (!$source || !$document) return;

        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $this->findAndStore($candidate, $source, $document, $metadata);
    }

    public function findAndStore(
        JobCandidate $candidate,
        GovernmentSource $source,
        DiscoveredDocument $document,
        array $metadata = []
    ): void {
        $data = is_array($candidate->extracted_data) ? $candidate->extracted_data : [];
        $years = $this->targetYears();

        $existing = is_array($data['previous_question_papers'] ?? null)
            ? $data['previous_question_papers']
            : [];

        $papers = $this->dedupePapers($existing);
        $foundYears = array_values(array_unique(array_map(
            fn($paper) => (int)($paper['year'] ?? 0),
            $papers
        )));

        // First stage: coded scan of known official pages. No AI cost.
        $officialPages = array_values(array_unique(array_filter([
            (string)($metadata['discovered_from'] ?? ''),
            (string)$source->recruitment_url,
            (string)$candidate->official_source_url,
        ], fn($url) => $this->validUrl(trim($url)))));

        foreach ($officialPages as $pageUrl) {
            foreach ($this->scanOfficialPage($pageUrl, $candidate, $years) as $paper) {
                $papers[] = $paper;
            }
        }

        $papers = $this->dedupePapers($papers);
        $foundYears = array_values(array_unique(array_map(
            fn($paper) => (int)($paper['year'] ?? 0),
            $papers
        )));
        $missingYears = array_values(array_diff($years, $foundYears));

        $searchesUsed = 0;

        // Second stage: at most ONE combined Sonnet web search for all remaining years.
        // Never search one year at a time.
        if ($missingYears !== [] && !$this->alreadySearchedYears($data, $missingYears)) {
            $searchResult = $this->searchWithSonnet($candidate, $source, $officialPages, $missingYears);
            $searchesUsed = $searchResult['web_searches_used'];

            foreach ($searchResult['papers'] as $paper) {
                $papers[] = $paper;
            }

            $data['question_paper_web_search_attempted_years'] = array_values(array_unique(array_merge(
                is_array($data['question_paper_web_search_attempted_years'] ?? null)
                    ? $data['question_paper_web_search_attempted_years']
                    : [],
                $missingYears
            )));
        }

        $papers = array_values(array_filter(
            $this->dedupePapers($papers),
            fn($paper) => in_array((int)($paper['year'] ?? 0), $years, true)
        ));

        usort($papers, fn($a, $b) => ((int)($b['year'] ?? 0)) <=> ((int)($a['year'] ?? 0)));

        $data['previous_question_papers'] = $papers;
        $data['question_paper_target_years'] = $years;
        $data['question_paper_last_checked_at'] = now()->toIso8601String();
        $data['question_paper_web_searches_used_last_run'] = $searchesUsed;

        $candidate->extracted_data = $data;
        $candidate->save();
    }

    private function targetYears(): array
    {
        $year = (int)now(config('app.timezone', 'Asia/Kolkata'))->format('Y');
        return [$year - 1, $year - 2, $year - 3];
    }

    private function alreadySearchedYears(array $data, array $missingYears): bool
    {
        $attempted = is_array($data['question_paper_web_search_attempted_years'] ?? null)
            ? array_map('intval', $data['question_paper_web_search_attempted_years'])
            : [];

        foreach ($missingYears as $year) {
            if (!in_array((int)$year, $attempted, true)) return false;
        }

        return $missingYears !== [];
    }

    private function scanOfficialPage(string $pageUrl, JobCandidate $candidate, array $years): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => config('govjobs.crawler.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
                'Accept-Language' => 'en-IN,en;q=0.9',
            ])->timeout(10)->get($pageUrl);

            if (!$response->successful()) return [];

            $contentType = strtolower((string)$response->header('Content-Type'));
            if ($contentType !== '' && !str_contains($contentType, 'html')) return [];

            $html = $response->body();
            $papers = [];

            if (!preg_match_all('#<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER)) {
                return [];
            }

            $identityTokens = $this->identityTokens(
                (string)$candidate->job_title.' '.(string)$candidate->organization
            );

            foreach ($matches as $match) {
                $href = html_entity_decode(trim((string)$match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string)$match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $absolute = $this->absoluteUrl($href, $pageUrl);
                if (!$absolute || !$this->validUrl($absolute)) continue;

                $haystack = mb_strtolower($label.' '.$absolute);
                if (!preg_match('/\b(question\s*paper|previous\s*year|old\s*paper|exam\s*paper|written\s*test\s*paper|model\s*question)\b/u', $haystack)) {
                    continue;
                }

                $year = $this->extractYear($haystack, $years);
                if (!$year) continue;
                if (!$this->looksRelatedToJob($haystack, $identityTokens)) continue;
                if (!$this->isPdfUrl($absolute)) continue;

                $papers[] = [
                    'year'=>$year,
                    'title'=>$label !== '' ? Str::limit($label, 220, '') : ((string)$candidate->job_title.' '.$year.' Question Paper'),
                    'pdf_url'=>$absolute,
                    'source_url'=>$pageUrl,
                    'source_title'=>'Official source archive/page',
                    'source_type'=>'official_archive',
                    'is_official'=>true,
                    'confidence'=>0.95,
                    'found_at'=>now()->toIso8601String(),
                ];
            }

            return $papers;
        } catch (Throwable $e) {
            Log::warning('Question paper official scan failed', [
                'candidate_id'=>$candidate->id,
                'page_url'=>$pageUrl,
                'error'=>$e->getMessage(),
            ]);
            return [];
        }
    }

    private function searchWithSonnet(
        JobCandidate $candidate,
        GovernmentSource $source,
        array $officialPages,
        array $missingYears
    ): array {
        $apiKey = trim((string)config('services.claude.api_key', ''));
        if ($apiKey === '') {
            return ['papers'=>[], 'web_searches_used'=>0];
        }

        $system = <<<'TXT'
You find previous-year question papers for Indian government recruitment jobs.

STRICT RULES:
1. Search only for the exact recruitment post/exam represented by the supplied job title and organization.
2. Search for ALL requested missing years together in ONE web search. Never search each year separately.
3. Prefer official government, commission, recruitment-board, department, university, district, or exam-authority sources.
4. A result must be an actual question-paper PDF for the same post/exam, not a syllabus, answer key, notification, model unrelated test, coaching article, or generic sample.
5. If an exact paper cannot be verified for a year, omit it. Never guess.
6. Return at most one best paper per requested year.
7. Provide the direct PDF URL plus the page/source URL that supports it.
8. Use the submit_question_papers tool exactly once after research.
TXT;

        $payload = [
            'model'=>config('services.claude.research_model', 'claude-sonnet-5-5'),
            'max_tokens'=>3500,
            'system'=>$system,
            'messages'=>[[
                'role'=>'user',
                'content'=>json_encode([
                    'job_title'=>$candidate->job_title,
                    'organization'=>$candidate->organization,
                    'official_source_url'=>$candidate->official_source_url,
                    'notification_pdf_url'=>$candidate->notification_pdf_url,
                    'known_official_pages'=>$officialPages,
                    'official_domain'=>parse_url((string)$source->recruitment_url, PHP_URL_HOST),
                    'missing_years'=>$missingYears,
                ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
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
                [
                    'name'=>'submit_question_papers',
                    'description'=>'Submit only verified previous-year question-paper PDFs for the requested years.',
                    'input_schema'=>[
                        'type'=>'object',
                        'additionalProperties'=>false,
                        'properties'=>[
                            'papers'=>[
                                'type'=>'array',
                                'items'=>[
                                    'type'=>'object',
                                    'additionalProperties'=>false,
                                    'properties'=>[
                                        'year'=>['type'=>'integer'],
                                        'title'=>['type'=>'string'],
                                        'pdf_url'=>['type'=>'string'],
                                        'source_url'=>['type'=>'string'],
                                        'source_title'=>['type'=>'string'],
                                        'confidence'=>['type'=>'number','minimum'=>0,'maximum'=>1],
                                    ],
                                    'required'=>['year','title','pdf_url','source_url','source_title','confidence'],
                                ],
                            ],
                        ],
                        'required'=>['papers'],
                    ],
                ],
            ],
        ];

        $headers = [
            'x-api-key'=>$apiKey,
            'anthropic-version'=>'2023-06-01',
            'content-type'=>'application/json',
        ];

        $workspaceId = trim((string)config('services.claude.workspace_id', ''));
        if ($workspaceId !== '') $headers['anthropic-workspace-id'] = $workspaceId;

        try {
            $response = Http::withHeaders($headers)
                ->acceptJson()
                ->asJson()
                ->timeout(180)
                ->retry(1, 900, throw:false)
                ->post('https://api.anthropic.com/v1/messages', $payload);

            if (!$response->successful()) {
                Log::warning('Question paper Sonnet search failed', [
                    'candidate_id'=>$candidate->id,
                    'status'=>$response->status(),
                    'body'=>Str::limit($response->body(), 1500),
                ]);
                return ['papers'=>[], 'web_searches_used'=>0];
            }

            $body = $response->json();
            if (!is_array($body)) return ['papers'=>[], 'web_searches_used'=>0];

            $searchedUrls = $this->sourceUrls($body);
            $papers = [];

            foreach (($body['content'] ?? []) as $part) {
                if (($part['type'] ?? null) !== 'tool_use' || ($part['name'] ?? null) !== 'submit_question_papers') continue;
                $input = $part['input'] ?? null;
                if (!is_array($input)) continue;

                foreach (($input['papers'] ?? []) as $row) {
                    if (!is_array($row)) continue;

                    $year = (int)($row['year'] ?? 0);
                    $pdfUrl = trim((string)($row['pdf_url'] ?? ''));
                    $sourceUrl = trim((string)($row['source_url'] ?? ''));
                    $confidence = (float)($row['confidence'] ?? 0);

                    if (!in_array($year, $missingYears, true)) continue;
                    if ($confidence < 0.82) continue;
                    if (!$this->validUrl($pdfUrl) || !$this->validUrl($sourceUrl)) continue;
                    if (!$this->isPdfUrl($pdfUrl)) continue;

                    $normalizedSource = $this->normalizeUrl($sourceUrl);
                    if ($searchedUrls !== [] && !in_array($normalizedSource, $searchedUrls, true)) continue;

                    $papers[] = [
                        'year'=>$year,
                        'title'=>Str::limit(trim((string)($row['title'] ?? 'Previous Year Question Paper')), 220, ''),
                        'pdf_url'=>$pdfUrl,
                        'source_url'=>$sourceUrl,
                        'source_title'=>Str::limit(trim((string)($row['source_title'] ?? 'Web search source')), 220, ''),
                        'source_type'=>'sonnet_web_search',
                        'is_official'=>$this->sameOfficialDomain(
                            parse_url($sourceUrl, PHP_URL_HOST),
                            parse_url((string)$source->recruitment_url, PHP_URL_HOST)
                        ),
                        'confidence'=>$confidence,
                        'found_at'=>now()->toIso8601String(),
                    ];
                }
            }

            return [
                'papers'=>$this->dedupePapers($papers),
                'web_searches_used'=>(int)data_get($body, 'usage.server_tool_use.web_search_requests', 0),
            ];
        } catch (Throwable $e) {
            Log::warning('Question paper Sonnet search exception', [
                'candidate_id'=>$candidate->id,
                'error'=>$e->getMessage(),
            ]);
            return ['papers'=>[], 'web_searches_used'=>0];
        }
    }

    private function identityTokens(string $value): array
    {
        $value = mb_strtolower(preg_replace('/[^\pL\pN]+/u', ' ', $value));
        $stop = [
            'government','govt','department','district','office','recruitment','notification',
            'applications','application','invited','post','posts','for','the','and','of','in',
            'on','basis','contract','contractual','india','state'
        ];

        $tokens = [];
        foreach (preg_split('/\s+/u', trim($value)) ?: [] as $token) {
            if (mb_strlen($token) < 4 || in_array($token, $stop, true)) continue;
            $tokens[$token] = true;
        }

        return array_keys($tokens);
    }

    private function looksRelatedToJob(string $haystack, array $tokens): bool
    {
        if ($tokens === []) return false;
        $matches = 0;
        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) $matches++;
        }
        return $matches >= 1;
    }

    private function extractYear(string $text, array $years): ?int
    {
        foreach ($years as $year) {
            if (preg_match('/\b'.preg_quote((string)$year, '/').'\b/', $text)) return (int)$year;
        }
        return null;
    }

    private function dedupePapers(array $papers): array
    {
        $byKey = [];
        foreach ($papers as $paper) {
            if (!is_array($paper)) continue;
            $year = (int)($paper['year'] ?? 0);
            $url = trim((string)($paper['pdf_url'] ?? ''));
            if ($year <= 0 || !$this->validUrl($url)) continue;

            $key = $year.'|'.$this->normalizeUrl($url);
            if (!isset($byKey[$key]) || (float)($paper['confidence'] ?? 0) > (float)($byKey[$key]['confidence'] ?? 0)) {
                $byKey[$key] = $paper;
            }
        }
        return array_values($byKey);
    }

    private function isPdfUrl(string $url): bool
    {
        if (!$this->validUrl($url)) return false;
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
        if (str_ends_with($path, '.pdf')) return true;

        try {
            $response = Http::withHeaders([
                'User-Agent'=>config('govjobs.crawler.user_agent'),
            ])->timeout(6)->head($url);

            return $response->successful()
                && str_contains(strtolower((string)$response->header('Content-Type')), 'application/pdf');
        } catch (Throwable $e) {
            return false;
        }
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

    private function sameOfficialDomain(?string $a, ?string $b): bool
    {
        $a = preg_replace('/^www\./i', '', strtolower((string)$a));
        $b = preg_replace('/^www\./i', '', strtolower((string)$b));
        if ($a === '' || $b === '') return false;
        return $a === $b || str_ends_with($a, '.'.$b) || str_ends_with($b, '.'.$a);
    }

    private function absoluteUrl(string $url, string $baseUrl): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'javascript:')) return null;
        if (filter_var($url, FILTER_VALIDATE_URL)) return $url;

        $scheme = (string)(parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https');
        $host = (string)parse_url($baseUrl, PHP_URL_HOST);
        if ($host === '') return null;

        if (str_starts_with($url, '//')) return $scheme.':'.$url;
        if (str_starts_with($url, '/')) return $scheme.'://'.$host.$url;

        $path = (string)(parse_url($baseUrl, PHP_URL_PATH) ?: '/');
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
        return $scheme.'://'.$host.($dir !== '' ? $dir : '').'/'.$url;
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
}
