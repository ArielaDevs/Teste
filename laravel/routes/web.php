<?php

use App\Http\Controllers\LegacyBridgeController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');
Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::get('/legacy/{module}/{path?}', [LegacyBridgeController::class, 'redirect'])
    ->where('path', '.*')
    ->name('legacy.redirect');
