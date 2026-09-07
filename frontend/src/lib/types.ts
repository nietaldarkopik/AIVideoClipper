export interface User {
  id: number;
  name: string;
  email: string;
  role: "user" | "admin";
  created_at: string;
}

export type ProjectStatus =
  | "draft"
  | "uploading"
  | "processing"
  | "transcribing"
  | "analyzing"
  | "generating_clips"
  | "rendering"
  | "completed"
  | "failed";

export interface Video {
  id: number;
  project_id: number;
  source_type: string;
  source_url: string | null;
  title: string | null;
  original_filename: string | null;
  url: string | null;
  thumbnail_url: string | null;
  // Null when the video was imported before this feature, generation failed,
  // or (waveform only) the source has no audio track — TimelineTrack falls
  // back to a plain bar. tile_count is the fixed number of frames tiled into
  // thumbnail_strip_url (see FFmpegService::THUMBNAIL_STRIP_TILE_COUNT).
  thumbnail_strip_url: string | null;
  thumbnail_strip_tile_count: number;
  waveform_url: string | null;
  duration_seconds: number | null;
  width: number | null;
  height: number | null;
  resolution: string | null;
  file_size_bytes: number | null;
  status: string;
  failure_reason: string | null;
  has_transcript: boolean | null;
  created_at: string;
  updated_at: string;
}

export interface Project {
  id: number;
  title: string;
  description: string | null;
  status: ProjectStatus;
  failure_reason: string | null;
  video: Video | null;
  // Every video in the project, not just the latest — only populated when
  // fetched via GET /projects/{id} (the list view still only loads the
  // latest). Used by the clip editor's "additional video clips" picker.
  videos?: Video[];
  clip_candidates_count?: number;
  clips_count?: number;
  clip_candidates?: ClipCandidate[];
  clips?: Clip[];
  last_edited_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface ClipScores {
  overall: number;
  engagement: number;
  hook: number;
  story: number;
  emotional: number;
  information: number;
  viral_potential: number;
}

export interface ClipCandidate {
  id: number;
  project_id: number;
  video_id: number;
  start_time: number;
  end_time: number;
  duration: number;
  scores: ClipScores;
  hook_text: string;
  moment_type: string;
  reasons: string[];
  explanation: string;
  suggested_title: string;
  suggested_caption: string;
  suggested_hashtags: string[];
  status: "pending" | "generated" | "dismissed";
  clip_id: number | null;
}

export type ClipStatus = "queued" | "rendering" | "completed" | "failed";

export type ReactionLayout = "pip_bottom_right" | "pip_bottom_left" | "split_top_bottom" | "split_side_by_side";

// One cut range in a multi-segment ("jump cut") selection — absolute source-video
// seconds. clips.segments is a list of these; null/one-entry means "plain trim",
// matching start_time/end_time exactly (see RenderClipJob::resolveSegments()).
export type TransitionType = "fade" | "dissolve";

// A crossfade FROM whichever segment ends up immediately before this one (once
// segments are sorted by start — see RenderClipJob::resolveSegments()) INTO this
// one. Meaningless on a clip's first segment (nothing precedes it) — the backend
// silently ignores it there rather than rejecting it, since validation has no
// way to know in advance which segment will end up first. null/absent is a hard
// cut, the only behavior before this feature. See
// FFmpegService::extractWithoutSilence()'s $transitions param.
export interface TransitionIn {
  type: TransitionType;
  duration: number;
}

export interface Segment {
  start: number;
  end: number;
  transition_in?: TransitionIn | null;
}

// A whole extra video appended AFTER the main clip's own segments, cut from a
// DIFFERENT Video in the same project — see
// RenderClipJob::renderAdditionalVideoClips(). start/end are SOURCE-time on
// THAT video (not the main clip's video). transition_in is the crossfade FROM
// whatever precedes this entry (the main clip's own output, or the previous
// additional clip) INTO it — same shape and convention as Segment's own.
export interface AdditionalVideoClip {
  video_id: number;
  start: number;
  end: number;
  transition_in?: TransitionIn | null;
}

// A manual crop keyframe — SOURCE VIDEO PIXEL coordinates (not fractions, unlike
// template layers), envelope-relative time. Mirrors what ReframingProvider's
// smart-crop keyframes already look like; see FFmpegService::buildCropSegments().
export interface CropKeyframe {
  time: number;
  x: number;
  y: number;
  width: number;
  height: number;
}

export interface CropConfig {
  mode: "smart" | "manual";
  keyframes: CropKeyframe[];
}

// Read-only — from Subtitle.segments (populated after a render). Clip-relative
// seconds. `words` is empty for cues sourced from a custom uploaded .srt/.ass
// (no word-level timing available there), populated for auto-generated captions.
export interface SubtitleCueWord {
  word: string;
  start: number;
  end: number;
}

export interface SubtitleCue {
  start: number;
  end: number;
  text: string;
  words: SubtitleCueWord[];
}

export interface Clip {
  id: number;
  project_id: number;
  video_id: number;
  clip_candidate_id: number | null;
  template: Template | null;
  template_version_id: number | null;
  cover_template_id: number | null;
  cover_template: CoverTemplate | null;
  cover_text: string | null;
  cover_kicker: string | null;
  cover_subline: string | null;
  cover_url: string | null;
  // Short thumbnail-text variants written by the clip analysis (only present
  // when the clip's candidate is loaded — see ClipResource).
  cover_title_options?: string[];
  cover_subtitle_options?: string[];
  title: string | null;
  caption: string | null;
  hashtags: string[];
  start_time: number;
  end_time: number;
  duration: number;
  speed: number;
  volume: number;
  aspect_ratio: "9:16" | "1:1" | "16:9";
  crop_config: CropConfig | null;
  scenes: unknown[] | null;
  subtitle_language: string;
  subtitles_enabled: boolean;
  subtitle_config: Record<string, unknown> | null;
  custom_subtitle_format: "srt" | "ass" | null;
  // Hand-edited caption cues (clip-relative seconds). Null = captions are still
  // regenerated from the transcript on every render, the pre-editing behavior.
  // The editor reads its working copy from /preview-config's subtitle_cues,
  // which already prefers these over the last render's transcript-derived ones.
  caption_cues: SubtitleCue[] | null;
  layer_overrides: LayerOverrides | null;
  segments: Segment[] | null;
  additional_video_clips: AdditionalVideoClip[] | null;
  reaction_layout: ReactionLayout | null;
  reaction_script: string | null;
  reaction_tone: "positive" | "satire" | null;
  intro_enabled: boolean;
  outro_enabled: boolean;
  intro_voice: string | null;
  reference_url: string | null;
  status: ClipStatus;
  progress: number | null;
  failure_reason: string | null;
  url: string | null;
  thumbnail_url: string | null;
  srt_url?: string | null;
  output_size_bytes: number | null;
  rendered_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface TemplateCategory {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  is_custom: boolean;
  templates_count?: number;
}

export interface TemplateVersion {
  id: number;
  template_id: number;
  version_number: number;
  label: string;
  is_published: boolean;
  config: TemplateConfig;
  created_at: string;
}

// A single entry in template_versions.config.layers (config version >= 2) — see
// LayerCompositionService on the backend for how each type turns into an FFmpeg
// filter. x/y/width/height are 0..1 fractions of the target resolution.
export type LayerType =
  | "text"
  | "image"
  | "logo"
  | "pip_video"
  | "audio"
  | "progress_bar"
  | "rect"
  // Timed pixel transforms of the footage — one FFmpeg implementation
  // (LayerCompositionService::buildColorLayer()), two editor affordances:
  // "effect" is a single timed adjustment, "filter" a named colour-grade preset
  // that normally spans the whole clip. Both render BEFORE the caption burn-in
  // regardless of z_index, so neither grades the captions or overlays — see
  // FFmpegService::partitionLayersAroundCaption().
  | "effect"
  | "filter";

export interface LayerTiming {
  start: number;
  end: number | null;
}

export interface TextLayerProps {
  content?: string;
  font?: string;
  font_file?: string;
  font_size?: number;
  color?: string;
  align?: "left" | "center" | "right";
  stroke_color?: string;
  stroke_width?: number;
  background?: string;
  background_opacity?: number;
}

export interface ImageLayerProps {
  image_path?: string;
}

export interface AudioLayerProps {
  audio_path?: string;
  volume?: number;
  fade_in?: number;
  fade_out?: number;
  // Waveform PNG generated beside the uploaded audio (see
  // MediaUploadController::makeWaveform) and drawn behind the layer's block on
  // the timeline. Purely cosmetic and always optional — audio picked by typing a
  // path, or whose waveform pass failed, simply has none.
  waveform_path?: string | null;
}

/**
 * One entry in the built-in sticker set (GET /api/stickers). Picking one adds an
 * ordinary image layer pointing at `path` — there is no separate sticker layer
 * type, on either side of the wire. See StickerLibrary.
 */
export interface Sticker {
  path: string;
  url: string;
  name: string;
  shape: string;
  color: string;
}

/**
 * An asset in the user's media library — what image/logo layers' `image_path`
 * and audio layers' `audio_path` point at. See MediaUploadController; `path` is
 * disk-relative (the value stored on the layer), `url` is servable directly.
 */
export interface MediaUpload {
  path: string;
  url: string;
  kind: "image" | "audio";
  name: string;
  size_bytes: number;
  uploaded_at: string;
  waveform_path: string | null;
  waveform_url: string | null;
}

export interface ProgressBarLayerProps {
  color?: string;
  background_color?: string;
  height_px?: number;
  position?: "top" | "bottom";
}

export interface RectLayerProps {
  color?: string;
}

// The five timed effects and six grade presets FFmpeg actually implements —
// keep these in sync with LayerCompositionService::buildColorLayer()'s match()
// and with EFFECTS/FILTERS in @/lib/videoFx (which mirrors each one as a CSS
// approximation for the preview).
export type EffectName = "blur" | "grayscale" | "brightness" | "contrast" | "vignette";
export type FilterPreset = "normal" | "warm" | "cool" | "bw" | "vintage" | "high_contrast";

export interface EffectLayerProps {
  effect?: EffectName;
  // 0..1 — 1.0 is the effect at full strength, 0 renders nothing at all.
  intensity?: number;
}

export interface FilterLayerProps {
  preset?: FilterPreset;
  intensity?: number;
}

export interface TemplateLayer {
  id: string;
  type: LayerType;
  z_index: number;
  x?: number;
  y?: number;
  width?: number | null;
  height?: number | null;
  opacity?: number;
  // Degrees clockwise, about the layer's own centre. Only image/logo layers act
  // on it today (LayerCompositionService::buildImageLayer) — mainly for
  // stickers, which look placed rather than pasted when they're slightly
  // turned. Absent/0 on every layer that predates it.
  rotation?: number;
  timing?: LayerTiming;
  props?:
    | TextLayerProps
    | ImageLayerProps
    | AudioLayerProps
    | ProgressBarLayerProps
    | RectLayerProps
    | EffectLayerProps
    | FilterLayerProps
    | Record<string, unknown>;
}

/**
 * What the editor currently has selected, across every kind of thing the
 * timeline can hold. One selection state (rather than one per track type) is
 * what lets the inspector, the timeline and the video preview always agree on
 * what's being edited — selecting a caption block has to be able to *deselect*
 * a text layer, which two independent states can't express.
 */
export type EditorSelection =
  | { kind: "layer"; id: string }
  | { kind: "caption"; index: number }
  | { kind: "segment"; index: number }
  // The crossfade INTO segments[index] from segments[index - 1] — index is
  // always >= 1 (a first segment has nothing before it to transition from).
  | { kind: "transition"; index: number }
  | null;

// Full-width-band shorthand the Template editor UI exposes for
// FFmpegService::renderClip()'s $videoRegion param — x/width are implicitly 0/1;
// only the vertical band is user-configurable in this pass. null = full-bleed
// video (every template before this feature, and the default for a new one).
export interface VideoRegion {
  top: number;
  height: number;
}

// Per-clip patch over a template's layers — see LayerOverrideMerger on the
// backend. Keyed by layer id, plus "_new" (clip-only extra layers) and
// "_removed" (hide a template layer for this clip only).
export interface LayerOverrides {
  _new?: TemplateLayer[];
  _removed?: string[];
  [layerId: string]: Partial<TemplateLayer> | TemplateLayer[] | string[] | undefined;
}

export interface TemplateConfig {
  version?: number;
  layers?: TemplateLayer[];
  caption?: {
    font?: string;
    font_size?: number | null;
    color?: string;
    highlight_color?: string;
    stroke_color?: string;
    stroke_width?: number;
    background?: string;
    background_opacity?: number;
    background_padding?: number;
    position?: "top" | "center" | "bottom";
    uppercase?: boolean;
    bold?: boolean;
    italic?: boolean;
    animation?: "none" | "fade" | "pop";
    highlight_active_word?: boolean;
    words_per_line?: number;
  };
  branding?: {
    logo_path?: string | null;
    watermark_path?: string | null;
    watermark_opacity?: number;
  };
  progress_bar?: { enabled?: boolean; color?: string; position?: string } | null;
  cta?: Record<string, unknown> | null;
  // Full x/y/width/height shape FFmpegService::renderClip() actually reads —
  // null (default) is full-bleed video. The Template editor only exposes the
  // top/height band shorthand (see VideoRegion) so x/width are always 0/1 from
  // this UI, but the field stores the general shape for forward-compatibility.
  video_region?: { x: number; y: number; width: number; height: number } | null;
  canvas_background_color?: string;
  // See FFmpegService::buildEffectFilter() — 'none' (default) leaves the frame
  // untouched; the others crop+scale a continuous zoom/drift/jitter formula onto
  // the base video, applied before captions/layers/watermark.
  effects?: {
    type?: "none" | "zoom_in" | "zoom_out" | "ken_burns" | "shake";
    intensity?: number;
  };
  // See FFmpegService::concatSegments() — only matters when a clip actually has
  // an intro/outro segment to join; 'cut' (default) is a hard cut, 'fade'
  // crossfades video+audio across the boundary over `duration` seconds.
  transition?: {
    type?: "cut" | "fade";
    duration?: number;
  };
  [key: string]: unknown;
}

export interface Template {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  thumbnail_url: string | null;
  preview_url: string | null;
  preview_status: "generating" | "ready" | "failed" | null;
  category: TemplateCategory | null;
  aspect_ratio: "9:16" | "1:1" | "16:9";
  resolution: { width: number; height: number };
  status: "draft" | "published" | "archived";
  is_system: boolean;
  current_version: TemplateVersion | null;
  versions?: TemplateVersion[];
  created_at: string;
  updated_at: string;
}

// Mirrors DefaultCoverTemplateConfig on the backend — see
// FFmpegService::renderCoverImage() for how each field is drawn, and
// CoverLivePreview for the CSS twin of that layout.
export interface CoverTemplateTextConfig {
  font_size?: number | null;
  font_scale?: number;
  color?: string;
  highlight_color?: string;
  highlight_mode?: "none" | "first_line" | "last_line" | "alternate";
  stroke_color?: string;
  stroke_width?: number;
  shadow_color?: string;
  shadow_x?: number;
  shadow_y?: number;
  block_style?: "band" | "lines" | "none";
  background?: string;
  background_opacity?: number;
  position?: "top" | "center" | "bottom";
  align?: "center" | "left";
  uppercase?: boolean;
  wrap_chars?: number;
}

export interface CoverTemplateKickerConfig {
  enabled?: boolean;
  text?: string;
  color?: string;
  background?: string;
  background_opacity?: number;
  font_scale?: number;
}

export interface CoverTemplateSublineConfig {
  enabled?: boolean;
  text?: string;
  color?: string;
  background?: string;
  background_opacity?: number;
  font_scale?: number;
}

export interface CoverTemplateBadgeConfig {
  enabled?: boolean;
  text?: string;
  color?: string;
  text_color?: string;
}

export interface CoverTemplateGradientConfig {
  enabled?: boolean;
  color?: string;
  opacity?: number;
  position?: "top" | "bottom";
  size?: number;
}

export interface CoverTemplateBackgroundConfig {
  source?: "clip_frame" | "ai_generated";
  ai_prompt?: string | null;
  overlay_color?: string;
  overlay_opacity?: number;
  gradient?: CoverTemplateGradientConfig;
}

export interface CoverTemplateConfig {
  background?: CoverTemplateBackgroundConfig;
  kicker?: CoverTemplateKickerConfig;
  text?: CoverTemplateTextConfig;
  subline?: CoverTemplateSublineConfig;
  badge?: CoverTemplateBadgeConfig;
}

export interface CoverTemplate {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  thumbnail_url: string | null;
  aspect_ratio: "9:16" | "1:1" | "16:9";
  config: CoverTemplateConfig;
  status: "draft" | "published" | "archived";
  is_system: boolean;
  created_at: string;
  updated_at: string;
}

export type SocialPlatform =
  | "tiktok"
  | "youtube"
  | "instagram"
  | "facebook"
  | "twitter"
  | "linkedin";

export interface SocialAccount {
  id: number;
  platform: SocialPlatform;
  account_name: string;
  username: string | null;
  avatar_url: string | null;
  status: "connected" | "expired" | "revoked" | "error";
  token_status: "valid" | "expired";
  permissions: string[];
  auto_publish_enabled: boolean;
  default_cover_template_id: number | null;
  default_cover_template: CoverTemplate | null;
  last_synced_at: string | null;
  created_at: string;
}

export interface PublishingProfile {
  id: number;
  name: string;
  is_default: boolean;
  social_accounts: SocialAccount[];
}

export type SocialPostStatus =
  | "ready"
  | "scheduled"
  | "uploading"
  | "publishing"
  | "published"
  | "failed"
  | "retrying"
  | "cancelled";

export interface SocialPost {
  id: number;
  clip_id: number;
  clip: {
    id: number;
    title: string | null;
    thumbnail_url: string | null;
    url: string | null;
    duration: number;
    project_id: number;
    project_title: string | null;
  } | null;
  platform: SocialPlatform;
  social_account: SocialAccount | null;
  title: string | null;
  caption: string | null;
  hashtags: string[];
  status: SocialPostStatus;
  scheduled_at: string | null;
  published_at: string | null;
  post_url: string | null;
  error_message: string | null;
  retry_count: number;
  thumbnail_status: "pending" | "uploaded" | "failed" | null;
  thumbnail_uploaded_at: string | null;
  thumbnail_error: string | null;
  metrics: Record<string, number> | null;
  metrics_synced_at: string | null;
  created_at: string;
}

export interface ProcessingJob {
  id: number;
  project_id: number;
  video_id: number | null;
  clip_id: number | null;
  type: string;
  status: "queued" | "running" | "completed" | "failed" | "cancelled";
  progress: number;
  message: string | null;
  error: string | null;
  request_payload: string | null;
  response_payload: string | null;
  started_at: string | null;
  finished_at: string | null;
}

export type AiCapability = "content_analysis" | "transcription" | "social_metadata";

export type AiProvider = "ollama" | "openai" | "claude" | "gemini" | "nine_router" | "whisper_engine";

export interface AiRequestLog {
  id: number;
  capability: AiCapability;
  provider: AiProvider;
  model: string | null;
  project: { id: number; title: string } | null;
  video: { id: number; title: string | null } | null;
  clip: { id: number; title: string | null } | null;
  prompt: string | null;
  response: string | null;
  status: "success" | "failed";
  error_message: string | null;
  duration_ms: number | null;
  created_at: string;
}

export interface DashboardStats {
  total_projects: number;
  total_videos: number;
  total_clips_generated: number;
  clips_completed: number;
  clips_processing: number;
  clips_failed: number;
  jobs_active: number;
  jobs_failed: number;
}

export type VideoBatchStatus =
  | "pending"
  | "running"
  | "completed"
  | "completed_with_errors"
  | "failed"
  | "cancelled";

export type VideoBatchItemStatus =
  | "pending"
  | "importing"
  | "analyzing"
  | "rendering"
  | "publishing"
  | "completed"
  | "failed"
  | "skipped"
  | "cancelled";

export interface VideoBatchSettings {
  clip_mode: "top_3" | "top_5" | "top_10" | "all";
  template_id: number | null;
  aspect_ratio: "9:16" | "1:1" | "16:9";
  subtitle_language: string;
  subtitles_enabled: boolean;
  publishing_profile_id: number | null;
  publish_stagger_min_minutes: number;
  publish_stagger_max_minutes: number;
}

export interface VideoBatchItem {
  id: number;
  position: number;
  source_url: string;
  project_id: number | null;
  project_title: string | null;
  status: VideoBatchItemStatus;
  progress: number;
  message: string | null;
  failure_reason: string | null;
  clips_generated: number;
  posts_published: number;
  started_at: string | null;
  finished_at: string | null;
}

export interface VideoBatch {
  id: number;
  name: string | null;
  status: VideoBatchStatus;
  settings: VideoBatchSettings;
  total_items: number;
  completed_items: number;
  failed_items: number;
  items?: VideoBatchItem[];
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
}

export interface ChannelWatch {
  id: number;
  platform: "youtube";
  channel_id: string;
  channel_title: string | null;
  channel_url: string;
  thumbnail_url: string | null;
  is_active: boolean;
  settings: VideoBatchSettings;
  last_video_id: string | null;
  last_video_published_at: string | null;
  last_checked_at: string | null;
  last_error: string | null;
  created_at: string;
}

export type TrendingPlatform = "youtube" | "facebook" | "tiktok" | "instagram" | "twitter";

export interface TrendingItem {
  platform: TrendingPlatform;
  external_id: string;
  title: string;
  source_url: string;
  thumbnail_url: string | null;
  author_name: string | null;
  view_count: number;
  like_count: number;
  comment_count: number;
  published_at: string | null;
  is_mock: boolean;
}

export interface TrendingPlatformInfo {
  key: TrendingPlatform;
  label: string;
  is_mocked: boolean;
}

export type ContentBriefStatus =
  | "pending"
  | "searching"
  | "fetching_sources"
  | "generating_script"
  | "finding_videos"
  | "completed"
  | "failed"
  | "cancelled";

export interface ContentBriefSource {
  title: string;
  url: string;
  snippet: string | null;
  published_at: string | null;
  content_excerpt: string | null;
}

export interface ContentBriefCandidateVideo {
  title: string;
  url: string;
  platform: string | null;
  thumbnail_url: string | null;
}

export interface ContentBriefNarrativeSection {
  heading: string;
  narration_text: string;
  duration_estimate_seconds: number;
}

export interface ContentBrief {
  id: number;
  topic: string;
  region_code: string | null;
  source_platform: string | null;
  source_trending_title: string | null;
  source_trending_url: string | null;
  status: ContentBriefStatus;
  progress: number;
  message: string | null;
  failure_reason: string | null;
  sources: ContentBriefSource[];
  candidate_videos: ContentBriefCandidateVideo[];
  narrative_title: string | null;
  narrative_hook: string | null;
  narrative_sections: ContentBriefNarrativeSection[];
  narrative_full_script: string | null;
  narrative_suggested_description: string | null;
  narrative_suggested_hashtags: string[];
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
}

export interface Paginated<T> {
  data: T[];
  meta?: {
    current_page: number;
    last_page: number;
    total: number;
  };
}

// --- Content Research Engine ---
// "Content channels" are brand/persona channels the research engine generates
// ideas for. Distinct from ChannelWatch (a watched YouTube upload feed).

export interface Platform {
  id: number;
  key: string;
  name: string;
  type: string;
  default_strategy: Record<string, unknown>;
  enabled: boolean;
  sort_order: number;
}

export interface ChannelTemplate {
  id: number;
  key: string;
  name: string;
  description: string | null;
  platform_key: string | null;
  defaults: ChannelTemplateDefaults;
  sort_order: number;
}

export interface ChannelTemplateDefaults {
  niche?: string;
  sub_niches?: string[];
  keywords?: string[];
  content_style?: string[];
  content_types?: string[];
  content_formats?: string[];
  tone?: string;
  research_frequency?: ResearchFrequency;
  research_times?: string[];
  interval_hours?: number;
  ideas_per_run?: number;
  research_source_keys?: string[];
}

export type ResearchFrequency = "daily" | "twice_daily" | "every_n_hours" | "custom";

/** One field a provider accepts in its per-channel configuration. */
export interface ResearchSourceConfigField {
  key: string;
  label: string;
  type: "list" | "number" | "text" | "boolean" | "select";
  help?: string;
  default?: unknown;
  options?: string[];
}

export interface ResearchSource {
  id: number;
  key: string;
  provider: string;
  name: string;
  type: string;
  description: string | null;
  enabled: boolean;
  configuration: Record<string, unknown>;
  /** False when the provider class is missing from the app's registry. */
  registered: boolean;
  requires_credentials: boolean;
  is_configured: boolean;
  config_schema: ResearchSourceConfigField[];
  health: {
    last_success_at: string | null;
    last_failure_at: string | null;
    last_error: string | null;
    consecutive_failures: number;
    /** Skipped by the engine until a manual test passes. */
    circuit_open: boolean;
  };
  /** Only present when the source is loaded through a channel. */
  pivot?: {
    enabled: boolean;
    weight: number;
    priority: number;
    configuration: Record<string, unknown>;
  };
}

export interface ScoringWeights {
  trend: number;
  relevance: number;
  originality: number;
  freshness: number;
  engagement: number;
  cross_source: number;
}

export interface ContentChannel {
  id: number;
  name: string;
  handle: string | null;
  description: string | null;
  language: string;
  timezone: string;
  is_active: boolean;

  platform_id: number;
  platform?: Platform;

  niche: string | null;
  sub_niches: string[];
  keywords: string[];
  excluded_keywords: string[];
  target_audience: string | null;

  content_style: string[];
  content_types: string[];
  content_formats: string[];
  tone: string | null;
  hook_styles: string[];

  scheduler_enabled: boolean;
  research_frequency: ResearchFrequency;
  /** As stored. Empty for every_n_hours — use schedule_times to display. */
  research_times: string[];
  /** The expanded schedule the engine actually uses, in the channel's timezone. */
  schedule_times: string[];
  interval_hours: number | null;
  ideas_per_run: number;
  min_relevance_score: number;
  min_trend_score: number;
  scoring_weights: ScoringWeights;

  last_research_at: string | null;
  next_research_at: string | null;

  research_sources?: ResearchSource[];
  ideas_count?: number;
  runs_count?: number;

  created_at: string;
  updated_at: string;
}

export type ResearchRunStatus = "running" | "success" | "partial" | "failed";

export interface ResearchProviderOutcome {
  source_key: string;
  items?: number;
  error?: string;
  /** True when the source was skipped (no credentials, open circuit) rather than failing. */
  skipped?: boolean;
}

export interface ResearchRun {
  id: number;
  content_channel_id: number;
  channel?: ContentChannel;
  trigger: "scheduled" | "manual";
  status: ResearchRunStatus;
  progress: number;
  message: string | null;
  topics_found: number;
  results_collected: number;
  ideas_generated: number;
  duplicates_skipped: number;
  providers_used: ResearchProviderOutcome[];
  providers_failed: ResearchProviderOutcome[];
  error_message: string | null;
  duration_ms: number | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
  results?: ResearchResult[];
  ideas?: ContentIdea[];
}

export interface ResearchResult {
  id: number;
  source_key: string;
  title: string;
  url: string;
  summary: string | null;
  author: string | null;
  published_at: string | null;
  discovered_at: string | null;
  engagement: Record<string, number>;
  source_score: number;
  topic_key: string | null;
}

export interface ContentIdeaSource {
  id: number;
  source_key: string;
  source_title: string;
  source_url: string;
  extracted_summary: string | null;
  engagement_metrics: Record<string, number>;
  source_score: number;
  published_at: string | null;
  discovered_at: string | null;
}

export type ContentIdeaStatus =
  | "idea"
  | "selected"
  | "scripting"
  | "draft"
  | "approved"
  | "published"
  | "rejected";

export interface ContentIdea {
  id: number;
  content_channel_id: number;
  channel?: ContentChannel;
  research_run_id: number | null;
  research_date: string | null;

  topic: string;
  title: string;
  alternative_titles: string[];
  short_description: string | null;
  content_angle: string | null;
  why_this_topic: string | null;
  target_audience: string | null;
  keywords: string[];
  source_summary: string | null;

  scores: {
    trend: number;
    relevance: number;
    originality: number;
    freshness: number;
    engagement: number;
    cross_source: number;
    priority: number;
  };
  priority_score: number;
  trend_score: number;

  suggested_content_type: string | null;
  suggested_format: string | null;
  status: ContentIdeaStatus;
  notes: string | null;
  selected_at: string | null;
  sources?: ContentIdeaSource[];
  sources_count?: number;
  created_at: string;
}

export interface ResearchDashboard {
  summary: {
    channels_total: number;
    channels_scheduled: number;
    ideas_today: number;
    ideas_total: number;
    ideas_waiting_review: number;
    ideas_high_priority: number;
    ideas_selected: number;
  };
  ideas_by_channel: {
    id: number;
    name: string;
    niche: string | null;
    scheduler_enabled: boolean;
    ideas_count: number;
    ideas_today_count: number;
    last_research_at: string | null;
    next_research_at: string | null;
  }[];
  top_ideas: ContentIdea[];
  recent_runs: ResearchRun[];
  scheduler: {
    last_success_at: string | null;
    next_research_at: string | null;
    runs_failed_24h: number;
    running: number;
  };
  provider_health: ResearchSource[];
  trending_topics: {
    topic_key: string;
    title: string;
    mentions: number;
    sources: number;
    research_date: string;
  }[];
}
