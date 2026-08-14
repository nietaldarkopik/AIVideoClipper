"use client";

import Link from "next/link";
import { Scissors, Copy, Trash2, Download, RefreshCw } from "lucide-react";
import { mutate } from "swr";
import { Card } from "@/components/ui/Card";
import { StatusBadge } from "@/components/ui/Badge";
import { ProgressBar } from "@/components/ui/ProgressBar";
import { formatDuration } from "@/lib/format";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import type { Clip } from "@/lib/types";

export function ClipCard({ clip, mutateKey }: { clip: Clip; mutateKey?: string }) {
  const isActive = clip.status === "queued" || clip.status === "rendering";

  async function refresh() {
    if (mutateKey) await mutate(mutateKey);
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
      <Link href={`/clips/${clip.id}`}>
        <div className="relative aspect-[9/16] w-full overflow-hidden bg-black">
          {clip.thumbnail_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={clip.thumbnail_url} alt={clip.title ?? "Clip"} className="h-full w-full object-cover" />
          ) : (
            <div className="flex h-full w-full items-center justify-center">
              <Scissors className="size-6 text-muted" />
            </div>
          )}
          <div className="absolute left-2 top-2">
            <StatusBadge status={clip.status} />
          </div>
          <div className="absolute bottom-2 right-2 rounded bg-black/70 px-1.5 py-0.5 text-[11px] font-medium text-white">
            {formatDuration(clip.duration)}
          </div>
          {isActive && (
            <div className="absolute inset-x-0 bottom-0">
              <ProgressBar value={clip.progress ?? 0} className="rounded-none" />
            </div>
          )}
        </div>
      </Link>
      <div className="p-3.5">
        <p className="truncate text-sm font-medium">{clip.title ?? `Clip #${clip.id}`}</p>
        <p className="mt-0.5 truncate text-xs text-muted">{clip.template?.name ?? "No template"}</p>

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
              href={`${clip.url}?download=1`}
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
    </Card>
  );
}
