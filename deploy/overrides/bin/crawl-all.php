<?php
use App\Enums\SourceStatus;
use App\Jobs\CrawlGovernmentSource;
use App\Models\GovernmentSource;
use App\Models\JobCandidate;
use Illuminate\Contracts\Console\Kernel;

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try{
    $todayCarbon=\Carbon\Carbon::today(config('app.timezone','Asia/Kolkata'));
    $today=$todayCarbon->toDateString();
    $maxDate=$todayCarbon->copy()->addMonthsNoOverflow(18)->toDateString();

    JobCandidate::query()
        ->where(function($q) use($today,$maxDate){
            $q->whereNull('application_last_date')
              ->orWhereDate('application_last_date','<=',$today)
              ->orWhereDate('application_last_date','>',$maxDate);
        })
        ->delete();
}catch(\Throwable $e){
    report($e);
}

$lockPath='/data/crawl-all.lock';
$statusPath='/data/crawl-all-status.json';
$stopPath='/data/crawl-stop.flag';

$lock=fopen($lockPath,'c+');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) exit(0);

$write=function(array $state) use($statusPath){
    $state['updated_at']=now()->toIso8601String();
    file_put_contents($statusPath,json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
};

$activeIds=GovernmentSource::query()
    ->where('status',SourceStatus::Active->value)
    ->orderBy('id')
    ->pluck('id')->map(fn($v)=>(int)$v)->all();

$previous=[];
if(is_file($statusPath)){
    $decoded=json_decode((string)file_get_contents($statusPath),true);
    if(is_array($decoded)) $previous=$decoded;
}

$resuming=($previous['status']??null)==='stopped' && !empty($previous['source_order']);
$sourceOrder=$resuming
    ? array_values(array_filter(array_map('intval',$previous['source_order']??[]),fn($id)=>in_array($id,$activeIds,true)))
    : $activeIds;

$nextIndex=$resuming ? min(count($sourceOrder),max(0,(int)($previous['next_index']??0))) : 0;
$sourceStates=[];

foreach($sourceOrder as $i=>$id){
    $old=$previous['sources'][(string)$id]??[];
    if($resuming && $i<$nextIndex && in_array(($old['status']??''),['completed','failed'],true)){
        $sourceStates[(string)$id]=$old;
    }else{
        $sourceStates[(string)$id]=[
            'status'=>'pending',
            'started_at'=>null,
            'completed_at'=>null,
            'error'=>null,
            'jobs_found'=>0,
        ];
    }
}

$state=[
    'status'=>'running',
    'started_at'=>$resuming?($previous['started_at']??now()->toIso8601String()):now()->toIso8601String(),
    'resumed_at'=>$resuming?now()->toIso8601String():null,
    'completed_at'=>null,
    'stopped_at'=>null,
    'total'=>count($sourceOrder),
    'processed'=>$nextIndex,
    'succeeded'=>$resuming?(int)($previous['succeeded']??0):0,
    'failed'=>$resuming?(int)($previous['failed']??0):0,
    'current_source_id'=>null,
    'next_index'=>$nextIndex,
    'source_order'=>$sourceOrder,
    'sources'=>$sourceStates,
    'errors'=>$resuming?($previous['errors']??[]):[],
];
$write($state);

for($i=$nextIndex;$i<count($sourceOrder);$i++){
    if(is_file($stopPath)){
        @unlink($stopPath);
        $state['status']='stopped';
        $state['stopped_at']=now()->toIso8601String();
        $state['current_source_id']=null;
        $state['next_index']=$i;
        $write($state);
        flock($lock,LOCK_UN); fclose($lock); exit(0);
    }

    $sourceId=(int)$sourceOrder[$i];
    $source=GovernmentSource::find($sourceId);
    $before=App\Models\JobCandidate::query()->count();

    $state['current_source_id']=$sourceId;
    $state['next_index']=$i;
    $state['sources'][(string)$sourceId]=[
        'status'=>'running',
        'started_at'=>now()->toIso8601String(),
        'completed_at'=>null,
        'error'=>null,
        'jobs_found'=>0,
        'name'=>$source?->name,
    ];
    $write($state);

    try{
        CrawlGovernmentSource::dispatchSync($sourceId);
        $after=App\Models\JobCandidate::query()->count();
        $state['succeeded']++;
        $state['sources'][(string)$sourceId]['status']='completed';
        $state['sources'][(string)$sourceId]['jobs_found']=max(0,$after-$before);
    }catch(Throwable $e){
        $state['failed']++;
        $message=mb_substr($e->getMessage(),0,500);
        $state['sources'][(string)$sourceId]['status']='failed';
        $state['sources'][(string)$sourceId]['error']=$message;
        $state['sources'][(string)$sourceId]['error_class']=get_class($e);
        $state['sources'][(string)$sourceId]['error_file']=$e->getFile();
        $state['sources'][(string)$sourceId]['error_line']=$e->getLine();
        $state['errors'][]=[
            'source_id'=>$sourceId,
            'name'=>$source?->name,
            'message'=>$message,
            'class'=>get_class($e),
            'file'=>$e->getFile(),
            'line'=>$e->getLine(),
        ];
        report($e);
    }

    $state['sources'][(string)$sourceId]['completed_at']=now()->toIso8601String();
    $state['processed']=$i+1;
    $state['next_index']=$i+1;
    $state['current_source_id']=null;
    $write($state);

    if(is_file($stopPath)){
        @unlink($stopPath);
        $state['status']='stopped';
        $state['stopped_at']=now()->toIso8601String();
        $write($state);
        flock($lock,LOCK_UN); fclose($lock); exit(0);
    }
}

$state['status']='completed';
$state['completed_at']=now()->toIso8601String();
$state['current_source_id']=null;
$state['next_index']=count($sourceOrder);
$write($state);

flock($lock,LOCK_UN);
fclose($lock);
