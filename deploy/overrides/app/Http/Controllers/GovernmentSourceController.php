<?php
namespace App\Http\Controllers;

use App\Enums\CrawlerMode;
use App\Enums\SourceStatus;
use App\Jobs\CrawlGovernmentSource;
use App\Models\GovernmentSource;
use App\Services\Security\UrlGuard;
use Illuminate\Http\Request;

class GovernmentSourceController extends Controller
{
    public function index(){ return view('sources.index',['sources'=>GovernmentSource::latest()->paginate(30)]); }
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
        $sourceIds = GovernmentSource::query()
            ->where('status', SourceStatus::Active->value)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (!$sourceIds) {
            return back()->with('error','No active sources are available to crawl.');
        }

        app()->terminating(function () use ($sourceIds) {
            foreach ($sourceIds as $sourceId) {
                try {
                    CrawlGovernmentSource::dispatchSync($sourceId);
                } catch (\Throwable $e) {
                    report($e);
                    // Continue with the next source even if one site fails.
                }
            }
        });

        return back()->with(
            'status',
            'Crawl started for '.count($sourceIds).' active sources. They will be checked one by one using the existing crawler logic.'
        );
    }

    public function toggle(GovernmentSource $source)
    {
        $source->status=$source->status===SourceStatus::Active ? SourceStatus::Paused : SourceStatus::Active;
        $source->save();
        return back()->with('status','Source status updated.');
    }
}
