<?php
namespace App\Http\Controllers;

use App\Enums\CrawlerMode;
use App\Enums\SourceStatus;
use App\Models\GovernmentSource;
use App\Services\Security\UrlGuard;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GovernmentSourceController extends Controller
{
    private string $statusPath = '/data/crawl-all-status.json';
    private string $stopPath = '/data/crawl-stop.flag';

    public function index()
    {
        return view('sources.index',[
            'sources'=>GovernmentSource::latest()->paginate(100),
            'crawlerStatus'=>$this->readStatus(),
        ]);
    }

    public function create(){ return view('sources.create'); }

    public function store(Request $request, UrlGuard $guard)
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
        $active = GovernmentSource::query()
            ->where('status', SourceStatus::Active->value)
            ->count();

        if ($active === 0) {
            return back()->with('error','No active sources are available to crawl.');
        }

        @unlink($this->stopPath);

        $php = escapeshellarg(PHP_BINARY);
        $script = escapeshellarg(base_path('bin/crawl-all.php'));
        $log = escapeshellarg(storage_path('logs/crawl-all-launch.log'));
        exec("nohup {$php} {$script} >> {$log} 2>&1 &");

        return back()->with('status',"Crawler started/resumed for {$active} active sources.");
    }

    public function stopAll()
    {
        @file_put_contents($this->stopPath, now()->toIso8601String());
        return back()->with('status','Stop requested. The crawler will finish the current website, then pause before the next website.');
    }

    public function crawlStatus(): JsonResponse
    {
        return response()->json($this->readStatus());
    }

    public function toggle(GovernmentSource $source)
    {
        $source->status=$source->status===SourceStatus::Active ? SourceStatus::Paused : SourceStatus::Active;
        $source->save();
        return back()->with('status','Source status updated.');
    }

    private function readStatus(): array
    {
        if (!is_file($this->statusPath)) {
            return ['status'=>'idle','processed'=>0,'total'=>0,'current_source_id'=>null,'sources'=>[]];
        }

        $data=json_decode((string)@file_get_contents($this->statusPath),true);
        return is_array($data) ? $data : ['status'=>'idle','processed'=>0,'total'=>0,'current_source_id'=>null,'sources'=>[]];
    }
}
