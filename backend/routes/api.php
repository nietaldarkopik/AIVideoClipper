<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClipCandidateController;
use App\Http\Controllers\Api\ClipController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProcessingJobController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\PublishingProfileController;
use App\Http\Controllers\Api\SocialAccountController;
use App\Http\Controllers\Api\SocialPostController;
use App\Http\Controllers\Api\TemplateCategoryController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\MediaStreamController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

Route::get('/media/{path}', [MediaStreamController::class, 'stream'])
    ->where('path', '.*')
    ->name('media.stream');

// Templates & categories are readable by anyone signed in; mutations are admin-only below.
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::patch('/projects/{project}', [ProjectController::class, 'update']);
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
    Route::post('/projects/{project}/analyze', [ProjectController::class, 'analyze']);
    Route::post('/projects/{project}/generate-clips', [ProjectController::class, 'generateClips']);
    Route::get('/projects/{project}/clip-candidates', [ClipCandidateController::class, 'index']);
    Route::get('/projects/{project}/processing-jobs', [ProcessingJobController::class, 'forProject']);
    Route::post('/projects/{project}/videos', [VideoController::class, 'store']);

    Route::get('/videos/{video}', [VideoController::class, 'show']);

    Route::get('/clip-candidates/{clipCandidate}', [ClipCandidateController::class, 'show']);

    Route::get('/clips', [ClipController::class, 'index']);
    Route::get('/clips/{clip}', [ClipController::class, 'show']);
    Route::patch('/clips/{clip}', [ClipController::class, 'update']);
    Route::delete('/clips/{clip}', [ClipController::class, 'destroy']);
    Route::post('/clips/{clip}/duplicate', [ClipController::class, 'duplicate']);
    Route::post('/clips/{clip}/regenerate', [ClipController::class, 'regenerate']);
    Route::post('/clips/{clip}/generate-social-metadata', [ClipController::class, 'generateSocialMetadata']);
    Route::post('/clips/export-zip', [ClipController::class, 'exportZip']);

    Route::get('/template-categories', [TemplateCategoryController::class, 'index']);
    Route::get('/templates', [TemplateController::class, 'index']);
    Route::get('/templates/{template}', [TemplateController::class, 'show']);

    Route::get('/social-accounts/platforms', [SocialAccountController::class, 'platforms']);
    Route::get('/social-accounts', [SocialAccountController::class, 'index']);
    Route::post('/social-accounts/connect', [SocialAccountController::class, 'connect']);
    Route::patch('/social-accounts/{socialAccount}', [SocialAccountController::class, 'update']);
    Route::post('/social-accounts/{socialAccount}/refresh', [SocialAccountController::class, 'refresh']);
    Route::post('/social-accounts/{socialAccount}/disconnect', [SocialAccountController::class, 'disconnect']);

    Route::apiResource('publishing-profiles', PublishingProfileController::class)->except(['show']);

    Route::get('/social-posts', [SocialPostController::class, 'index']);
    Route::post('/social-posts', [SocialPostController::class, 'store']);
    Route::get('/social-posts/{socialPost}', [SocialPostController::class, 'show']);
    Route::post('/social-posts/{socialPost}/retry', [SocialPostController::class, 'retry']);
    Route::delete('/social-posts/{socialPost}', [SocialPostController::class, 'destroy']);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/projects', [AdminController::class, 'projects']);
        Route::get('/processing-jobs', [AdminController::class, 'processingJobs']);
        Route::get('/settings', [AdminController::class, 'settings']);
        Route::patch('/settings', [AdminController::class, 'updateSettings']);

        Route::post('/template-categories', [TemplateCategoryController::class, 'store']);
        Route::patch('/template-categories/{templateCategory}', [TemplateCategoryController::class, 'update']);
        Route::delete('/template-categories/{templateCategory}', [TemplateCategoryController::class, 'destroy']);

        Route::post('/templates', [TemplateController::class, 'store']);
        Route::patch('/templates/{template}', [TemplateController::class, 'update']);
        Route::post('/templates/{template}/duplicate', [TemplateController::class, 'duplicate']);
        Route::post('/templates/{template}/archive', [TemplateController::class, 'archive']);
        Route::delete('/templates/{template}', [TemplateController::class, 'destroy']);
    });
});
