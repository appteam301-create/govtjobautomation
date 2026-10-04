<?php
namespace App\Http\Controllers;

use App\Enums\CrawlerMode;
use App\Enums\SourceStatus;
use App\Models\GovernmentSource;
use App\Services\Security\UrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GovernmentSourceController extends Controller
{
    private string $statusPath='/data/crawl-all-status.json';
    private string $startPath='/data/crawl-start.flag';
    private string $stopPath='/data/crawl-stop.flag';

    public function index()
    {
        return view('sources.index',[
            'sources'=>GovernmentSource::orderBy('id')->paginate(100),
            'crawlerStatus'=>$this->readStatus(),
        ]);
    }

    public function create(){ return view('sources.create'); }

    public function store(Request $request,UrlGuard $guard)
    {
        $data=$request->validate([
            'name'=>'required|max:255','organization'=>'required|max:255',
            'government_level'=>'required|in:Central,State,UT','state'=>'nullable|max:100',
            'department'=>'nullable|max:255','official_domain'=>'required|max:255',
            'recruitment_url'=>'required|url',
            'crawler_mode'=>'required|in:'.implode(',',array_column(CrawlerMode::cases(),'value')),
            'check_frequency_minutes'=>'required|integer|min:15|max:10080'
        ]);
        $guard->assertSafe($data['recruitment_url']);
        $data['status']=SourceStatus::Active;
        $data['next_crawl_at']=now();
        $source=GovernmentSource::create($data);
        return redirect('/sources')->with('status','Source added: '.$source->name);
    }

    public function crawlAll()
    {
        $status=$this->readStatus();
        if(($status['status']??null)==='running'){
            return back()->with('status','Crawler is already running.');
        }

        $active=GovernmentSource::query()->where('status',SourceStatus::Active->value)->count();
        if($active===0) return back()->with('error','No active sources are available to crawl.');

        @unlink($this->stopPath);
        file_put_contents($this->startPath,now()->toIso8601String(),LOCK_EX);
        return back()->with('status',"Crawler start/resume requested for {$active} active sources.");
    }

    public function stopAll()
    {
        $status=$this->readStatus();
        if(($status['status']??null)!=='running'){
            return back()->with('status','Crawler is not currently running.');
        }
        file_put_contents($this->stopPath,now()->toIso8601String(),LOCK_EX);
        return back()->with('status','Stop requested. The current website will finish, then the crawler will pause.');
    }

    public function crawlStatus(): JsonResponse
    {
        return response()->json($this->readStatus(),200,[
            'Cache-Control'=>'no-store, no-cache, must-revalidate, max-age=0'
        ]);
    }

    public function toggle(GovernmentSource $source)
    {
        $source->status=$source->status===SourceStatus::Active?SourceStatus::Paused:SourceStatus::Active;
        $source->save();
        return back()->with('status','Source status updated.');
    }

    private function readStatus(): array
    {
        if(!is_file($this->statusPath)){
            return ['status'=>'idle','processed'=>0,'total'=>0,'succeeded'=>0,'failed'=>0,'current_source_id'=>null,'sources'=>[]];
        }
        $data=json_decode((string)@file_get_contents($this->statusPath),true);
        return is_array($data)?$data:['status'=>'idle','processed'=>0,'total'=>0,'succeeded'=>0,'failed'=>0,'current_source_id'=>null,'sources'=>[]];
    }
}
