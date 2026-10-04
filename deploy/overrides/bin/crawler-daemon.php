<?php
define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$start='/data/crawl-start.flag';
$status='/data/crawl-all-status.json';

if(is_file($status)){
    $state=json_decode((string)@file_get_contents($status),true);
    if(is_array($state) && ($state['status']??null)==='running'){
        $state['status']='stopped';
        $state['stopped_at']=now()->toIso8601String();
        $state['current_source_id']=null;
        file_put_contents($status,json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX);
    }
}

while(true){
    if(is_file($start)){
        @unlink($start);
        passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/crawl-all.php').' 2>&1');
    }
    sleep(2);
}
