<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/auth/redirect', [AuthController::class, 'redirect']);

Route::get('/auth/callback', [AuthController::class, 'callback']);

Route::middleware('auth.bitbucket')->group(function () {
    Route::get('logout', [AuthController::class, 'destroy']);

    Route::get('/', [ReviewController::class, 'index']);
    Route::get('/send-emails', [ReviewController::class, 'sendEmails']);
});
