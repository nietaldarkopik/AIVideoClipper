// Human wording for the assistant's tool names. Anything not listed falls back to a
// readable version of the snake_case name, so a tool added on the backend still shows up
// sensibly before this file learns about it.
const LABELS: Record<string, string> = {
  get_overview: "Memeriksa ringkasan dashboard",
  get_analytics: "Menganalisis performa posting",
  list_projects: "Melihat daftar project",
  get_project: "Membuka detail project",
  list_clips: "Melihat daftar clip",
  search_clips: "Mencari clip",
  get_clip: "Membuka detail clip",
  list_social_accounts: "Memeriksa akun media sosial",
  list_social_platforms: "Melihat platform yang didukung",
  get_social_connect_link: "Membuat tautan hubungkan akun",
  list_social_posts: "Melihat daftar posting",
  get_social_post: "Membuka detail posting",
  list_publishing_profiles: "Melihat profil publikasi",
  list_content_channels: "Melihat channel riset",
  list_content_ideas: "Melihat ide konten",
  get_content_idea: "Membuka detail ide konten",
  list_research_runs: "Melihat riwayat riset",
  list_content_briefs: "Melihat brief riset",
  get_content_brief: "Membuka brief & naskah",
  list_cartoon_videos: "Melihat video kartun",
  get_cartoon_video: "Membuka detail video kartun",
  get_trending: "Memeriksa tren terkini",
  list_facebook_messages: "Membaca pesan Facebook",
  list_video_batches: "Melihat batch video",
  update_clip: "Mengubah clip",
  generate_clip_social_metadata: "Membuat caption & hashtag clip",
  create_project: "Membuat project",
  update_content_idea: "Mengubah ide konten",
  create_content_brief: "Memulai riset & naskah",
  run_research: "Menjalankan riset",
  create_cartoon_video: "Membuat video kartun",
  render_cartoon_video: "Merender video kartun",
  update_social_post: "Mengubah posting",
  bulk_reschedule_social_posts: "Menjadwal ulang posting",
  sync_social_post_metrics: "Menyinkronkan metrik posting",
  publish_clip: "Memposting clip",
  schedule_clips_bulk: "Menjadwalkan clip",
  publish_social_post_now: "Memposting sekarang",
  retry_social_post: "Mengulang posting",
  delete_clip: "Menghapus clip",
  delete_social_post: "Menghapus posting",
  delete_project: "Menghapus project",
  delete_content_brief: "Menghapus brief",
  delete_cartoon_video: "Menghapus video kartun",
};

export function toolLabel(name: string | null | undefined): string {
  if (!name) return "Tool";
  if (LABELS[name]) return LABELS[name];
  const readable = name.replace(/_/g, " ");
  return readable.charAt(0).toUpperCase() + readable.slice(1);
}

/** Parses a stored tool result; returns whether it is an error payload and its message. */
export function parseToolResult(content: string | null | undefined): { error: boolean; message: string | null } {
  if (!content) return { error: false, message: null };
  try {
    const parsed = JSON.parse(content);
    if (parsed && typeof parsed === "object" && "error" in parsed && parsed.error) {
      return { error: true, message: typeof parsed.message === "string" ? parsed.message : null };
    }
    if (parsed && typeof parsed === "object" && "rejected" in parsed && parsed.rejected) {
      return { error: false, message: typeof parsed.message === "string" ? parsed.message : null };
    }
  } catch {
    // Results can be truncated mid-JSON by the backend — treat as plain, non-error text.
  }
  return { error: false, message: null };
}
