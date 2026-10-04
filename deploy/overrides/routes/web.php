<?php
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GovernmentSourceController;
use App\Http\Controllers\JobDetailsController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');
Route::resource('sources', GovernmentSourceController::class)->only(['index','create','store']);
Route::post('/sources/crawl-all', [GovernmentSourceController::class,'crawlAll'])->name('sources.crawlAll');
Route::post('/sources/stop-all', [GovernmentSourceController::class,'stopAll'])->name('sources.stopAll');
Route::get('/sources/crawl-status', [GovernmentSourceController::class,'crawlStatus'])->name('sources.crawlStatus');
Route::post('/sources/{source}/toggle', [GovernmentSourceController::class,'toggle'])->name('sources.toggle');
Route::get('/reviews', [ReviewController::class,'index'])->name('reviews.index');
Route::get('/reviews/{candidate}', [ReviewController::class,'show'])->name('reviews.show');
Route::post('/reviews/{candidate}/details', [JobDetailsController::class,'update'])->name('reviews.details');
Route::post('/reviews/{candidate}', [ReviewController::class,'decide'])->name('reviews.decide');
