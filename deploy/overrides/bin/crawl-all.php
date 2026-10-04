<?php

use App\Enums\SourceStatus;
use App\Jobs\CrawlGovernmentSource;
use App\Models\GovernmentSource;
use Illuminate\Contracts\Console\Kernel;

define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$lockPath = '/data/crawl-all.lock';
$statusPath = '/data/crawl-all-status.json';
$lock = fopen($lockPath, 'c+');

if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    file_put_contents($statusPath, json_encode([
        'status' => 'already_running',
        'updated_at' => now()->toIso8601String(),
    ], JSON_PRETTY_PRINT));
    exit(0);
}

$sourceIds = GovernmentSource::query()
    ->where('status', SourceStatus::Active->value)
    ->orderBy('id')
    ->pluck('id')
    ->all();

$summary = [
    'status' => 'running',
    'started_at' => now()->toIso8601String(),
    'updated_at' => now()->toIso8601String(),
    'total' => count($sourceIds),
    'processed' => 0,
    'succeeded' => 0,
    'failed' => 0,
    'current_source_id' => null,
    'errors' => [],
];

file_put_contents($statusPath, json_encode($summary, JSON_PRETTY_PRINT));

foreach ($sourceIds as $sourceId) {
    $summary['current_source_id'] = $sourceId;
    $summary['updated_at'] = now()->toIso8601String();
    file_put_contents($statusPath, json_encode($summary, JSON_PRETTY_PRINT));

    try {
        CrawlGovernmentSource::dispatchSync($sourceId);
        $summary['succeeded']++;
    } catch (Throwable $e) {
        $summary['failed']++;
        $summary['errors'][] = [
            'source_id' => $sourceId,
            'message' => mb_substr($e->getMessage(), 0, 500),
        ];
        report($e);
    }

    $summary['processed']++;
    $summary['updated_at'] = now()->toIso8601String();
    file_put_contents($statusPath, json_encode($summary, JSON_PRETTY_PRINT));
}

$summary['status'] = 'completed';
$summary['current_source_id'] = null;
$summary['completed_at'] = now()->toIso8601String();
$summary['updated_at'] = now()->toIso8601String();
file_put_contents($statusPath, json_encode($summary, JSON_PRETTY_PRINT));

flock($lock, LOCK_UN);
fclose($lock);
