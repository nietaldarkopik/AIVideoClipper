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
  crop_config: { mode: string; keyframes: unknown[] } | null;
  scenes: unknown[] | null;
  subtitle_language: string;
  subtitles_enabled: boolean;
  subtitle_config: Record<string, unknown> | null;
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

export interface TemplateConfig {
  caption?: {
    font?: string;
    font_size?: number | null;
    color?: string;
    highlight_color?: string;
    stroke_color?: string;
    stroke_width?: number;
    background?: string;
    background_opacity?: number;
    position?: "top" | "center" | "bottom";
    uppercase?: boolean;
    bold?: boolean;
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

export interface Paginated<T> {
  data: T[];
  meta?: {
    current_page: number;
    last_page: number;
    total: number;
  };
}
