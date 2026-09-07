<?php

use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Admin\BackupController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChannelWatchController;
use App\Http\Controllers\Api\ClipCandidateController;
use App\Http\Controllers\Api\ClipController;
use App\Http\Controllers\Api\ContentBriefController;
use App\Http\Controllers\Api\CoverTemplateController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MediaUploadController;
use App\Http\Controllers\Api\ProcessingJobController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\PublishingProfileController;
use App\Http\Controllers\Api\ReactionController;
use App\Http\Controllers\Api\Research\ContentChannelController;
use App\Http\Controllers\Api\Research\ContentIdeaController;
use App\Http\Controllers\Api\Research\ResearchDashboardController;
use App\Http\Controllers\Api\Research\ResearchRunController;
use App\Http\Controllers\Api\Research\ResearchSourceController;
use App\Http\Controllers\Api\SocialAccountController;
use App\Http\Controllers\Api\SocialPostController;
use App\Http\Controllers\Api\StickerController;
use App\Http\Controllers\Api\TemplateCategoryController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\TrendingController;
use App\Http\Controllers\Api\VideoBatchController;
use App\Http\Controllers\Api\VideoController;
use App\Http\Controllers\MediaStreamController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/auth/tiktok/redirect', [AuthController::class, 'tiktokRedirect']);
// Public: TikTok redirects the bare browser here after consent, same reasoning
// as the /social-accounts/{platform}/callback route below.
Route::get('/auth/tiktok/callback', [AuthController::class, 'tiktokCallback']);

Route::get('/media/{path}', [MediaStreamController::class, 'stream'])
    ->where('path', '.*')
    ->name('media.stream');

// Public: the browser lands here straight from the platform's OAuth consent screen
// (Google/etc.), with no bearer token attached — the signed `state` param is what
// identifies which user this connection belongs to. See SocialAccountController.
Route::get('/social-accounts/{platform}/callback', [SocialAccountController::class, 'callback']);

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
    Route::post('/projects/{project}/reprocess', [ProjectController::class, 'reprocess']);
    Route::post('/projects/{project}/generate-clips', [ProjectController::class, 'generateClips']);
    Route::get('/projects/{project}/clip-candidates', [ClipCandidateController::class, 'index']);
    Route::get('/projects/{project}/processing-jobs', [ProcessingJobController::class, 'forProject']);
    Route::post('/projects/{project}/processing-jobs/{processingJob}/cancel', [ProcessingJobController::class, 'cancel']);
    Route::post('/projects/{project}/videos', [VideoController::class, 'store']);

    Route::get('/videos/{video}', [VideoController::class, 'show']);

    Route::get('/video-batches', [VideoBatchController::class, 'index']);
    Route::post('/video-batches', [VideoBatchController::class, 'store']);
    Route::get('/video-batches/{videoBatch}', [VideoBatchController::class, 'show']);
    Route::post('/video-batches/{videoBatch}/cancel', [VideoBatchController::class, 'cancel']);
    Route::post('/video-batches/{videoBatch}/items/{item}/retry', [VideoBatchController::class, 'retryItem']);
    Route::delete('/video-batches/{videoBatch}', [VideoBatchController::class, 'destroy']);

    Route::apiResource('channel-watches', ChannelWatchController::class)->except(['show']);
    Route::post('/channel-watches/{channelWatch}/check', [ChannelWatchController::class, 'checkNow']);

    Route::get('/clip-candidates/{clipCandidate}', [ClipCandidateController::class, 'show']);
    Route::post('/clip-candidates/{clipCandidate}/reaction', [ReactionController::class, 'store']);

    // Per-user media library backing image/logo/audio layers' relative paths —
    // see MediaUploadController and LayerCompositionService.
    Route::get('/stickers', [StickerController::class, 'index']);

    Route::get('/media-uploads', [MediaUploadController::class, 'index']);
    Route::post('/media-uploads', [MediaUploadController::class, 'store']);
    Route::delete('/media-uploads', [MediaUploadController::class, 'destroy']);

    Route::get('/clips', [ClipController::class, 'index']);
    // Must precede /clips/{clip} — otherwise "search" is swallowed as a {clip} id.
    Route::get('/clips/search', [ClipController::class, 'search']);
    Route::get('/clips/{clip}', [ClipController::class, 'show']);
    Route::get('/clips/{clip}/preview-config', [ClipController::class, 'previewConfig']);
    Route::patch('/clips/{clip}', [ClipController::class, 'update']);
    Route::delete('/clips/{clip}', [ClipController::class, 'destroy']);
    Route::post('/clips/{clip}/duplicate', [ClipController::class, 'duplicate']);
    Route::post('/clips/{clip}/subtitle', [ClipController::class, 'uploadSubtitle']);
    Route::delete('/clips/{clip}/subtitle', [ClipController::class, 'removeSubtitle']);
    Route::post('/clips/{clip}/regenerate', [ClipController::class, 'regenerate']);
    Route::post('/clips/{clip}/reaction', [ReactionController::class, 'updateClip']);
    Route::post('/clips/{clip}/generate-social-metadata', [ClipController::class, 'generateSocialMetadata']);
    Route::post('/clips/{clip}/generate-reaction-script', [ClipController::class, 'generateReactionScript']);
    Route::post('/clips/{clip}/generate-cover', [ClipController::class, 'generateCover']);
    Route::post('/clips/export-zip', [ClipController::class, 'exportZip']);

    Route::get('/template-categories', [TemplateCategoryController::class, 'index']);
    Route::get('/templates', [TemplateController::class, 'index']);
    Route::get('/templates/demo-source', [TemplateController::class, 'demoSource']);
    Route::get('/templates/{template}', [TemplateController::class, 'show']);

    Route::get('/cover-templates', [CoverTemplateController::class, 'index']);
    // Must precede /cover-templates/{coverTemplate} — otherwise "demo-frame" is
    // swallowed as a {coverTemplate} id.
    Route::get('/cover-templates/demo-frame', [CoverTemplateController::class, 'demoFrame']);
    Route::get('/cover-templates/{coverTemplate}', [CoverTemplateController::class, 'show']);

    Route::get('/trending', [TrendingController::class, 'index']);
    Route::get('/trending/platforms', [TrendingController::class, 'platforms']);

    Route::get('/content-briefs', [ContentBriefController::class, 'index']);
    Route::post('/content-briefs', [ContentBriefController::class, 'store']);
    Route::get('/content-briefs/{contentBrief}', [ContentBriefController::class, 'show']);
    Route::post('/content-briefs/{contentBrief}/regenerate-script', [ContentBriefController::class, 'regenerateScript']);
    Route::post('/content-briefs/{contentBrief}/search-videos', [ContentBriefController::class, 'searchVideos']);
    Route::delete('/content-briefs/{contentBrief}', [ContentBriefController::class, 'destroy']);

    // --- Content Research Engine ---
    // "Content channels" are brand/persona channels the research engine generates
    // ideas for — distinct from /channel-watches above, which are watched YouTube
    // upload feeds.
    Route::get('/research/dashboard', [ResearchDashboardController::class, 'index']);
    Route::get('/research/platforms', [ResearchSourceController::class, 'platforms']);
    Route::get('/research/channel-templates', [ResearchSourceController::class, 'templates']);
    Route::get('/research/sources', [ResearchSourceController::class, 'index']);
    Route::get('/research/sources/{researchSource}', [ResearchSourceController::class, 'show']);

    Route::get('/research/runs', [ResearchRunController::class, 'index']);
    Route::get('/research/runs/{researchRun}', [ResearchRunController::class, 'show']);

    Route::apiResource('content-channels', ContentChannelController::class);
    Route::get('/content-channels/{contentChannel}/research-runs', [ResearchRunController::class, 'forChannel']);
    Route::post('/content-channels/{contentChannel}/research-runs', [ResearchRunController::class, 'store']);
    Route::get('/content-channels/{contentChannel}/content-ideas', [ContentIdeaController::class, 'forChannel']);

    Route::get('/content-ideas', [ContentIdeaController::class, 'index']);
    Route::get('/content-ideas/{contentIdea}', [ContentIdeaController::class, 'show']);
    Route::patch('/content-ideas/{contentIdea}', [ContentIdeaController::class, 'update']);
    Route::post('/content-ideas/{contentIdea}/select', [ContentIdeaController::class, 'select']);
    Route::post('/content-ideas/{contentIdea}/reject', [ContentIdeaController::class, 'reject']);
    Route::delete('/content-ideas/{contentIdea}', [ContentIdeaController::class, 'destroy']);

    Route::get('/social-accounts/platforms', [SocialAccountController::class, 'platforms']);
    Route::get('/social-accounts', [SocialAccountController::class, 'index']);
    Route::post('/social-accounts/connect', [SocialAccountController::class, 'connect']);
    Route::get('/social-accounts/{platform}/authorize', [SocialAccountController::class, 'authorize']);
    Route::patch('/social-accounts/{socialAccount}', [SocialAccountController::class, 'update']);
    Route::post('/social-accounts/{socialAccount}/refresh', [SocialAccountController::class, 'refresh']);
    Route::post('/social-accounts/{socialAccount}/disconnect', [SocialAccountController::class, 'disconnect']);

    Route::apiResource('publishing-profiles', PublishingProfileController::class)->except(['show']);

    Route::get('/social-posts', [SocialPostController::class, 'index']);
    Route::post('/social-posts', [SocialPostController::class, 'store']);
    Route::post('/social-posts/bulk-reschedule', [SocialPostController::class, 'bulkReschedule']);
    Route::post('/social-posts/bulk-move-channel', [SocialPostController::class, 'bulkMoveChannel']);
    Route::post('/social-posts/bulk-delete', [SocialPostController::class, 'bulkDelete']);
    Route::get('/social-posts/{socialPost}', [SocialPostController::class, 'show']);
    Route::patch('/social-posts/{socialPost}', [SocialPostController::class, 'update']);
    Route::post('/social-posts/{socialPost}/regenerate-schedule', [SocialPostController::class, 'regenerateSchedule']);
    Route::post('/social-posts/{socialPost}/retry', [SocialPostController::class, 'retry']);
    Route::post('/social-posts/{socialPost}/retry-thumbnail', [SocialPostController::class, 'retryThumbnail']);
    Route::post('/social-posts/{socialPost}/publish-now', [SocialPostController::class, 'publishNow']);
    Route::delete('/social-posts/{socialPost}', [SocialPostController::class, 'destroy']);

    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::get('/projects', [AdminController::class, 'projects']);
        Route::get('/processing-jobs', [AdminController::class, 'processingJobs']);
        Route::get('/ai-request-logs', [AdminController::class, 'aiRequestLogs']);
        Route::get('/settings', [AdminController::class, 'settings']);
        Route::patch('/settings', [AdminController::class, 'updateSettings']);

        Route::get('/backups', [BackupController::class, 'index']);
        Route::post('/backups', [BackupController::class, 'store']);
        Route::get('/backups/{filename}/download', [BackupController::class, 'download']);
        Route::delete('/backups/{filename}', [BackupController::class, 'destroy']);

        Route::post('/template-categories', [TemplateCategoryController::class, 'store']);
        Route::patch('/template-categories/{templateCategory}', [TemplateCategoryController::class, 'update']);
        Route::delete('/template-categories/{templateCategory}', [TemplateCategoryController::class, 'destroy']);

        Route::post('/templates', [TemplateController::class, 'store']);
        Route::patch('/templates/{template}', [TemplateController::class, 'update']);
        Route::post('/templates/{template}/duplicate', [TemplateController::class, 'duplicate']);
        Route::post('/templates/{template}/generate-thumbnail', [TemplateController::class, 'generateThumbnail']);
        Route::post('/templates/{template}/generate-preview', [TemplateController::class, 'generatePreview']);
        Route::post('/templates/{template}/archive', [TemplateController::class, 'archive']);
        Route::delete('/templates/{template}', [TemplateController::class, 'destroy']);

        // research_sources is a global registry shared by every user's channels, so
        // enabling/configuring/testing a source is admin-only — same treatment as
        // templates and app settings above. Per-channel source configuration is NOT
        // admin-only; it lives on the channel (PATCH /content-channels/{id}).
        Route::patch('/research/sources/{researchSource}', [ResearchSourceController::class, 'update']);
        Route::post('/research/sources/{researchSource}/test', [ResearchSourceController::class, 'test']);

        Route::post('/cover-templates', [CoverTemplateController::class, 'store']);
        Route::patch('/cover-templates/{coverTemplate}', [CoverTemplateController::class, 'update']);
        Route::post('/cover-templates/{coverTemplate}/duplicate', [CoverTemplateController::class, 'duplicate']);
        Route::post('/cover-templates/{coverTemplate}/generate-thumbnail', [CoverTemplateController::class, 'generateThumbnail']);
        Route::post('/cover-templates/{coverTemplate}/archive', [CoverTemplateController::class, 'archive']);
        Route::delete('/cover-templates/{coverTemplate}', [CoverTemplateController::class, 'destroy']);
    });
});
