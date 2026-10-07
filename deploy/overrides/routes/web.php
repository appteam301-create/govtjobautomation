<?php
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GovernmentSourceController;
use App\Http\Controllers\JobDetailsController;
use App\Http\Controllers\ReviewController;
use App\Models\JobCandidate;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');
Route::resource('sources', GovernmentSourceController::class)->only(['index','create','store']);
Route::post('/sources/crawl-all', [GovernmentSourceController::class,'crawlAll'])->name('sources.crawlAll');
Route::post('/sources/stop-all', [GovernmentSourceController::class,'stopAll'])->name('sources.stopAll');
Route::post('/sources/clear-all', [GovernmentSourceController::class,'clearAllData'])->name('sources.clearAll');
Route::get('/sources/crawl-status', [GovernmentSourceController::class,'crawlStatus'])->name('sources.crawlStatus');
Route::post('/sources/{source}/toggle', [GovernmentSourceController::class,'toggle'])->name('sources.toggle');
Route::get('/reviews', function () {
    $today=\Carbon\Carbon::today(config('app.timezone','Asia/Kolkata'));
    $maxDate=$today->copy()->addMonthsNoOverflow(18)->toDateString();
    $todayString=$today->toDateString();

    $candidates=JobCandidate::query()
        ->whereNotNull('application_last_date')
        ->whereDate('application_last_date','>',$todayString)
        ->whereDate('application_last_date','<=',$maxDate)
        ->latest()
        ->paginate(50);

    return view('reviews.index',compact('candidates'));
})->name('reviews.index');
Route::get('/reviews/{candidate}', [ReviewController::class,'show'])->name('reviews.show');
Route::post('/reviews/{candidate}/details', [JobDetailsController::class,'update'])->name('reviews.details');
Route::post('/reviews/{candidate}/fetch-all-data', [JobDetailsController::class,'fetchAllData'])->name('reviews.fetchAllData');
Route::post('/reviews/{candidate}', [ReviewController::class,'decide'])->name('reviews.decide');


Route::get('/internal/export-source/pkg-20261007-8f4e2c91a7', function () {
    $base = base_path();
    $zipPath = storage_path('app/latestgovtjobs-automation-full.zip');
    @mkdir(dirname($zipPath), 0775, true);
    @unlink($zipPath);

    $zip = new \ZipArchive();
    if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        abort(500, 'Unable to create archive');
    }

    $excludedPrefixes = [
        'vendor/', 'node_modules/', '.git/', 'storage/logs/', 'storage/framework/',
        'bootstrap/cache/', 'database/database.sqlite',
    ];
    $excludedFiles = ['.env'];

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $absolute = $file->getPathname();
        $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($base))), '/');

        if (in_array($relative, $excludedFiles, true)) continue;
        $skip = false;
        foreach ($excludedPrefixes as $prefix) {
            if ($relative === rtrim($prefix, '/') || str_starts_with($relative, $prefix)) {
                $skip = true; break;
            }
        }
        if ($skip) continue;

        $zip->addFile($absolute, $relative);
    }

    $zip->close();
    return response()->download($zipPath, 'latestgovtjobs-automation-full.zip')->deleteFileAfterSend(true);
});
