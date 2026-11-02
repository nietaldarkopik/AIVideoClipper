<?php

namespace App\Services\Assistant;

use App\Services\Assistant\ToolDefinition as T;

/**
 * Every tool the assistant may use, each mapped onto an existing API route.
 *
 * This list is an ALLOWLIST: a route that isn't here cannot be reached by the model, and
 * only the parameters declared per tool are forwarded (see ToolExecutor) — so it cannot
 * smuggle extra body fields into an update either. Deliberately absent: anything admin,
 * and social-account `connect` (it takes an Instagram password; credentials must never
 * pass through a chat). Connecting a platform goes through get_social_connect_link, which
 * only hands the user an authorization URL to open themselves.
 */
class ToolCatalog
{
    /** @var array<string, ToolDefinition>|null */
    private ?array $tools = null;

    /** @return array<string, ToolDefinition> */
    public function all(): array
    {
        return $this->tools ??= $this->build();
    }

    public function find(string $name): ?ToolDefinition
    {
        return $this->all()[$name] ?? null;
    }

    /** OpenAI-format tool list sent with every model call. */
    public function functionSchemas(): array
    {
        return array_values(array_map(fn (ToolDefinition $t) => $t->toFunctionSchema(), $this->all()));
    }

    /** @return array<string, ToolDefinition> */
    private function build(): array
    {
        $defs = [
            // ---------------------------------------------------------------- read
            new T('get_overview', 'Dashboard overview: totals and recent projects.', T::RISK_READ, 'GET', '/dashboard'),
            new T('get_analytics', 'Performance of published posts over the last N days: totals (views, likes, comments, shares, engagement rate), per-platform breakdown, top posts and a daily timeseries.', T::RISK_READ, 'GET', '/analytics', [
                'days' => self::int('Window in days (1-365). Default 30.'),
            ]),
            new T('list_projects', 'List the user\'s projects (a project holds one source video and its clips).', T::RISK_READ, 'GET', '/projects', [
                'search' => self::str('Search in the title.'),
                'status' => self::str('Filter by status, e.g. completed, failed, processing, draft.'),
                'per_page' => self::int('Items per page (max 25). Default 15.'),
            ], '', ['id', 'title', 'status', 'origin_type', 'clips_count', 'clip_candidates_count', 'failure_reason', 'last_edited_at', 'created_at']),
            new T('get_project', 'Details of one project.', T::RISK_READ, 'GET', '/projects/{project_id}', [
                'project_id' => self::int('Project id.', true),
            ]),
            new T('list_clips', 'List the user\'s rendered clips with filters.', T::RISK_READ, 'GET', '/clips', [
                'project_id' => self::int('Only clips of this project.'),
                'status' => self::str('queued, rendering, completed or failed.'),
                'published' => self::bool('true = only clips already published somewhere; false = never published.'),
                'platform' => self::str('Only clips published on this platform.'),
                'aspect_ratio' => self::str('9:16, 1:1 or 16:9.'),
                'per_page' => self::int('Items per page (max 25). Default 15.'),
            ], '', ['id', 'project_id', 'project_title', 'title', 'caption', 'hashtags', 'duration', 'aspect_ratio', 'status', 'is_published', 'published_platforms', 'scheduled_targets', 'created_at']),
            new T('search_clips', 'Semantic/keyword search across the user\'s clips.', T::RISK_READ, 'GET', '/clips/search', [
                'q' => self::str('What to look for.', true),
            ], '', ['id', 'project_id', 'project_title', 'title', 'caption', 'hashtags', 'duration', 'status', 'is_published']),
            new T('get_clip', 'Details of one clip (title, caption, hashtags, status, where it is published/scheduled).', T::RISK_READ, 'GET', '/clips/{clip_id}', [
                'clip_id' => self::int('Clip id.', true),
            ], '', [], ['segments', 'additional_video_clips', 'crop_config', 'scenes', 'subtitle_config', 'caption_cues', 'layer_overrides', 'layers']),
            new T('list_social_accounts', 'The user\'s connected social media accounts (platform, name, connection status, whether the token still works).', T::RISK_READ, 'GET', '/social-accounts', [], '',
                ['id', 'platform', 'account_name', 'username', 'status', 'token_status', 'auto_publish_enabled', 'last_synced_at']),
            new T('list_social_platforms', 'Which social platforms this app can connect to.', T::RISK_READ, 'GET', '/social-accounts/platforms'),
            new T('get_social_connect_link', 'Get the authorization URL for connecting a social platform account. Give the URL to the user so THEY open it and approve access; you cannot complete the login yourself.', T::RISK_READ, 'GET', '/social-accounts/{platform}/authorize', [
                'platform' => self::str('Platform key, e.g. youtube, facebook, tiktok. See list_social_platforms.', true),
            ]),
            new T('list_social_posts', 'List posts (published, scheduled, failed...) with their metrics.', T::RISK_READ, 'GET', '/social-posts', [
                'status' => self::str('ready, scheduled, uploading, publishing, retrying, published, failed or cancelled.'),
                'platform' => self::str('Platform key.'),
                'clip_id' => self::int('Only posts of this clip.'),
                'social_account_id' => self::int('Only posts to this account.'),
                'scheduled_from' => self::str('ISO date/time lower bound for scheduled_at.'),
                'scheduled_to' => self::str('ISO date/time upper bound for scheduled_at.'),
                'per_page' => self::int('Items per page (max 25). Default 24.'),
            ], '', ['id', 'clip_id', 'platform', 'social_account', 'title', 'status', 'scheduled_at', 'published_at', 'post_url', 'error_message', 'metrics']),
            new T('get_social_post', 'Details of one social post.', T::RISK_READ, 'GET', '/social-posts/{social_post_id}', [
                'social_post_id' => self::int('Social post id.', true),
            ]),
            new T('list_publishing_profiles', 'Saved groups of accounts to publish to together.', T::RISK_READ, 'GET', '/publishing-profiles'),
            new T('list_content_channels', 'The user\'s research channels (brand/persona the daily content ideas are generated for).', T::RISK_READ, 'GET', '/content-channels', [], '',
                ['id', 'name', 'handle', 'platform', 'niche', 'is_active', 'last_research_at', 'next_research_at', 'ideas_count']),
            new T('list_content_ideas', 'Content ideas produced by research, best priority first.', T::RISK_READ, 'GET', '/content-ideas', [
                'content_channel_id' => self::int('Only ideas of this research channel.'),
                'status' => self::str('idea, selected, scripting, draft, approved, published or rejected.'),
                'q' => self::str('Search text.'),
                'min_priority' => self::int('Minimum priority score.'),
                'per_page' => self::int('Items per page (max 25).'),
            ], '', ['id', 'content_channel_id', 'title', 'topic', 'short_description', 'priority_score', 'suggested_content_type', 'status', 'research_date']),
            new T('get_content_idea', 'Full details of one content idea.', T::RISK_READ, 'GET', '/content-ideas/{content_idea_id}', [
                'content_idea_id' => self::int('Content idea id.', true),
            ]),
            new T('list_research_runs', 'History of research runs.', T::RISK_READ, 'GET', '/research/runs', [
                'content_channel_id' => self::int('Only runs of this research channel.'),
                'status' => self::str('Run status.'),
                'per_page' => self::int('Items per page (max 25).'),
            ], '', ['id', 'channel', 'trigger', 'status', 'topics_found', 'ideas_generated', 'providers_failed', 'error_message', 'created_at']),
            new T('list_content_briefs', 'Researched topic briefs with generated video scripts.', T::RISK_READ, 'GET', '/content-briefs', [
                'per_page' => self::int('Items per page (max 25).'),
            ], '', ['id', 'topic', 'status', 'narrative_title', 'narrative_suggested_description', 'created_at']),
            new T('get_content_brief', 'One brief including its generated script.', T::RISK_READ, 'GET', '/content-briefs/{content_brief_id}', [
                'content_brief_id' => self::int('Brief id.', true),
            ], '', [], ['sources', 'candidate_videos']),
            new T('list_cartoon_videos', 'Cartoon explainer videos made from ideas/briefs.', T::RISK_READ, 'GET', '/cartoon-videos', [
                'per_page' => self::int('Items per page (max 25).'),
            ], '', ['id', 'title', 'status', 'progress', 'source_type', 'source_id', 'duration_seconds', 'clip_id', 'created_at']),
            new T('get_cartoon_video', 'One cartoon video with its scenes.', T::RISK_READ, 'GET', '/cartoon-videos/{cartoon_video_id}', [
                'cartoon_video_id' => self::int('Cartoon video id.', true),
            ], '', [], ['image_url', 'clip_url', 'url', 'thumbnail_url']),
            new T('get_trending', 'Currently trending videos/topics per platform.', T::RISK_READ, 'GET', '/trending'),
            new T('list_facebook_messages', 'Inbox messages synced from connected Facebook pages.', T::RISK_READ, 'GET', '/facebook-messages', [
                'status' => self::str('Message status.'),
                'per_page' => self::int('Items per page (max 25).'),
            ], '', ['id', 'page_name', 'sender_name', 'message', 'sent_at', 'status', 'content_idea_id']),
            new T('list_video_batches', 'Bulk video import batches.', T::RISK_READ, 'GET', '/video-batches', [], '',
                ['id', 'name', 'status', 'total_items', 'completed_items', 'failed_items', 'created_at']),

            // --------------------------------------------------------------- write
            new T('update_clip', 'Edit a clip\'s title, caption, hashtags or cover text. Only the fields you pass change.', T::RISK_WRITE, 'PATCH', '/clips/{clip_id}', [
                'clip_id' => self::int('Clip id.', true),
                'title' => self::str('New title.'),
                'caption' => self::str('New caption/description.'),
                'hashtags' => self::strArray('Full hashtag list, replacing the old one (without the # sign).'),
                'cover_text' => self::str('Cover headline (max 120 chars).'),
                'cover_kicker' => self::str('Cover kicker (max 30 chars).'),
                'cover_subline' => self::str('Cover subline (max 40 chars).'),
            ], 'Ubah clip #{clip_id}: judul={title}; caption={caption}; hashtag={hashtags}'),
            new T('generate_clip_social_metadata', 'Have the app\'s AI write platform-specific titles/captions/hashtags for a clip (uses AI credits).', T::RISK_WRITE, 'POST', '/clips/{clip_id}/generate-social-metadata', [
                'clip_id' => self::int('Clip id.', true),
                'platforms' => self::strArray('Subset of tiktok, youtube, instagram, facebook, twitter, linkedin. Default: all.'),
            ], 'Buat metadata sosmed untuk clip #{clip_id} ({platforms})'),
            new T('create_project', 'Create an empty project.', T::RISK_WRITE, 'POST', '/projects', [
                'title' => self::str('Project title.', true),
                'description' => self::str('Optional description.'),
            ], 'Buat project "{title}"'),
            new T('update_content_idea', 'Change an idea\'s status, notes or title.', T::RISK_WRITE, 'PATCH', '/content-ideas/{content_idea_id}', [
                'content_idea_id' => self::int('Content idea id.', true),
                'status' => self::str('idea, selected, scripting, draft, approved, published or rejected.'),
                'notes' => self::str('Notes.'),
                'title' => self::str('New title.'),
            ], 'Ubah ide #{content_idea_id}: status={status}; catatan={notes}; judul={title}'),
            new T('create_content_brief', 'Start researching a topic and writing a video script for it (searches the web, uses AI credits, takes minutes).', T::RISK_WRITE, 'POST', '/content-briefs', [
                'topic' => self::str('The topic to research.', true),
            ], 'Riset topik & buat naskah: "{topic}"'),
            new T('run_research', 'Run a research pass now for a research channel to generate new content ideas.', T::RISK_WRITE, 'POST', '/content-channels/{content_channel_id}/research-runs', [
                'content_channel_id' => self::int('Research channel id.', true),
            ], 'Jalankan riset untuk channel riset #{content_channel_id}'),
            new T('create_cartoon_video', 'Start a cartoon explainer video (storyboard first; rendering is a separate step) from a content idea or brief.', T::RISK_WRITE, 'POST', '/cartoon-videos', [
                'source_type' => self::enum(['content_idea', 'content_brief'], 'What it is made from.', true),
                'source_id' => self::int('Id of that idea/brief.', true),
            ], 'Buat video kartun dari {source_type} #{source_id}'),
            new T('render_cartoon_video', 'Render (or re-render failed scenes of) a cartoon video whose storyboard is ready. May spend image/video AI credits.', T::RISK_WRITE, 'POST', '/cartoon-videos/{cartoon_video_id}/render', [
                'cartoon_video_id' => self::int('Cartoon video id.', true),
            ], 'Render video kartun #{cartoon_video_id}'),
            new T('update_social_post', 'Edit an unpublished post\'s title/caption/hashtags or change its scheduled time (must be in the future).', T::RISK_WRITE, 'PATCH', '/social-posts/{social_post_id}', [
                'social_post_id' => self::int('Social post id.', true),
                'title' => self::str('New title.'),
                'caption' => self::str('New caption.'),
                'hashtags' => self::strArray('Full hashtag list.'),
                'scheduled_at' => self::str('New ISO date/time in the future, or empty to unschedule.'),
            ], 'Ubah post #{social_post_id}: judul={title}; caption={caption}; jadwal={scheduled_at}'),
            new T('bulk_reschedule_social_posts', 'Re-spread several unpublished posts over new times automatically.', T::RISK_WRITE, 'POST', '/social-posts/bulk-reschedule', [
                'social_post_ids' => self::intArray('Social post ids.', true),
                'min_minutes' => self::int('Minimum gap between posts in minutes.'),
                'max_minutes' => self::int('Maximum gap in minutes.'),
                'max_per_day' => self::int('Max posts per account per day.'),
                'window_start_hour' => self::int('Earliest hour of day (0-23).'),
                'window_end_hour' => self::int('Latest hour of day (1-24).'),
            ], 'Jadwal ulang otomatis post {social_post_ids}'),
            new T('sync_social_post_metrics', 'Refresh a post\'s views/likes from the platform.', T::RISK_WRITE, 'POST', '/social-posts/{social_post_id}/sync-metrics', [
                'social_post_id' => self::int('Social post id.', true),
            ], 'Sinkronkan metrik post #{social_post_id}'),

            // ------------------------------------------------------------ outbound
            new T('publish_clip', 'Publish a clip to social accounts, now or at a scheduled time. This puts content on public platforms.', T::RISK_OUTBOUND, 'POST', '/social-posts', [
                'clip_id' => self::int('Clip id (must be fully rendered).', true),
                'social_account_ids' => self::intArray('Connected account ids to post to.', true),
                'scheduled_at' => self::str('ISO date/time to publish at. Omit to publish as soon as possible.'),
                'publishing_profile_id' => self::int('Optionally add all accounts of this profile.'),
            ], 'POSTING clip #{clip_id} ke akun {social_account_ids}; jadwal={scheduled_at}'),
            new T('schedule_clips_bulk', 'Schedule several clips across accounts, spaced out automatically from a start time.', T::RISK_OUTBOUND, 'POST', '/clips/bulk-schedule', [
                'clip_ids' => self::intArray('Clip ids.', true),
                'social_account_ids' => self::intArray('Account ids.', true),
                'scheduled_at' => self::str('ISO start date/time.', true),
                'gap_min_minutes' => self::int('Minimum gap between posts, minutes.'),
                'gap_max_minutes' => self::int('Maximum gap, minutes.'),
            ], 'JADWALKAN clip {clip_ids} ke akun {social_account_ids} mulai {scheduled_at}'),
            new T('publish_social_post_now', 'Publish an existing scheduled/ready post immediately.', T::RISK_OUTBOUND, 'POST', '/social-posts/{social_post_id}/publish-now', [
                'social_post_id' => self::int('Social post id.', true),
            ], 'POSTING SEKARANG post #{social_post_id}'),
            new T('retry_social_post', 'Retry a failed post.', T::RISK_OUTBOUND, 'POST', '/social-posts/{social_post_id}/retry', [
                'social_post_id' => self::int('Social post id.', true),
            ], 'Coba posting ulang post #{social_post_id}'),

            // --------------------------------------------------------- destructive
            new T('delete_clip', 'Permanently delete a clip.', T::RISK_DESTRUCTIVE, 'DELETE', '/clips/{clip_id}', [
                'clip_id' => self::int('Clip id.', true),
            ], 'HAPUS clip #{clip_id}'),
            new T('delete_social_post', 'Delete a social post record (and cancel it if scheduled).', T::RISK_DESTRUCTIVE, 'DELETE', '/social-posts/{social_post_id}', [
                'social_post_id' => self::int('Social post id.', true),
            ], 'HAPUS post #{social_post_id}'),
            new T('delete_project', 'Permanently delete a project and its clips.', T::RISK_DESTRUCTIVE, 'DELETE', '/projects/{project_id}', [
                'project_id' => self::int('Project id.', true),
            ], 'HAPUS project #{project_id}'),
            new T('delete_content_brief', 'Delete a content brief.', T::RISK_DESTRUCTIVE, 'DELETE', '/content-briefs/{content_brief_id}', [
                'content_brief_id' => self::int('Brief id.', true),
            ], 'HAPUS brief #{content_brief_id}'),
            new T('delete_cartoon_video', 'Delete a cartoon video.', T::RISK_DESTRUCTIVE, 'DELETE', '/cartoon-videos/{cartoon_video_id}', [
                'cartoon_video_id' => self::int('Cartoon video id.', true),
            ], 'HAPUS video kartun #{cartoon_video_id}'),
        ];

        $byName = [];
        foreach ($defs as $def) {
            $byName[$def->name] = $def;
        }

        return $byName;
    }

    private static function int(string $description, bool $required = false): array
    {
        return ['type' => 'integer', 'description' => $description, 'required' => $required];
    }

    private static function str(string $description, bool $required = false): array
    {
        return ['type' => 'string', 'description' => $description, 'required' => $required];
    }

    private static function bool(string $description): array
    {
        return ['type' => 'boolean', 'description' => $description];
    }

    private static function enum(array $values, string $description, bool $required = false): array
    {
        return ['type' => 'string', 'enum' => $values, 'description' => $description, 'required' => $required];
    }

    private static function intArray(string $description, bool $required = false): array
    {
        return ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => $description, 'required' => $required];
    }

    private static function strArray(string $description): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string'], 'description' => $description];
    }
}
