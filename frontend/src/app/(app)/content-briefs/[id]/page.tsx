"use client";

import { use, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { mutate } from "swr";
import { ArrowLeft, Copy, Download, RotateCcw, Scissors, ExternalLink, Trash2 } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { formatRelativeTime, formatDuration } from "@/lib/format";
import { createProjectFromUrl } from "@/lib/createProjectFromUrl";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Skeleton } from "@/components/ui/Skeleton";
import { StatusBadge, Badge } from "@/components/ui/Badge";
import { ProgressBar } from "@/components/ui/ProgressBar";
import type { ContentBrief } from "@/lib/types";

const ACTIVE_STATUSES = ["pending", "searching", "fetching_sources", "generating_script", "finding_videos"];

export default function ContentBriefDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const briefId = Number(id);
  const router = useRouter();
  const [regenerating, setRegenerating] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [creatingProjectFor, setCreatingProjectFor] = useState<string | null>(null);

  const key = `/content-briefs/${briefId}`;
  const { data: res, isLoading } = useApi<{ data: ContentBrief }>(key, {
    refreshInterval: (latest?: { data: ContentBrief }) =>
      latest && ACTIVE_STATUSES.includes(latest.data.status) ? 2500 : 0,
  });
  const brief = res?.data;

  async function handleRegenerate() {
    setRegenerating(true);
    try {
      await api.post(`/content-briefs/${briefId}/regenerate-script`);
      await mutate(key);
      toast("Membuat ulang naskah...", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Gagal membuat ulang naskah.", "danger");
    } finally {
      setRegenerating(false);
    }
  }

  async function handleDelete() {
    if (!confirm("Hapus riset konten ini?")) return;
    setDeleting(true);
    try {
      await api.del(`/content-briefs/${briefId}`);
      await mutate((k) => typeof k === "string" && k.startsWith("/content-briefs"));
      toast("Riset konten dihapus.", "success");
      router.push("/content-briefs");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Gagal menghapus.", "danger");
      setDeleting(false);
    }
  }

  async function handleCreateProject(url: string, title: string) {
    setCreatingProjectFor(url);
    try {
      const project = await createProjectFromUrl(url, title);
      await mutate("/projects");
      toast("Import dimulai.", "success");
      router.push(`/projects/${project.id}`);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Gagal membuat proyek.", "danger");
      setCreatingProjectFor(null);
    }
  }

  function handleCopyScript() {
    if (!brief?.narrative_full_script) return;
    navigator.clipboard.writeText(brief.narrative_full_script);
    toast("Naskah disalin.", "success");
  }

  function handleDownloadScript() {
    if (!brief?.narrative_full_script) return;
    const blob = new Blob([brief.narrative_full_script], { type: "text/plain;charset=utf-8" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `naskah-${brief.id}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  if (isLoading || !brief) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-96" />
      </div>
    );
  }

  const isActive = ACTIVE_STATUSES.includes(brief.status);
  const canRegenerate = !isActive && brief.sources.length > 0;

  return (
    <div className="space-y-6">
      <div>
        <Link href="/content-briefs" className="mb-3 flex items-center gap-1.5 text-xs text-muted hover:text-foreground">
          <ArrowLeft className="size-3.5" />
          Kembali ke Riset Konten
        </Link>
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0">
            <div className="flex items-center gap-2.5">
              <h1 className="text-xl font-semibold">{brief.topic}</h1>
              <StatusBadge status={brief.status} />
            </div>
            <p className="mt-1 text-sm text-muted">
              Dimulai {formatRelativeTime(brief.created_at)}
              {brief.message && ` · ${brief.message}`}
            </p>
          </div>
          <Button variant="danger" size="sm" onClick={handleDelete} loading={deleting} disabled={isActive}>
            <Trash2 className="size-3.5" />
            Hapus
          </Button>
        </div>
      </div>

      {isActive && (
        <Card className="p-5">
          <div className="flex items-center justify-between text-xs text-muted">
            <span>{brief.message || "Memproses..."}</span>
            <span>{brief.progress}%</span>
          </div>
          <ProgressBar className="mt-2" value={brief.progress} />
        </Card>
      )}

      {brief.status === "failed" && (
        <Card className="border-danger/30 bg-danger/5 p-5">
          <p className="text-sm text-danger">{brief.failure_reason || "Riset konten gagal."}</p>
        </Card>
      )}

      {brief.sources.length > 0 && (
        <Card className="p-5">
          <h2 className="text-sm font-semibold">Sumber</h2>
          <div className="mt-3 space-y-3">
            {brief.sources.map((source, i) => {
              const content = (
                <>
                  <div className="flex items-center gap-1.5">
                    <p className="truncate text-sm font-medium">{source.title}</p>
                    {source.url && <ExternalLink className="size-3 shrink-0 text-muted" />}
                  </div>
                  {source.snippet && <p className="mt-1 line-clamp-2 text-xs text-muted">{source.snippet}</p>}
                </>
              );

              // No real page for this one (e.g. a synthesized AI answer used as a
              // fallback when the search had no citable results) — render as a
              // plain block instead of a dead link.
              return source.url ? (
                <a
                  key={i}
                  href={source.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="block rounded-xl border border-border-subtle p-3 transition-colors hover:border-accent/40"
                >
                  {content}
                </a>
              ) : (
                <div key={i} className="rounded-xl border border-border-subtle p-3">
                  {content}
                </div>
              );
            })}
          </div>
        </Card>
      )}

      {(brief.narrative_full_script || brief.narrative_title) && (
        <Card className="p-5">
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold">Naskah</h2>
            <div className="flex gap-2">
              <Button size="sm" variant="outline" onClick={handleCopyScript}>
                <Copy className="size-3.5" />
                Salin Naskah
              </Button>
              <Button size="sm" variant="outline" onClick={handleDownloadScript}>
                <Download className="size-3.5" />
                Unduh .txt
              </Button>
              <Button size="sm" variant="secondary" onClick={handleRegenerate} loading={regenerating} disabled={!canRegenerate}>
                <RotateCcw className="size-3.5" />
                Buat Ulang Naskah
              </Button>
            </div>
          </div>

          {brief.narrative_title && <p className="mt-4 text-base font-semibold">{brief.narrative_title}</p>}
          {brief.narrative_suggested_description && (
            <p className="mt-1 text-sm text-muted">{brief.narrative_suggested_description}</p>
          )}
          {brief.narrative_suggested_hashtags.length > 0 && (
            <div className="mt-2 flex flex-wrap gap-1.5">
              {brief.narrative_suggested_hashtags.map((tag) => (
                <Badge key={tag} tone="accent">
                  {tag}
                </Badge>
              ))}
            </div>
          )}

          {brief.narrative_hook && (
            <div className="mt-4 rounded-xl bg-white/5 p-4">
              <p className="text-xs font-medium text-muted">Hook</p>
              <p className="mt-1 text-sm">{brief.narrative_hook}</p>
            </div>
          )}

          <div className="mt-4 space-y-4">
            {brief.narrative_sections.map((section, i) => (
              <div key={i} className="border-t border-border-subtle pt-4">
                <div className="flex items-center justify-between">
                  <p className="text-sm font-semibold">{section.heading}</p>
                  <Badge tone="muted">{formatDuration(section.duration_estimate_seconds)}</Badge>
                </div>
                <p className="mt-1.5 whitespace-pre-wrap text-sm text-muted">{section.narration_text}</p>
              </div>
            ))}
          </div>
        </Card>
      )}

      {brief.candidate_videos.length > 0 && (
        <Card className="p-5">
          <h2 className="text-sm font-semibold">Video Terkait</h2>
          <div className="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {brief.candidate_videos.map((video, i) => (
              <Card key={i} className="overflow-hidden">
                <div className="relative flex aspect-video w-full items-center justify-center overflow-hidden bg-gradient-to-br from-surface-elevated to-surface">
                  {video.thumbnail_url ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={video.thumbnail_url} alt={video.title} className="h-full w-full object-cover" />
                  ) : (
                    <Scissors className="size-6 text-muted" />
                  )}
                  {video.platform && (
                    <Badge tone="accent" className="absolute left-2 top-2 capitalize">
                      {video.platform}
                    </Badge>
                  )}
                </div>
                <div className="p-3">
                  <p className="line-clamp-2 text-xs font-medium">{video.title}</p>
                  <Button
                    onClick={() => handleCreateProject(video.url, video.title)}
                    loading={creatingProjectFor === video.url}
                    className="mt-2 w-full"
                    size="sm"
                  >
                    <Scissors className="size-3.5" />
                    Buat Proyek Klip
                  </Button>
                </div>
              </Card>
            ))}
          </div>
        </Card>
      )}
    </div>
  );
}
