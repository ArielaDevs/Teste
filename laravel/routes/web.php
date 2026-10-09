<?php

use App\Http\Controllers\LegacyBridgeController;
use App\Http\Controllers\LegacyContextController;
use App\Http\Controllers\LegacyModuleMapController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');
Route::get('/health', fn () => response()->json(['status' => 'ok']));
Route::get('/legacy/context', [LegacyContextController::class, 'show'])->name('legacy.context');
Route::get('/legacy/modules', [LegacyModuleMapController::class, 'index'])->name('legacy.modules');

Route::get('/legacy/{module}/{path?}', [LegacyBridgeController::class, 'redirect'])
    ->where('path', '.*')
    ->name('legacy.redirect');
