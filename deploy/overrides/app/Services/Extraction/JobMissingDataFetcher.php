<?php
namespace App\Services\Extraction;

use App\Models\JobCandidate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class JobMissingDataFetcher
{
    private const SKIP_FIELDS = [
        'featured_job','urgent_hiring','seo_title','seo_description','seo_keywords',
    ];

    private const INTEGER_FIELDS = [
        'total_vacancies','age_minimum','age_maximum','salary_minimum','salary_maximum','application_fee',
    ];

    private const DATE_FIELDS = [
        'application_start_date','application_end_date','exam_date','admit_card_date',
    ];

    public function fetch(JobCandidate $candidate): array
    {
        $apiKey = (string) config('services.openai.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured on the server.');
        }

        $data = is_array($candidate->extracted_data) ? $candidate->extracted_data : [];
        $missing = $this->missingFields($data);

        if ($missing === []) {
            return ['fetched_count'=>0,'fetched_fields'=>[],'not_found'=>[]];
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(150)
            ->retry(2, 900, throw: false)
            ->post('https://api.openai.com/v1/responses', $this->payload($candidate, $data, $missing));

        if (!$response->successful()) {
            Log::error('OpenAI Fetch All Data failed', [
                'candidate_id'=>$candidate->id,
                'status'=>$response->status(),
                'body'=>Str::limit($response->body(), 2500),
            ]);
            throw new RuntimeException('OpenAI request failed with HTTP '.$response->status().'.');
        }

        $body = $response->json();
        $structured = $this->structuredOutput($body);
        $searchedUrls = $this->sourceUrls($body);
        $accepted = [];
        $references = is_array($data['field_sources'] ?? null) ? $data['field_sources'] : [];

        foreach (($structured['results'] ?? []) as $row) {
            $field = (string)($row['field'] ?? '');
            $value = $row['value'] ?? null;
            $url = trim((string)($row['source_url'] ?? ''));
            $confidence = (float)($row['confidence'] ?? 0);

            if (!in_array($field, $missing, true) || !$this->isMissing($data[$field] ?? null)) {
                continue;
            }
            if ($confidence < 0.72 || !$this->validHttpUrl($url)) {
                continue;
            }
            if ($searchedUrls !== [] && !in_array($this->normalizeUrl($url), $searchedUrls, true)) {
                continue;
            }

            $normalized = $this->normalizeValue($field, $value);
            if ($normalized === null || $normalized === '') {
                continue;
            }

            $data[$field] = $normalized;
            $references[$field] = [
                'provider'=>'openai_web_search',
                'source_url'=>$url,
                'source_title'=>$this->nullableString($row['source_title'] ?? null),
                'source_page'=>$this->nullableString($row['source_page'] ?? null),
                'reference'=>$this->nullableString($row['source_excerpt'] ?? null),
                'fetched_at'=>now()->toIso8601String(),
            ];
            $accepted[] = $field;
        }

        $data['field_sources'] = $references;
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

        $notFound = array_values(array_unique(array_filter(
            array_merge($structured['not_found'] ?? [], array_diff($missing, $accepted)),
            fn($field) => in_array($field, $missing, true)
        )));

        return [
            'fetched_count'=>count($accepted),
            'fetched_fields'=>$accepted,
            'not_found'=>$notFound,
        ];
    }

    private function missingFields(array $data): array
    {
        return array_values(array_filter(
            JobDetailsEnricher::FIELDS,
            fn($field) => !in_array($field, self::SKIP_FIELDS, true) && $this->isMissing($data[$field] ?? null)
        ));
    }

    private function isMissing(mixed $value): bool
    {
        if ($value === null) return true;
        if (is_string($value)) {
            $v = Str::of($value)->trim()->lower()->squish()->toString();
            return $v === '' || in_array($v, ['pending','unknown','not known','not available','n/a','na','tbd','to be updated','-','--'], true);
        }
        return false;
    }

    private function payload(JobCandidate $candidate, array $data, array $missing): array
    {
        $current = [];
        foreach (JobDetailsEnricher::FIELDS as $field) {
            if (array_key_exists($field, $data) && !$this->isMissing($data[$field])) {
                $current[$field] = is_string($data[$field]) ? Str::limit($data[$field], 2500, '') : $data[$field];
            }
        }

        $context = [
            'candidate_id'=>$candidate->id,
            'job_title'=>$candidate->job_title,
            'organization'=>$candidate->organization,
            'official_source_url'=>$candidate->official_source_url,
            'notification_pdf_url'=>$candidate->notification_pdf_url,
            'application_url'=>$candidate->application_url,
            'application_last_date'=>optional($candidate->application_last_date)->format('Y-m-d'),
            'current_known_fields'=>$current,
        ];

        $instructions = <<<'TXT'
You research Indian government recruitment records for an admin review system.
Use web search. Fill ONLY the requested missing fields. Never overwrite or propose changes to existing values.
Prefer evidence in this order: official recruitment notification/PDF, official recruiting authority website, other government website, then reputable secondary source only when official evidence is unavailable.
For every returned field, provide a source URL that supports that exact value. If the source is a PDF and the page is known, include the page.
Do not invent, infer from convention, or use generic defaults. If reliable evidence cannot be found, put the field in not_found and do not return a result for it.
Keep source_excerpt as a short paraphrase of the supporting evidence.
Dates must be YYYY-MM-DD. Numeric fields must contain only the numeric value in the value string.
TXT;

        return [
            'model'=>config('services.openai.model','gpt-6-luna'),
            'instructions'=>$instructions,
            'input'=>"Current job context:\n".json_encode($context, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n\nMissing fields:\n".implode(', ', $missing),
            'tools'=>[[
                'type'=>'web_search',
                'search_context_size'=>'medium',
                'user_location'=>['type'=>'approximate','country'=>'IN'],
            ]],
            'include'=>['web_search_call.action.sources'],
            'text'=>['format'=>[
                'type'=>'json_schema',
                'name'=>'job_missing_fields',
                'strict'=>true,
                'schema'=>[
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
                                    'source_url'=>['type'=>'string'],
                                    'source_title'=>['type'=>['string','null']],
                                    'source_page'=>['type'=>['string','null']],
                                    'source_excerpt'=>['type'=>['string','null']],
                                ],
                                'required'=>['field','value','confidence','source_url','source_title','source_page','source_excerpt'],
                            ],
                        ],
                        'not_found'=>['type'=>'array','items'=>['type'=>'string']],
                    ],
                    'required'=>['results','not_found'],
                ],
            ]],
        ];
    }

    private function structuredOutput(array $body): array
    {
        $text = $body['output_text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            foreach (($body['output'] ?? []) as $item) {
                if (($item['type'] ?? null) !== 'message') continue;
                foreach (($item['content'] ?? []) as $part) {
                    if (($part['type'] ?? null) === 'output_text' && isset($part['text'])) {
                        $text = $part['text'];
                        break 2;
                    }
                }
            }
        }
        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException('OpenAI returned no structured data.');
        }
        try {
            $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException('OpenAI returned invalid structured data.', previous:$e);
        }
        return is_array($decoded) ? $decoded : ['results'=>[],'not_found'=>[]];
    }

    private function sourceUrls(array $body): array
    {
        $urls = [];
        $walk = function ($node) use (&$walk, &$urls) {
            if (!is_array($node)) return;
            foreach ($node as $key=>$value) {
                if ($key === 'url' && is_string($value) && $this->validHttpUrl($value)) {
                    $urls[] = $this->normalizeUrl($value);
                } elseif (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($body['output'] ?? []);
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

    private function validHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $url);
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
