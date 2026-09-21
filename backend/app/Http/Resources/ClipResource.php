<?php

namespace App\Http\Resources;

use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project_title' => $this->whenLoaded('project', fn () => $this->project?->title),
            'video_id' => $this->video_id,
            'clip_candidate_id' => $this->clip_candidate_id,
            'template' => TemplateResource::make($this->whenLoaded('template')),
            'template_version_id' => $this->template_version_id,
            'title' => $this->title,
            'caption' => $this->caption,
            'hashtags' => $this->hashtags ?? [],
            'start_time' => (float) $this->start_time,
            'end_time' => (float) $this->end_time,
            'duration' => (float) $this->duration,
            'speed' => (float) $this->speed,
            'volume' => (float) $this->volume,
            'aspect_ratio' => $this->aspect_ratio,
            'segments' => $this->segments,
            'additional_video_clips' => $this->additional_video_clips,
            'crop_config' => $this->crop_config,
            'scenes' => $this->scenes,
            'subtitle_language' => $this->subtitle_language,
            'subtitles_enabled' => $this->subtitles_enabled,
            'subtitle_config' => $this->subtitle_config,
            'custom_subtitle_format' => $this->custom_subtitle_path
                ? strtolower(pathinfo($this->custom_subtitle_path, PATHINFO_EXTENSION))
                : null,
            'caption_cues' => $this->caption_cues,
            'layer_overrides' => $this->layer_overrides,
            'reaction_layout' => $this->reaction_layout,
            'reaction_script' => $this->reaction_script,
            'reaction_tone' => $this->reaction_tone,
            'intro_enabled' => (bool) $this->intro_enabled,
            'outro_enabled' => (bool) $this->outro_enabled,
            'intro_voice' => $this->intro_voice,
            'reference_url' => $this->reference_url,
            'status' => $this->status,
            // Actually posted to at least one platform — set via ->withExists() in
            // ClipController::index(); falls back to a live query (e.g. ClipController::
            // show()/update() don't attach it) so this is never silently wrong, just
            // occasionally an extra query.
            'is_published' => $this->is_published ?? $this->socialPosts()->where('status', 'published')->exists(),
            'video_title' => $this->whenLoaded('video', fn () => $this->video?->title),
            // Free-text uploader/channel name captured at import time — see the
            // `source_channel` filter in ClipController::index() for why this
            // isn't a foreign key.
            'source_channel' => $this->whenLoaded('video', fn () => $this->video?->channelName()),
            'published_platforms' => $this->whenLoaded('socialPosts', fn () => $this->socialPosts
                ->where('status', 'published')->pluck('platform')->unique()->values()),
            // One entry per published/scheduled post — the specific YouTube channel /
            // Facebook Page / etc a clip went (or will go) to, not just the platform
            // name (a user can have several connected accounts on the same platform).
            'published_targets' => $this->whenLoaded('socialPosts', fn () => $this->socialPosts
                ->where('status', 'published')
                ->map(fn ($post) => [
                    'social_account_id' => $post->social_account_id,
                    'platform' => $post->platform,
                    'account_name' => $post->socialAccount?->account_name,
                ])->values()),
            'scheduled_targets' => $this->whenLoaded('socialPosts', fn () => $this->socialPosts
                ->where('status', 'scheduled')
                ->map(fn ($post) => [
                    'social_account_id' => $post->social_account_id,
                    'platform' => $post->platform,
                    'account_name' => $post->socialAccount?->account_name,
                ])->values()),
            'progress' => $this->progress,
            'failure_reason' => $this->failure_reason,
            'url' => Media::url($this->output_path),
            // Cover-first, raw-frame fallback — see Clip::displayThumbnailPath().
            // `cover_url` below stays the raw cover_path (nullable) for the Cover
            // tab, which needs to know whether one actually exists.
            'thumbnail_url' => Media::url($this->displayThumbnailPath()),
            'cover_template_id' => $this->cover_template_id,
            'cover_template' => CoverTemplateResource::make($this->whenLoaded('coverTemplate')),
            'cover_text' => $this->cover_text,
            'cover_kicker' => $this->cover_kicker,
            'cover_subline' => $this->cover_subline,
            'cover_url' => Media::url($this->cover_path),
            // Short thumbnail-text variants the analysis produced for this
            // moment, offered as one-click alternatives in the Cover panel.
            'cover_title_options' => $this->whenLoaded('clipCandidate', fn () => $this->clipCandidate?->cover_titles ?? []),
            'cover_subtitle_options' => $this->whenLoaded('clipCandidate', fn () => $this->clipCandidate?->cover_subtitles ?? []),
            'srt_url' => $this->whenLoaded('subtitle', fn () => Media::url($this->subtitle?->srt_path)),
            'output_size_bytes' => $this->output_size_bytes,
            'rendered_at' => $this->rendered_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
