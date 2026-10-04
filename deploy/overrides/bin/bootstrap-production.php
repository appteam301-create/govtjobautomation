<?php
use App\Models\GovernmentSource;
use App\Models\SourceRule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$marker='/data/source-catalog-v20261004-3.done';
if(is_file($marker)){ exit(0); }

$sources=require __DIR__.'/../config/govjob_sources.php';
$include=['recruitment','vacancy','vacancies','advertisement','advt','applications invited','apply online','direct recruitment','walk-in','walk in','apprentice','faculty position','non-faculty','project position','research associate','junior research fellow','senior research fellow','assistant professor','associate professor','professor','consultant','resident','technician','officer','assistant','engineer','stenographer','clerk','manager','executive','medical officer','staff nurse','postdoctoral','fellowship','trainee'];
$ignore=['final result','provisional result','admit card','hall ticket','answer key','merit list','shortlist','shortlisted','eligible candidates','not eligible','interview schedule','exam schedule','examination schedule','objection','response sheet','cut off','cutoff','appointment order','appointment letter','tender','procurement','auction'];

DB::transaction(function() use ($sources,$include,$ignore){
    GovernmentSource::query()->delete();

    foreach($sources as $row){
        $source=GovernmentSource::create([
            'name'=>$row['name'],
            'organization'=>$row['organization'],
            'government_level'=>$row['government_level'],
            'state'=>$row['state'],
            'department'=>$row['department'],
            'official_domain'=>$row['official_domain'],
            'recruitment_url'=>$row['recruitment_url'],
            'crawler_mode'=>$row['crawler_mode'],
            'status'=>'active',
            'check_frequency_minutes'=>$row['check_frequency_minutes'],
            'last_crawled_at'=>null,
            'next_crawl_at'=>now(),
            'settings'=>$row['settings'],
        ]);
        SourceRule::create([
            'government_source_id'=>$source->id,
            'include_keywords'=>$include,
            'ignore_keywords'=>$ignore,
            'enabled'=>true,
        ]);
    }
});

foreach(['/data/crawl-all-status.json','/data/crawl-stop.flag','/data/crawl-all.lock'] as $p){ @unlink($p); }
file_put_contents($marker,json_encode(['imported_at'=>now()->toIso8601String(),'count'=>count($sources)],JSON_PRETTY_PRINT));
echo "Imported ".count($sources)." verified recruitment sources and cleared old crawl/review data.\n";
