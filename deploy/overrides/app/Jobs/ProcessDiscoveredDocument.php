<?php
namespace App\Jobs;

use App\Models\DiscoveredDocument;
use App\Models\GovernmentSource;
use App\Models\JobCandidate;
use App\Services\Extraction\JobDetailsEnricher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class ProcessDiscoveredDocument implements ShouldQueue
{
    use Queueable;
    public int $tries=1;
    public int $timeout=120;

    public function __construct(public int $documentId){}

    public function handle(JobDetailsEnricher $enricher): void
    {
        $doc=DiscoveredDocument::findOrFail($this->documentId);
        $source=GovernmentSource::find($doc->government_source_id);
        if(!$source) return;

        $title=trim(preg_replace('/\s+/u',' ',(string)$doc->title));
        $url=(string)$doc->url;

        if(!$this->looksLikeRealJob($title)) return;

        $raw=$this->extractText($url,(string)($doc->document_type??'html'),$title);
        if($raw==='') $raw=$title;
        $raw=mb_substr($raw,0,120000);

        // Result/DV/answer-key/update pages must never become new job rows.
        $head=mb_strtolower(mb_substr($title.' '.$raw,0,2500));
        if(preg_match('/\b(final result|provisional result|document verification|admit card|hall ticket|answer key|merit list|shortlist|shortlisted|eligibility list|interview schedule|exam schedule|examination schedule|objection|response sheet|cut[ -]?off|appointment order)\b/u',$head)) return;

        $jobTitle=$this->cleanTitle($title);
        if(!$this->looksLikeRealJob($jobTitle)) return;

        $vacancies=$this->intMatch($raw,'/(?:total\s+(?:number\s+of\s+)?vacanc(?:y|ies)|no\.?\s*of\s*posts?|vacanc(?:y|ies))[\s:\-]*(\d{1,6})/i');
        $lastDate=$this->dateNear($raw,['last date','closing date','application end date','last date for application']);
        $applyUrl=$this->urlNear($raw,['apply online','apply here','application link']);

        $model=new JobCandidate();
        $columns=Schema::getColumnListing($model->getTable());
        $payload=[
            'government_source_id'=>$source->id,
            'discovered_document_id'=>$doc->id,
            'job_title'=>$jobTitle ?: 'Recruitment Notification',
            'organization'=>$source->organization,
            'total_vacancies'=>$vacancies,
            'application_last_date'=>$lastDate,
            'application_url'=>$applyUrl,
            'notification_pdf_url'=>($doc->document_type??'')==='pdf'?$url:null,
            'official_source_url'=>$url,
            'confidence_score'=>$this->confidence($jobTitle,$raw,$vacancies,$lastDate),
            'status'=>'needs_review',
            'extracted_data'=>[
                'raw_text'=>$raw,
                'job_title'=>$jobTitle,
                'organization'=>$source->organization,
                'total_vacancies'=>$vacancies,
                'application_end_date'=>$lastDate,
                'apply_url'=>$applyUrl,
                'official_notification_url'=>$url,
                'source_name'=>$source->name,
                'source_url'=>$source->recruitment_url,
            ],
        ];

        $payload=array_intersect_key($payload,array_flip($columns));

        $existing=null;
        if(in_array('official_source_url',$columns,true)){
            $existing=JobCandidate::query()->where('official_source_url',$url)->first();
        }
        if(!$existing && in_array('discovered_document_id',$columns,true)){
            $existing=JobCandidate::query()->where('discovered_document_id',$doc->id)->first();
        }

        $candidate=$existing?:new JobCandidate();
        $candidate->forceFill($payload);
        $candidate->save();

        $enricher->enrich($candidate);
    }

    private function looksLikeRealJob(string $title): bool
    {
        $t=trim(mb_strtolower(preg_replace('/\s+/u',' ',$title)));
        if($t==='' || mb_strlen($t)<8 || mb_strlen($t)>420) return false;

        $generic=[
            'vacancy','vacancies','all vacancies','recruitment','recruitments','jobs','career','careers',
            'application form','apply online','eligibility list','faculty','project','english','hindi',
            'know more about vacancies','vision document','notice','notification','advertisement',
            'रिक्तियां','भर्ती','देखें'
        ];
        if(in_array($t,$generic,true)) return false;

        if(preg_match('/^(extension of last date|corrigendum|addendum|amendment|revised notice|notice regarding|important notice|expression of interest)\b/u',$t)) return false;
        if(preg_match('/\b(final result|provisional result|document verification|admit card|hall ticket|answer key|merit list|shortlist|shortlisted|eligibility list|interview schedule|exam schedule|examination schedule|objection|response sheet|cut[ -]?off|appointment order|tender|procurement|auction)\b/u',$t)) return false;

        $positive='/\\b(recruit(?:ment|ing)?|vacanc(?:y|ies)|applications? invited|walk[ -]?in|apprentice(?:ship)?|engagement of|post(?:s)? of|hiring|agniveer|constable|sub[ -]?inspector|inspector|senior resident|junior resident|medical consultant|bank.?s medical consultant|assistant professor|associate professor|professor|director|registrar|librarian|accountant|scientist|research (?:associate|fellow|scientist)|project (?:staff|associate|assistant|scientist|technical|officer)|technologist|technical officer|staff nurse|engineer|officer|assistant|clerk|manager|executive|technician|stenographer|trainee|fellowship|tutor|demonstrator|driver|attendant|tradesman|multi[ -]?tasking staff|mts|data entry operator|deo)\\b/u';
        return (bool)preg_match($positive,$t);
    }

    private function extractText(string $url,string $type,string $fallback): string
    {
        try{
            $response=Http::withHeaders([
                'User-Agent'=>config('govjobs.crawler.user_agent'),
                'Accept'=>'text/html,application/pdf,*/*;q=0.8',
                'Accept-Language'=>'en-IN,en;q=0.9'
            ])->timeout(18)->retry(1,300,throw:false)->get($url);
            if(!$response->successful()) return $fallback;

            $contentType=strtolower((string)$response->header('Content-Type'));
            if($type==='pdf' || str_contains($contentType,'pdf')){
                $tmp=tempnam(sys_get_temp_dir(),'govjob_');
                file_put_contents($tmp,$response->body());
                $out=$tmp.'.txt';
                shell_exec('timeout 25s pdftotext -layout '.escapeshellarg($tmp).' '.escapeshellarg($out).' 2>/dev/null');
                $text=is_file($out)?(string)file_get_contents($out):'';
                @unlink($tmp); @unlink($out);
                return trim($text)?:$fallback;
            }

            $html=$response->body();
            $html=preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is',' ',$html);
            $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
            return trim(preg_replace('/[ \t]+/u',' ',preg_replace('/\R{3,}/u',"\n\n",$text)));
        }catch(\Throwable $e){
            return $fallback;
        }
    }

    private function cleanTitle(string $title): string
    {
        $title=trim(preg_replace('/\s+/u',' ',$title));
        $title=preg_replace('/^(new\s+)?(notification|advertisement|advert|advt\.?)[\s:\-]*/i','',$title);
        return mb_substr(trim($title),0,500);
    }

    private function intMatch(string $text,string $pattern): ?int
    {
        return preg_match($pattern,$text,$m)?(int)str_replace(',','',$m[1]):null;
    }

    private function dateNear(string $raw,array $labels): ?string
    {
        foreach($labels as $label){
            $p='/'.preg_quote($label,'/').'[^\n\d]{0,50}(\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4}|\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4}|[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4})/i';
            if(preg_match($p,$raw,$m)){
                try{return \Carbon\Carbon::parse($m[1])->format('Y-m-d');}catch(\Throwable $e){}
            }
        }
        return null;
    }

    private function urlNear(string $raw,array $labels): ?string
    {
        foreach($labels as $label){
            $p='/'.preg_quote($label,'/').'[^\n]{0,160}?(https?:\/\/[^\s<>"\']+)/i';
            if(preg_match($p,$raw,$m)) return rtrim($m[1],').,;');
        }
        return null;
    }

    private function confidence(string $title,string $raw,?int $vacancies,?string $lastDate): int
    {
        $score=35;
        if($title!=='') $score+=20;
        if(mb_strlen($raw)>400) $score+=15;
        if($vacancies!==null) $score+=10;
        if($lastDate!==null) $score+=10;
        if(preg_match('/qualification|eligibility|pay scale|salary|selection process/i',$raw)) $score+=10;
        return min(100,$score);
    }
}
