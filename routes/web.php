<?php

use App\Http\Controllers\AgentFileController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\McpTokenController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PullRequestReviewController;
use App\Http\Controllers\ReviewController;
use App\Http\Middleware\RequireBitbucketToken;
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

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', [ReviewController::class, 'index'])->name('reviews.index');
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/emails', [NotificationController::class, 'sendEmails']);

    Route::get('configuration', [ConfigurationController::class, 'index']);
    Route::put('configuration', [ConfigurationController::class, 'update']);
    Route::post('configuration/mcp-token', [McpTokenController::class, 'store']);
    Route::delete('configuration/mcp-token', [McpTokenController::class, 'destroy']);
    Route::post('configuration/bitbucket-token', [AccountController::class, 'storeBitbucketToken']);
    Route::delete('configuration/bitbucket-token', [AccountController::class, 'destroyBitbucketToken']);
    Route::put('configuration/password', [AccountController::class, 'updatePassword']);

    Route::get('files', [AgentFileController::class, 'index']);
    Route::post('files', [AgentFileController::class, 'store']);
    Route::put('files/{file}', [AgentFileController::class, 'update']);
    Route::delete('files/{file}', [AgentFileController::class, 'destroy']);

    // Review pages call Bitbucket as the user, with their own API token.
    Route::prefix('pull-requests/{workspace}/{repository}/{id}')
        ->middleware(RequireBitbucketToken::class)
        ->where(['workspace' => '[A-Za-z0-9_.-]+', 'repository' => '[A-Za-z0-9_.-]+', 'id' => '[0-9]+', 'comment' => '[0-9]+', 'draft' => '[0-9]+'])
        ->controller(PullRequestReviewController::class)
        ->group(function () {
            Route::get('/', 'show')->name('pull-requests.review');
            // Unsent comments, saved here until the user sends them all to Bitbucket.
            Route::post('drafts', 'storeDraft')->name('pull-requests.drafts.store');
            Route::put('drafts/{draft}', 'updateDraft')->name('pull-requests.drafts.update');
            Route::delete('drafts/{draft}', 'destroyDraft')->name('pull-requests.drafts.destroy');
            Route::post('drafts/publish', 'publish')->name('pull-requests.drafts.publish');
            Route::put('viewed', 'markViewed')->name('pull-requests.viewed');
            Route::get('file', 'fileContent')->name('pull-requests.file');
            Route::put('review-state', 'setReviewState')->name('pull-requests.review-state');
            // Pending comments from a draft review started in Bitbucket.
            Route::put('comments/{comment}', 'update')->name('pull-requests.comments.update');
            Route::delete('comments/{comment}', 'destroy')->name('pull-requests.comments.destroy');
            Route::put('comments/{comment}/resolution', 'setResolved')->name('pull-requests.comments.resolution');
        });
});
