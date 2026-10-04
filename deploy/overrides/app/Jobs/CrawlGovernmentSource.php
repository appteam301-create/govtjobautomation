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
    public int $timeout=180;

    public function __construct(public int $sourceId){}

    public function handle(
        HttpFetcher $fetcher,
        HtmlDiscovery $discovery,
        ContentNormalizer $norm,
        ChangeDetector $changes,
        BrowserRenderer $browser
    ): void {
        $source=GovernmentSource::findOrFail($this->sourceId);
        $run=CrawlRun::create([
            'government_source_id'=>$source->id,
            'started_at'=>now(),
            'status'=>'running'
        ]);

        try{
            $settings=$source->settings??[];
            $mode=$source->crawler_mode;
            $forceBrowser=(bool)($settings['force_browser']??false);
            $html='';
            $baseUrl=$source->recruitment_url;
            $httpStatus=null;
            $fetchError=null;

            if(!$forceBrowser && $mode!==CrawlerMode::Js){
                try{
                    $result=$fetcher->fetch($source->recruitment_url);
                    $html=$result->body;
                    $baseUrl=$result->url;
                    $httpStatus=$result->status;
                }catch(\Throwable $e){
                    $fetchError=$e;
                }
            }

            if($html===''){
                $html=$browser->render($source->recruitment_url);
                $baseUrl=$source->recruitment_url;
                $httpStatus=$httpStatus??200;
            }

            $items=$discovery->discover($html,$baseUrl);

            if(!$forceBrowser && $mode===CrawlerMode::Auto && count($items)<8){
                try{
                    $rendered=$browser->render($source->recruitment_url);
                    $renderedItems=$discovery->discover($rendered,$source->recruitment_url);
                    if(count($renderedItems)>count($items)) $items=$renderedItems;
                }catch(\Throwable $e){}
            }

            $listing=(bool)($settings['listing_is_recruitment']??false);
            $max=max(10,min(120,(int)($settings['max_items']??80)));
            $keywords=['recruit','vacan','career','job','advert','advt','apply','appointment','apprent','faculty','non-faculty','project','fellow','resident','consultant','trainee','position','officer','assistant','engineer','technician','clerk','manager','executive','medical officer','staff nurse','professor'];
            $bad='/\b(result|admit card|answer key|merit list|shortlist|shortlisted|tender|procurement|auction|objection|cut[ -]?off|interview schedule|exam schedule)\b/u';

            $scored=[];
            foreach($items as $item){
                $hay=mb_strtolower(($item['title']??'').' '.($item['url']??''));
                if(preg_match($bad,$hay)) continue;

                $score=(($item['type']??'')==='pdf')?3:0;
                foreach($keywords as $k){
                    if(str_contains($hay,$k)){ $score+=2; }
                }

                if($score===0) continue;
                if(!$listing && $score<2) continue;
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
                'status'=>'success',
                'finished_at'=>now(),
                'http_status'=>$httpStatus,
                'metrics'=>[
                    'candidate_links'=>count($items),
                    'new_documents'=>$new,
                    'http_fallback_error'=>$fetchError?mb_substr($fetchError->getMessage(),0,250):null
                ]
            ]);

            $source->update([
                'last_crawled_at'=>now(),
                'next_crawl_at'=>now()->addMinutes($source->check_frequency_minutes)
            ]);

            CrawlerHealth::updateOrCreate(
                ['government_source_id'=>$source->id],
                ['status'=>'healthy','consecutive_failures'=>0,'last_success_at'=>now(),'last_error'=>null]
            );
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
