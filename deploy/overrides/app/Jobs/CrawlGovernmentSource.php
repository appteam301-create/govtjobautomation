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
    public int $tries=2;
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
        $run=CrawlRun::create(['government_source_id'=>$source->id,'started_at'=>now(),'status'=>'running']);

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
                }catch(\Throwable $e){
                    // Keep HTTP-discovered items if browser fallback is unavailable.
                }
            }

            $listing=(bool)($settings['listing_is_recruitment']??false);
            $max=max(10,min(120,(int)($settings['max_items']??80)));
            $items=array_values(array_filter($items,function(array $item) use ($listing){
                $hay=mb_strtolower(($item['title']??'').' '.($item['url']??''));
                if(preg_match('/\b(result|admit card|answer key|merit list|shortlist|tender|procurement|auction|corrigendum only)\b/u',$hay)) return false;
                if($listing && (($item['type']??'')==='pdf')) return true;
                $keywords=['recruit','vacan','career','job','advert','advt','apply','appointment','apprent','faculty','non-faculty','project','fellow','resident','consultant','trainee','position','notification'];
                foreach($keywords as $k) if(str_contains($hay,$k)) return true;
                return $listing;
            }));
            $items=array_slice($items,0,$max);

            $new=0;
            foreach($items as $item){
                $normalized=$norm->url($item['url']);
                $fingerprint=$norm->hash($normalized.'|'.mb_strtolower($item['title']??''));
                if(!$changes->isNew($source,$fingerprint,$item)) continue;
                $new++;
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
            }

            $run->update([
                'status'=>'success','finished_at'=>now(),'http_status'=>$httpStatus,
                'metrics'=>['links'=>count($items),'new'=>$new,'http_fallback_error'=>$fetchError?mb_substr($fetchError->getMessage(),0,250):null]
            ]);
            $source->update(['last_crawled_at'=>now(),'next_crawl_at'=>now()->addMinutes($source->check_frequency_minutes)]);
            CrawlerHealth::updateOrCreate(['government_source_id'=>$source->id],['status'=>'healthy','consecutive_failures'=>0,'last_success_at'=>now(),'last_error'=>null]);
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
