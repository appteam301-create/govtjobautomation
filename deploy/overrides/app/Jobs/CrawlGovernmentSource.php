<?php
namespace App\Jobs;

use App\Enums\CrawlerMode;
use App\Models\CrawlRun;
use App\Models\CrawlerHealth;
use App\Models\DiscoveredDocument;
use App\Models\GovernmentSource;
use App\Services\Crawling\BrowserRenderer;
use App\Services\Crawling\ChangeDetector;
use App\Services\Crawling\ContentNormalizer;
use App\Services\Crawling\HtmlDiscovery;
use App\Services\Crawling\HttpFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CrawlGovernmentSource implements ShouldQueue
{
    use Queueable;
    public int $tries=1;
    public int $timeout=150;

    public function __construct(public int $sourceId){}

    public function handle(
        HttpFetcher $fetcher,
        HtmlDiscovery $discovery,
        ContentNormalizer $norm,
        ChangeDetector $changes,
        BrowserRenderer $browser
    ): void {
        $source=GovernmentSource::findOrFail($this->sourceId);
        $run=CrawlRun::create(['government_source_id'=>$source->id,'started_at'=>now(),'status'=>'running']);

        try{
            $settings=$source->settings??[];
            $mode=$source->crawler_mode;
            $forceBrowser=(bool)($settings['force_browser']??false);
            $baseUrl=$source->recruitment_url;
            $httpStatus=null;
            $items=[];
            $httpError=null;
            $browserError=null;

            // Always try normal HTTP first. Even JS portals often expose useful links in server HTML.
            try{
                $result=$fetcher->fetch($source->recruitment_url);
                $baseUrl=$result->url;
                $httpStatus=$result->status;
                $items=$discovery->discover($result->body,$baseUrl);
            }catch(\Throwable $e){
                $httpError=$e;
            }

            $needBrowser=$httpError !== null || $forceBrowser || $mode===CrawlerMode::Js || ($mode===CrawlerMode::Auto && count($items)<8);
            if($needBrowser){
                try{
                    $rendered=$browser->render($source->recruitment_url);
                    $renderedItems=$discovery->discover($rendered,$source->recruitment_url);
                    if(count($renderedItems)>count($items)) $items=$renderedItems;
                    $httpStatus=$httpStatus??200;
                }catch(\Throwable $e){
                    $browserError=$e;
                }
            }

            // A browser timeout is not a source failure if HTTP still returned a usable page.
            if(!$items && $httpError && $browserError){
                throw new \RuntimeException('HTTP fetch failed: '.$httpError->getMessage().' | Browser: '.$browserError->getMessage());
            }
            if(!$items && $httpError && !$browserError){
                throw $httpError;
            }

            $listing=(bool)($settings['listing_is_recruitment']??false);
            $max=max(10,min(100,(int)($settings['max_items']??60)));
            $positive='/\\b(recruit(?:ment|ing)?|vacanc(?:y|ies)|applications? invited|walk[ -]?in|apprentice(?:ship)?|engagement|post(?:s)?\\s+of|hiring|agniveer|constable|sub[ -]?inspector|inspector|resident|consultant|director|registrar|librarian|accountant|professor|scientist|research (?:associate|fellow|scientist)|project (?:staff|associate|assistant|scientist|technical|officer)|technologist|technical officer|staff nurse|engineer|officer|assistant|clerk|manager|executive|technician|stenographer|trainee|fellowship|tutor|demonstrator|driver|attendant|tradesman|multi[ -]?tasking staff|mts|data entry operator|deo)\\b/u';
            $bad='/\\b(final result|provisional result|document verification|admit card|answer key|merit list|shortlist|shortlisted|eligibility list|tender|procurement|auction|objection|cut[ -]?off|interview schedule|exam schedule|expression of interest|closed|archived|cancelled|cancellation)\\b/u';

            $scored=[];
            foreach($items as $item){
                $title=mb_strtolower(trim((string)($item['title']??'')));
                if($title==='' || mb_strlen($title)<8 || mb_strlen($title)>420) continue;
                if(preg_match($bad,$title)) continue;
                if(preg_match('/^(extension of last date|corrigendum|addendum|amendment|revised notice|notice regarding|important notice|expression of interest|know your|home ?notices?|home organisation)\\b/u',$title)) continue;
                if(preg_match('/^(central public info(?:rmation)? officer|tentative vacancy|deputy director of public instruction)$/u',$title)) continue;
                $navHits=0;
                foreach(['about','contact','rti','faq','photos','videos','publications','forms','manuals','press release','resources','conference','home'] as $nav){
                    if(str_contains($title,$nav)) $navHits++;
                }
                if($navHits>=4) continue;

                $generic=['vacancy','vacancies','all vacancies','recruitment','recruitments','jobs','career','careers','application form','apply online','faculty','project','english','hindi','know more about vacancies','vision document','रिक्तियां','भर्ती','देखें'];
                if(in_array($title,$generic,true)) continue;

                $score=preg_match($positive,$title)?4:0;
                if(($item['type']??'')==='pdf') $score+=2;
                if(!$listing && $score<4) continue;
                if($listing && $score<2) continue;
                $scored[]=['score'=>$score,'item'=>$item];
            }

            usort($scored,fn($a,$b)=>$b['score']<=>$a['score']);
            $items=array_slice(array_map(fn($x)=>$x['item'],$scored),0,$max);

            $new=0;
            foreach($items as $item){
                $normalized=$norm->url($item['url']);
                $fingerprint=$norm->hash($normalized.'|'.mb_strtolower($item['title']??''));
                if(!$changes->isNew($source,$fingerprint,$item)) continue;

                $doc=DiscoveredDocument::create([
                    'government_source_id'=>$source->id,
                    'crawl_run_id'=>$run->id,
                    'url'=>$item['url'],
                    'normalized_url'=>$normalized,
                    'title'=>$item['title'],
                    'document_type'=>$item['type'],
                    'content_hash'=>$fingerprint,
                    'raw_text'=>$item['title'],
                    'metadata'=>['discovered_from'=>$baseUrl],
                    'discovered_at'=>now()
                ]);
                ProcessDiscoveredDocument::dispatchSync($doc->id);
                $new++;
            }

            $run->update([
                'status'=>'success','finished_at'=>now(),'http_status'=>$httpStatus,
                'metrics'=>[
                    'candidate_links'=>count($items),'new_documents'=>$new,
                    'http_error'=>$httpError?mb_substr($httpError->getMessage(),0,250):null,
                    'browser_error'=>$browserError?mb_substr($browserError->getMessage(),0,250):null
                ]
            ]);

            $source->update(['last_crawled_at'=>now(),'next_crawl_at'=>now()->addMinutes($source->check_frequency_minutes)]);
            CrawlerHealth::updateOrCreate(['government_source_id'=>$source->id],[
                'status'=>'healthy','consecutive_failures'=>0,'last_success_at'=>now(),'last_error'=>null
            ]);
        }catch(\Throwable $e){
            $run->update(['status'=>'failed','finished_at'=>now(),'error'=>$e->getMessage()]);
            $h=CrawlerHealth::firstOrNew(['government_source_id'=>$source->id]);
            $h->status='failed';
            $h->consecutive_failures=($h->consecutive_failures??0)+1;
            $h->last_failure_at=now();
            $h->last_error=$e->getMessage();
            $h->save();
            throw $e;
        }
    }
}
