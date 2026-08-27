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
export interface Segment {
  start: number;
  end: number;
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

export interface Clip {
  id: number;
  project_id: number;
  video_id: number;
  clip_candidate_id: number | null;
  template: Template | null;
  template_version_id: number | null;
  title: string | null;
  caption: string | null;
  hashtags: string[];
  start_time: number;
  end_time: number;
  duration: number;
  aspect_ratio: "9:16" | "1:1" | "16:9";
  crop_config: CropConfig | null;
  scenes: unknown[] | null;
  subtitle_language: string;
  subtitles_enabled: boolean;
  subtitle_config: Record<string, unknown> | null;
  layer_overrides: LayerOverrides | null;
  segments: Segment[] | null;
  reaction_layout: ReactionLayout | null;
  reaction_script: string | null;
  reaction_tone: "positive" | "satire" | null;
  intro_enabled: boolean;
  outro_enabled: boolean;
  intro_voice: string | null;
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
export type LayerType = "text" | "image" | "logo" | "pip_video" | "audio" | "progress_bar" | "rect";

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

export interface TemplateLayer {
  id: string;
  type: LayerType;
  z_index: number;
  x?: number;
  y?: number;
  width?: number | null;
  height?: number | null;
  opacity?: number;
  timing?: LayerTiming;
  props?: TextLayerProps | ImageLayerProps | AudioLayerProps | ProgressBarLayerProps | RectLayerProps | Record<string, unknown>;
}

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
  [key: string]: unknown;
}

export interface Template {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  thumbnail_url: string | null;
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

export interface Paginated<T> {
  data: T[];
  meta?: {
    current_page: number;
    last_page: number;
    total: number;
  };
}
