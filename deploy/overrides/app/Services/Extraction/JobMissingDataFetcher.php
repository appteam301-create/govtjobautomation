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
            return ['fetched_count'=>0,'fetched_fields'=>[],'not_found'=>[],'source_mode'=>'none','web_searches_used'=>0];
        }

        // Strict cost/source order:
        // 1) notification PDF, 2) official source page, 3) Claude web search only if neither is readable.
        $official = $this->officialEvidence($candidate, $data);
        if ($official !== null) {
            $body = $this->callClaude($apiKey, $this->officialPayload($candidate,$data,$missing,$official), $candidate, 'official_evidence');
            $sourceMode = $official['kind'];
            $webSearchesUsed = 0;
            $searchedUrls = [];
        } else {
            $body = $this->callClaude($apiKey, $this->searchPayload($candidate,$data,$missing), $candidate, 'web_search');
            $sourceMode = 'claude_web_search';
            $webSearchesUsed = (int) data_get($body, 'usage.server_tool_use.web_search_requests', 0);
            $searchedUrls = $this->sourceUrls($body);
        }

        $structured = $this->structuredOutput($body);
        $references = is_array($data['field_sources'] ?? null) ? $data['field_sources'] : [];
        $pendingApproval = is_array($data['claude_pending_approval'] ?? null) ? $data['claude_pending_approval'] : [];
        $accepted = [];

        foreach (($structured['results'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $field = (string)($row['field'] ?? '');
            $confidence = (float)($row['confidence'] ?? 0);
            if (!in_array($field, $missing, true) || !$this->isMissing($data[$field] ?? null) || $confidence < 0.72) continue;

            $value = $this->normalizeValue($field, $row['value'] ?? null);
            if ($value === null || $value === '') continue;

            if ($official !== null) {
                $url = $official['url'];
                $title = $official['title'];
                $provider = 'claude_official_evidence';
            } else {
                $url = trim((string)($row['source_url'] ?? ''));
                if (!$this->validUrl($url)) continue;
                if ($searchedUrls !== [] && !in_array($this->normalizeUrl($url), $searchedUrls, true)) continue;
                $title = $this->nullableString($row['source_title'] ?? null) ?: 'Claude web source';
                $provider = 'claude_web_search';
            }

            $data[$field] = $value;
            $references[$field] = [
                'provider'=>$provider,
                'source_url'=>$url,
                'source_title'=>$title,
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
        $data['field_sources'] = $references;
        $data['claude_pending_approval'] = array_values(array_unique($pendingApproval));
        $data['pending_fields'] = array_values(array_filter(JobDetailsEnricher::FIELDS, fn($f) => $this->isMissing($data[$f] ?? null)));

        $candidate->extracted_data = $data;
        if (!empty($data['job_title'])) $candidate->job_title = $data['job_title'];
        if (!empty($data['organization'])) $candidate->organization = $data['organization'];
        if (array_key_exists('total_vacancies',$data)) $candidate->total_vacancies = $data['total_vacancies'];
        if (!empty($data['application_end_date'])) $candidate->application_last_date = $data['application_end_date'];
        if (!empty($data['apply_url'])) $candidate->application_url = $data['apply_url'];
        $candidate->save();

        $notFound = array_values(array_unique(array_filter(
            array_merge($structured['not_found'] ?? [], array_diff($missing,$accepted)),
            fn($f) => in_array($f,$missing,true)
        )));

        return [
            'fetched_count'=>count($accepted),
            'fetched_fields'=>$accepted,
            'not_found'=>$notFound,
            'source_mode'=>$sourceMode,
            'web_searches_used'=>$webSearchesUsed,
            'requires_admin_approval'=>count($accepted) > 0,
        ];
    }

    private function missingFields(array $data): array
    {
        $refs = is_array($data['field_sources'] ?? null) ? $data['field_sources'] : [];
        return array_values(array_filter(JobDetailsEnricher::FIELDS, fn($field) =>
            !in_array($field,self::SKIP_FIELDS,true)
            && $this->isMissing($data[$field] ?? null)
            && !$this->hasSource($refs[$field] ?? null)
        ));
    }

    private function hasSource(mixed $source): bool
    {
        return is_array($source) && $this->validUrl((string)($source['source_url'] ?? ''));
    }

    private function isMissing(mixed $value): bool
    {
        if ($value === null) return true;
        if (!is_string($value)) return false;
        $v = Str::of($value)->trim()->lower()->squish()->toString();
        return $v === '' || in_array($v,['pending','unknown','not known','not available','n/a','na','tbd','to be updated','-','--'],true);
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
                $isPdf = str_contains(strtolower((string)$result->contentType),'application/pdf')
                    || str_ends_with(strtolower((string)(parse_url($result->url,PHP_URL_PATH) ?: '')),'.pdf');
                $text = $isPdf
                    ? (string)($this->pdfTextExtractor->extract($result->body)['text'] ?? '')
                    : $this->contentNormalizer->text($result->body);
                if (mb_strlen(trim($text)) >= 120) {
                    return ['kind'=>$source['kind'],'url'=>$result->url ?: $url,'title'=>$source['title'],'text'=>mb_substr(trim($text),0,60000)];
                }
            } catch (Throwable $e) {
                Log::warning('Official evidence read failed before Claude fetch', ['candidate_id'=>$candidate->id,'url'=>$url,'error'=>$e->getMessage()]);
            }
        }

        $raw = trim((string)($data['raw_text'] ?? ''));
        $url = $this->validUrl((string)$candidate->notification_pdf_url)
            ? (string)$candidate->notification_pdf_url
            : (string)$candidate->official_source_url;
        if (mb_strlen($raw) >= 120 && $this->validUrl($url)) {
            return ['kind'=>'official_source_cached','url'=>$url,'title'=>'Official recruitment evidence','text'=>mb_substr($raw,0,60000)];
        }
        return null;
    }

    private function context(JobCandidate $candidate, array $data): array
    {
        $known=[];
        foreach (JobDetailsEnricher::FIELDS as $field) {
            if (array_key_exists($field,$data) && !$this->isMissing($data[$field])) {
                $known[$field] = is_string($data[$field]) ? Str::limit($data[$field],2500,'') : $data[$field];
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

    private function baseInstructions(bool $search): string
    {
        $sourceRule = $search
            ? 'Official evidence could not be read directly. You may use web search now. Prefer official notification/PDF and government sources.'
            : 'Use ONLY the official evidence text included in this request. Do not use web search, memory, assumptions, or general knowledge.';
        return <<<TXT
You extract Indian government recruitment facts for an admin review system.
{$sourceRule}
Fill ONLY the requested missing fields. Never overwrite or revise existing non-empty values.
Every returned value must be supported by the cited source. Never guess or use generic defaults.
Dates must be YYYY-MM-DD. Numeric fields must contain only the numeric value.
Return ONLY valid JSON: {"results":[...],"not_found":[...]}.
Each result must contain: field, value, confidence, source_url, source_title, source_page, source_excerpt.
TXT;
    }

    private function officialPayload(JobCandidate $candidate,array $data,array $missing,array $official): array
    {
        return [
            'model'=>config('services.claude.model','claude-sonnet-5-5'),
            'max_tokens'=>6000,
            'system'=>$this->baseInstructions(false),
            'messages'=>[['role'=>'user','content'=>"Job context:\n".json_encode($this->context($candidate,$data),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n\nMissing fields ONLY:\n".implode(', ',$missing)."\n\nOfficial URL:\n".$official['url']."\n\nOfficial evidence:\n".$official['text']]],
        ];
    }

    private function searchPayload(JobCandidate $candidate,array $data,array $missing): array
    {
        $system = $this->baseInstructions(true)."\nSearch efficiently: use ONE comprehensive search for all missing fields. Use a SECOND search only if important fields remain unresolved. Never search more than twice.";
        return [
            'model'=>config('services.claude.model','claude-sonnet-5-5'),
            'max_tokens'=>7000,
            'system'=>$system,
            'messages'=>[['role'=>'user','content'=>"Job context:\n".json_encode($this->context($candidate,$data),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n\nMissing fields ONLY:\n".implode(', ',$missing)]],
            'tools'=>[[
                'type'=>'web_search_20250305',
                'name'=>'web_search',
                'max_uses'=>2,
                'user_location'=>['type'=>'approximate','country'=>'IN','timezone'=>'Asia/Kolkata'],
            ]],
        ];
    }

    private function callClaude(string $apiKey,array $payload,JobCandidate $candidate,string $mode): array
    {
        $response = Http::withHeaders([
            'x-api-key'=>$apiKey,
            'anthropic-version'=>'2023-06-01',
            'content-type'=>'application/json',
        ])->acceptJson()->asJson()->timeout(180)->retry(2,900,throw:false)
          ->post('https://api.anthropic.com/v1/messages',$payload);

        if (!$response->successful()) {
            Log::error('Claude Fetch All Data failed',['candidate_id'=>$candidate->id,'mode'=>$mode,'status'=>$response->status(),'body'=>Str::limit($response->body(),3000)]);
            $msg=(string)data_get($response->json(),'error.message','');
            throw new RuntimeException('Claude request failed with HTTP '.$response->status().($msg!==''?': '.$msg:'.'));
        }
        $body=$response->json();
        if (!is_array($body)) throw new RuntimeException('Claude returned an invalid response.');
        return $body;
    }

    private function structuredOutput(array $body): array
    {
        $parts=[];
        foreach (($body['content'] ?? []) as $part) {
            if (($part['type'] ?? null)==='text' && is_string($part['text'] ?? null)) $parts[]=$part['text'];
        }
        $text=trim(implode("\n",$parts));
        if ($text==='') throw new RuntimeException('Claude returned no structured data.');
        $text=preg_replace('/^\`\`\`(?:json)?\s*|\s*\`\`\`$/i','',$text) ?? $text;
        $start=strpos($text,'{'); $end=strrpos($text,'}');
        if ($start!==false && $end!==false && $end>=$start) $text=substr($text,$start,$end-$start+1);
        try { $decoded=json_decode($text,true,flags:JSON_THROW_ON_ERROR); }
        catch (Throwable $e) { throw new RuntimeException('Claude returned invalid structured data.',previous:$e); }
        if (!is_array($decoded)) $decoded=[];
        $decoded['results']=is_array($decoded['results'] ?? null)?$decoded['results']:[];
        $decoded['not_found']=is_array($decoded['not_found'] ?? null)?$decoded['not_found']:[];
        return $decoded;
    }

    private function sourceUrls(array $body): array
    {
        $urls=[];
        $walk=function($node) use (&$walk,&$urls) {
            if (!is_array($node)) return;
            foreach ($node as $k=>$v) {
                if ($k==='url' && is_string($v) && $this->validUrl($v)) $urls[]=$this->normalizeUrl($v);
                elseif (is_array($v)) $walk($v);
            }
        };
        $walk($body['content'] ?? []);
        return array_values(array_unique($urls));
    }

    private function normalizeValue(string $field,mixed $value): mixed
    {
        if (!is_scalar($value)) return null;
        $v=trim((string)$value); if ($v==='') return null;
        if (in_array($field,self::INTEGER_FIELDS,true)) {
            $digits=preg_replace('/[^0-9]/','',$v); return $digits===''?null:(int)$digits;
        }
        if (in_array($field,self::DATE_FIELDS,true)) return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:null;
        return $v;
    }

    private function validUrl(string $url): bool
    {
        return $url!=='' && filter_var($url,FILTER_VALIDATE_URL)!==false && preg_match('#^https?://#i',$url);
    }

    private function normalizeUrl(string $url): string { return rtrim(Str::lower(trim($url)),'/'); }
    private function nullableString(mixed $value): ?string
    {
        if (!is_scalar($value)) return null;
        $value=trim((string)$value);
        return $value===''?null:Str::limit($value,1000,'');
    }
}
