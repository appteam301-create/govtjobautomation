<?php
namespace App\Http\Controllers;

use App\Enums\CrawlerMode;
use App\Enums\SourceStatus;
use App\Models\GovernmentSource;
use App\Services\Security\UrlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        @unlink('/data/crawl-reset.flag');
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

    public function clearAllData()
    {
        // Prevent any in-flight crawler from repopulating data during a reset.
        @file_put_contents($this->stopPath,now()->toIso8601String(),LOCK_EX);
        @unlink($this->startPath);
        @file_put_contents('/data/crawl-reset.flag',now()->toIso8601String(),LOCK_EX);

        $tables=[
            'job_evidence','job_evidences','review_actions','publish_attempts',
            'job_candidates','discovered_documents','crawl_runs',
            'crawler_health','crawler_healths','source_rules','government_sources',
            'jobs','job_batches','failed_jobs'
        ];

        $driver=DB::getDriverName();

        try{
            if($driver==='sqlite') DB::statement('PRAGMA foreign_keys = OFF');
            else Schema::disableForeignKeyConstraints();

            foreach($tables as $table){
                if(Schema::hasTable($table)) DB::table($table)->delete();
            }

            if($driver==='sqlite' && Schema::hasTable('sqlite_sequence')){
                $names=array_values(array_filter($tables,fn($t)=>Schema::hasTable($t)));
                if($names){
                    DB::table('sqlite_sequence')->whereIn('name',$names)->delete();
                }
            }
        }finally{
            if($driver==='sqlite') DB::statement('PRAGMA foreign_keys = ON');
            else Schema::enableForeignKeyConstraints();
        }

        foreach([
            $this->statusPath,
            $this->startPath,
            $this->stopPath,
            '/data/crawl-all.lock'
        ] as $path){
            @unlink($path);
        }

        // Keep the reset flag until the next intentional crawl start.
        return redirect('/sources')->with('status','All data cleared. Sources, found jobs, crawler history and review data are now empty.');
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
