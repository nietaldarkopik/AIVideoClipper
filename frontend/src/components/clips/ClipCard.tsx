"use client";

import { useState } from "react";
import Link from "next/link";
import { Scissors, Copy, Trash2, Download, RefreshCw, PlayCircle, Share2, Clock } from "lucide-react";
import { mutate } from "swr";
import { Card } from "@/components/ui/Card";
import { StatusBadge, Badge } from "@/components/ui/Badge";
import { PLATFORM_LABELS } from "@/components/social/platforms";
import { ProgressBar } from "@/components/ui/ProgressBar";
import { formatDuration, withDownload } from "@/lib/format";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { ClipVideoModal } from "@/components/clips/ClipVideoModal";
import type { Clip } from "@/lib/types";

function formatTargets(targets: Clip["published_targets"]): string {
  return targets
    .map((t) => {
      const label = PLATFORM_LABELS[t.platform as keyof typeof PLATFORM_LABELS] ?? t.platform;
      return t.account_name ? `${label} (${t.account_name})` : label;
    })
    .join(", ");
}

export function ClipCard({ clip, mutateKey }: { clip: Clip; mutateKey?: string }) {
  const isActive = clip.status === "queued" || clip.status === "rendering";
  const [previewing, setPreviewing] = useState(false);

  async function refresh() {
    if (mutateKey) await mutate(mutateKey);
  }

  function handlePlay(e: React.MouseEvent) {
    e.preventDefault();
    setPreviewing(true);
  }

  async function handleDuplicate(e: React.MouseEvent) {
    e.preventDefault();
    try {
      await api.post(`/clips/${clip.id}/duplicate`);
      toast("Clip duplicated.", "success");
      refresh();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to duplicate.", "danger");
    }
  }

  async function handleRegenerate(e: React.MouseEvent) {
    e.preventDefault();
    try {
      await api.post(`/clips/${clip.id}/regenerate`);
      toast("Clip queued for re-render.", "success");
      refresh();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to regenerate.", "danger");
    }
  }

  async function handleDelete(e: React.MouseEvent) {
    e.preventDefault();
    if (!confirm("Delete this clip? This cannot be undone.")) return;
    try {
      await api.del(`/clips/${clip.id}`);
      toast("Clip deleted.", "success");
      refresh();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to delete.", "danger");
    }
  }

  return (
    <Card className="group overflow-hidden">
      <div className="relative aspect-[9/16] w-full overflow-hidden bg-black">
        {/* Not nested inside the play button below — a <button> can't be a
            descendant of an <a> (invalid HTML, breaks hydration). They're
            siblings instead: this covers the whole thumbnail for "go to
            edit", the play button sits on top as a small centered circle
            rather than a full overlay, so it doesn't swallow clicks meant
            for the rest of the thumbnail. */}
        <Link href={`/clips/${clip.id}`} className="absolute inset-0">
          {clip.thumbnail_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={clip.thumbnail_url} alt={clip.title ?? "Clip"} className="h-full w-full object-cover" />
          ) : (
            <div className="flex h-full w-full items-center justify-center">
              <Scissors className="size-6 text-muted" />
            </div>
          )}
        </Link>
        <div className="pointer-events-none absolute left-2 top-2 flex gap-1.5">
          <StatusBadge status={clip.status} />
          {clip.reaction_layout && <Badge tone="accent">Reaction</Badge>}
          {clip.is_published && <Badge tone="success">Published</Badge>}
        </div>
        <div className="pointer-events-none absolute bottom-2 right-2 rounded bg-black/70 px-1.5 py-0.5 text-[11px] font-medium text-white">
          {formatDuration(clip.duration)}
        </div>
        {clip.url && (
          <button
            onClick={handlePlay}
            title="Play video"
            className="absolute left-1/2 top-1/2 flex size-11 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-black/50 opacity-0 transition-opacity cursor-pointer hover:bg-black/70 group-hover:opacity-100"
          >
            <PlayCircle className="size-7 text-white" />
          </button>
        )}
        {isActive && (
          <div className="pointer-events-none absolute inset-x-0 bottom-0">
            <ProgressBar value={clip.progress ?? 0} className="rounded-none" />
          </div>
        )}
      </div>
      <div className="p-3.5">
        <Link href={`/clips/${clip.id}`} className="block truncate text-sm font-medium hover:underline">
          {clip.title ?? `Clip #${clip.id}`}
        </Link>
        <p className="mt-0.5 truncate text-xs text-muted">{clip.template?.name ?? "No template"}</p>
        <div className="mt-2 space-y-1 text-xs text-muted">
          {clip.project_title && <p className="truncate">📺 {clip.project_title}</p>}
          {clip.video_title && <p className="truncate">🎬 {clip.video_title}</p>}
          {clip.source_channel && <p className="truncate">📡 {clip.source_channel}</p>}
          {clip.published_targets && clip.published_targets.length > 0 && (
            <p className="flex items-start gap-1.5">
              <Share2 className="mt-0.5 size-3 shrink-0" />
              <span className="truncate">{formatTargets(clip.published_targets)}</span>
            </p>
          )}
          {clip.scheduled_targets && clip.scheduled_targets.length > 0 && (
            <p className="flex items-start gap-1.5">
              <Clock className="mt-0.5 size-3 shrink-0" />
              <span className="truncate">{formatTargets(clip.scheduled_targets)}</span>
            </p>
          )}
        </div>

        <div className="mt-3 flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
          <button
            onClick={handleDuplicate}
            title="Duplicate"
            className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
          >
            <Copy className="size-3.5" />
          </button>
          <button
            onClick={handleRegenerate}
            title="Regenerate"
            className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
          >
            <RefreshCw className="size-3.5" />
          </button>
          {clip.url && (
            <a
              href={withDownload(clip.url)}
              title="Download"
              onClick={(e) => e.stopPropagation()}
              className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
            >
              <Download className="size-3.5" />
            </a>
          )}
          <button
            onClick={handleDelete}
            title="Delete"
            className="ml-auto rounded-lg p-1.5 text-muted hover:bg-danger/10 hover:text-danger cursor-pointer"
          >
            <Trash2 className="size-3.5" />
          </button>
        </div>
      </div>

      <ClipVideoModal clip={previewing ? clip : null} onClose={() => setPreviewing(false)} />
    </Card>
  );
}
