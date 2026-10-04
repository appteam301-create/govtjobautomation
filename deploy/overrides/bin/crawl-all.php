<?php

use App\Enums\SourceStatus;
use App\Jobs\CrawlGovernmentSource;
use App\Models\GovernmentSource;
use App\Models\JobCandidate;
use App\Services\Extraction\JobDetailsEnricher;
use Illuminate\Contracts\Console\Kernel;

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();


function _aiType(\ReflectionType $type=null): string {
    if(!$type) return '';
    if($type instanceof \ReflectionNamedType){
        $n=$type->getName();
        if(!$type->isBuiltin() && !in_array($n,['self','static','parent'],true)) $n='\\\\'.$n;
        if($type->allowsNull() && !in_array($n,['mixed','null'],true)) return '?'.$n;
        return $n;
    }
    if($type instanceof \ReflectionUnionType) return implode('|',array_map(fn($t)=>_aiType($t),$type->getTypes()));
    if($type instanceof \ReflectionIntersectionType) return implode('&',array_map(fn($t)=>_aiType($t),$type->getTypes()));
    return '';
}
function _aiDefaultReturn(?\ReflectionType $type): string {
    if(!$type) return 'return null;';
    $s=(string)$type;
    if(str_contains($s,'null') || ($type instanceof \ReflectionNamedType && $type->allowsNull())) return 'return null;';
    if($type instanceof \ReflectionNamedType){
        return match($type->getName()){
            'void'=>'return;',
            'bool'=>'return false;',
            'array','iterable'=>'return [];',
            'string'=>'return "";',
            'int'=>'return 0;',
            'float'=>'return 0.0;',
            'mixed'=>'return null;',
            'never'=>'throw new \\RuntimeException("AI disabled");',
            default=>'throw new \\RuntimeException("AI disabled");',
        };
    }
    return 'return null;';
}
function _bindNullAi(): void {
    $contract='App\\Services\\Ai\\AiFallbackService';
    if(!(interface_exists($contract) || class_exists($contract))) return;
    $ref=new \ReflectionClass($contract);
    if($ref->isInstantiable()) return;
    $class='App\\Services\\Ai\\RuntimeNullAiFallback';
    if(!class_exists($class)){
        $methods='';
        foreach($ref->getMethods() as $m){
            if(!$ref->isInterface() && !$m->isAbstract()) continue;
            $params=[];
            foreach($m->getParameters() as $p){
                $part='';
                if($p->hasType()) $part.=_aiType($p->getType()).' ';
                if($p->isPassedByReference()) $part.='&';
                if($p->isVariadic()) $part.='...';
                $part.='
$statusPath='/data/crawl-all-status.json';
$stopPath='/data/crawl-stop.flag';

$lock=fopen($lockPath,'c+');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) exit(0);

$write=function(array $state) use ($statusPath){
    $state['updated_at']=now()->toIso8601String();
    file_put_contents($statusPath,json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
};

$activeIds=GovernmentSource::query()
    ->where('status',SourceStatus::Active->value)
    ->orderBy('id')
    ->pluck('id')
    ->map(fn($v)=>(int)$v)
    ->all();

$previous=[];
if(is_file($statusPath)){
    $decoded=json_decode((string)file_get_contents($statusPath),true);
    if(is_array($decoded)) $previous=$decoded;
}

$resuming=($previous['status']??null)==='stopped' && !empty($previous['source_order']);
$sourceOrder=$resuming
    ? array_values(array_filter(array_map('intval',$previous['source_order']),fn($id)=>in_array($id,$activeIds,true)))
    : $activeIds;

$nextIndex=$resuming ? max(0,(int)($previous['next_index']??0)) : 0;

$sourceStates=[];
foreach($sourceOrder as $i=>$id){
    $old=$previous['sources'][(string)$id]??[];
    if($resuming && $i<$nextIndex && in_array(($old['status']??''),['completed','failed'],true)){
        $sourceStates[(string)$id]=$old;
    } else {
        $sourceStates[(string)$id]=[
            'status'=>$i<$nextIndex?'completed':'pending',
            'started_at'=>$old['started_at']??null,
            'completed_at'=>$old['completed_at']??null,
            'error'=>$old['error']??null,
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

$enricher=app(JobDetailsEnricher::class);

for($i=$nextIndex;$i<count($sourceOrder);$i++){
    if(is_file($stopPath)){
        $state['status']='stopped';
        $state['stopped_at']=now()->toIso8601String();
        $state['current_source_id']=null;
        $state['next_index']=$i;
        $write($state);
        @unlink($stopPath);
        flock($lock,LOCK_UN);
        fclose($lock);
        exit(0);
    }

    $sourceId=(int)$sourceOrder[$i];
    $state['current_source_id']=$sourceId;
    $state['next_index']=$i;
    $state['sources'][(string)$sourceId]=[
        'status'=>'running',
        'started_at'=>now()->toIso8601String(),
        'completed_at'=>null,
        'error'=>null,
    ];
    $write($state);

    try{
        $started=now()->subSeconds(2);
        CrawlGovernmentSource::dispatchSync($sourceId);

        JobCandidate::query()
            ->where('updated_at','>=',$started)
            ->orderBy('id')
            ->each(fn(JobCandidate $candidate)=>$enricher->enrich($candidate));

        $state['succeeded']++;
        $state['sources'][(string)$sourceId]['status']='completed';
    }catch(Throwable $e){
        $state['failed']++;
        $message=mb_substr($e->getMessage(),0,500);
        $state['sources'][(string)$sourceId]['status']='failed';
        $state['sources'][(string)$sourceId]['error']=$message;
        $state['errors'][]=['source_id'=>$sourceId,'message'=>$message];
        report($e);
    }

    $state['sources'][(string)$sourceId]['completed_at']=now()->toIso8601String();
    $state['processed']=$i+1;
    $state['next_index']=$i+1;
    $state['current_source_id']=null;
    $write($state);

    if(is_file($stopPath)){
        $state['status']='stopped';
        $state['stopped_at']=now()->toIso8601String();
        $write($state);
        @unlink($stopPath);
        flock($lock,LOCK_UN);
        fclose($lock);
        exit(0);
    }
}

$state['status']='completed';
$state['current_source_id']=null;
$state['completed_at']=now()->toIso8601String();
$state['next_index']=count($sourceOrder);
$write($state);

flock($lock,LOCK_UN);
fclose($lock);
.$p->getName();
                if($p->isDefaultValueAvailable() && !$p->isVariadic()){
                    try{$part.=' = '.var_export($p->getDefaultValue(),true);}catch(\Throwable $e){}
                }
                $params[]=$part;
            }
            $ret=$m->hasReturnType()?': '._aiType($m->getReturnType()):'';
            $static=$m->isStatic()?' static':'';
            $byref=$m->returnsReference()?'&':'';
            $methods.='public'.$static.' function '.$byref.$m->getName().'('.implode(', ',$params).')'.$ret.' { '._aiDefaultReturn($m->getReturnType()).' }';
        }
        $kind=$ref->isInterface()?'implements':'extends';
        eval('namespace App\\Services\\Ai; final class RuntimeNullAiFallback '.$kind.' \\'.$contract.' { '.$methods.' }');
    }
    app()->bind($contract,$class);
}
if(filter_var(env('AI_ENABLED',false),FILTER_VALIDATE_BOOLEAN)===false) _bindNullAi();

$lockPath='/data/crawl-all.lock';
$statusPath='/data/crawl-all-status.json';
$stopPath='/data/crawl-stop.flag';

$lock=fopen($lockPath,'c+');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) exit(0);

$write=function(array $state) use ($statusPath){
    $state['updated_at']=now()->toIso8601String();
    file_put_contents($statusPath,json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
};

$activeIds=GovernmentSource::query()
    ->where('status',SourceStatus::Active->value)
    ->orderBy('id')
    ->pluck('id')
    ->map(fn($v)=>(int)$v)
    ->all();

$previous=[];
if(is_file($statusPath)){
    $decoded=json_decode((string)file_get_contents($statusPath),true);
    if(is_array($decoded)) $previous=$decoded;
}

$resuming=($previous['status']??null)==='stopped' && !empty($previous['source_order']);
$sourceOrder=$resuming
    ? array_values(array_filter(array_map('intval',$previous['source_order']),fn($id)=>in_array($id,$activeIds,true)))
    : $activeIds;

$nextIndex=$resuming ? max(0,(int)($previous['next_index']??0)) : 0;

$sourceStates=[];
foreach($sourceOrder as $i=>$id){
    $old=$previous['sources'][(string)$id]??[];
    if($resuming && $i<$nextIndex && in_array(($old['status']??''),['completed','failed'],true)){
        $sourceStates[(string)$id]=$old;
    } else {
        $sourceStates[(string)$id]=[
            'status'=>$i<$nextIndex?'completed':'pending',
            'started_at'=>$old['started_at']??null,
            'completed_at'=>$old['completed_at']??null,
            'error'=>$old['error']??null,
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

$enricher=app(JobDetailsEnricher::class);

for($i=$nextIndex;$i<count($sourceOrder);$i++){
    if(is_file($stopPath)){
        $state['status']='stopped';
        $state['stopped_at']=now()->toIso8601String();
        $state['current_source_id']=null;
        $state['next_index']=$i;
        $write($state);
        @unlink($stopPath);
        flock($lock,LOCK_UN);
        fclose($lock);
        exit(0);
    }

    $sourceId=(int)$sourceOrder[$i];
    $state['current_source_id']=$sourceId;
    $state['next_index']=$i;
    $state['sources'][(string)$sourceId]=[
        'status'=>'running',
        'started_at'=>now()->toIso8601String(),
        'completed_at'=>null,
        'error'=>null,
    ];
    $write($state);

    try{
        $started=now()->subSeconds(2);
        CrawlGovernmentSource::dispatchSync($sourceId);

        JobCandidate::query()
            ->where('updated_at','>=',$started)
            ->orderBy('id')
            ->each(fn(JobCandidate $candidate)=>$enricher->enrich($candidate));

        $state['succeeded']++;
        $state['sources'][(string)$sourceId]['status']='completed';
    }catch(Throwable $e){
        $state['failed']++;
        $message=mb_substr($e->getMessage(),0,500);
        $state['sources'][(string)$sourceId]['status']='failed';
        $state['sources'][(string)$sourceId]['error']=$message;
        $state['errors'][]=['source_id'=>$sourceId,'message'=>$message];
        report($e);
    }

    $state['sources'][(string)$sourceId]['completed_at']=now()->toIso8601String();
    $state['processed']=$i+1;
    $state['next_index']=$i+1;
    $state['current_source_id']=null;
    $write($state);

    if(is_file($stopPath)){
        $state['status']='stopped';
        $state['stopped_at']=now()->toIso8601String();
        $write($state);
        @unlink($stopPath);
        flock($lock,LOCK_UN);
        fclose($lock);
        exit(0);
    }
}

$state['status']='completed';
$state['current_source_id']=null;
$state['completed_at']=now()->toIso8601String();
$state['next_index']=count($sourceOrder);
$write($state);

flock($lock,LOCK_UN);
fclose($lock);
