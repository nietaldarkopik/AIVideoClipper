"use client";

import { useState } from "react";
import { mutate } from "swr";
import { RefreshCw, Trash2, Rss, Pencil } from "lucide-react";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { formatRelativeTime } from "@/lib/format";
import { EditChannelWatchModal } from "@/components/channels/EditChannelWatchModal";
import type { ChannelWatch } from "@/lib/types";

export function ChannelWatchCard({ watch }: { watch: ChannelWatch }) {
  const [checking, setChecking] = useState(false);
  const [toggling, setToggling] = useState(false);
  const [editOpen, setEditOpen] = useState(false);

  async function refresh() {
    await mutate((key) => typeof key === "string" && key.startsWith("/channel-watches"));
  }

  async function handleCheckNow() {
    setChecking(true);
    try {
      await api.post(`/channel-watches/${watch.id}/check`);
      await refresh();
      toast("Checked for new uploads.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Check failed.", "danger");
    } finally {
      setChecking(false);
    }
  }

  async function handleToggleActive() {
    setToggling(true);
    try {
      await api.patch(`/channel-watches/${watch.id}`, { is_active: !watch.is_active });
      await refresh();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to update.", "danger");
    } finally {
      setToggling(false);
    }
  }

  async function handleDelete() {
    if (!confirm(`Stop watching "${watch.channel_title ?? watch.channel_url}"?`)) return;
    try {
      await api.del(`/channel-watches/${watch.id}`);
      await refresh();
      toast("Channel removed.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to delete.", "danger");
    }
  }

  return (
    <Card className="flex items-center gap-4 p-5">
      <div className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-surface-elevated">
        {watch.thumbnail_url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={watch.thumbnail_url} alt="" className="size-full object-cover" />
        ) : (
          <Rss className="size-5 text-muted" />
        )}
      </div>

      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2.5">
          <h3 className="truncate text-sm font-semibold">{watch.channel_title ?? watch.channel_url}</h3>
          <Badge tone={watch.is_active ? "success" : "muted"}>{watch.is_active ? "Watching" : "Paused"}</Badge>
        </div>
        <p className="mt-1 truncate text-xs text-muted">
          Last checked {formatRelativeTime(watch.last_checked_at)}
          {watch.last_video_published_at && ` · Last upload found ${formatRelativeTime(watch.last_video_published_at)}`}
          {` · Publish gap ${watch.settings.publish_stagger_min_minutes ?? 30}–${watch.settings.publish_stagger_max_minutes ?? 60}min`}
        </p>
        {watch.last_error && (
          <Badge tone="danger" className="mt-2">
            {watch.last_error}
          </Badge>
        )}
      </div>

      <div className="flex shrink-0 items-center gap-1.5">
        <button
          onClick={handleCheckNow}
          disabled={checking}
          title="Check now"
          className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer disabled:opacity-50"
        >
          <RefreshCw className={`size-3.5 ${checking ? "animate-spin" : ""}`} />
        </button>
        <button
          onClick={handleToggleActive}
          disabled={toggling}
          title={watch.is_active ? "Pause watching" : "Resume watching"}
          className="rounded-lg px-2.5 py-1.5 text-xs font-medium text-muted hover:bg-white/5 hover:text-foreground cursor-pointer disabled:opacity-50"
        >
          {watch.is_active ? "Pause" : "Resume"}
        </button>
        <button
          onClick={() => setEditOpen(true)}
          title="Edit channel settings"
          className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
        >
          <Pencil className="size-3.5" />
        </button>
        <button
          onClick={handleDelete}
          title="Stop watching"
          className="rounded-lg p-1.5 text-muted hover:bg-danger/10 hover:text-danger cursor-pointer"
        >
          <Trash2 className="size-3.5" />
        </button>
      </div>

      <EditChannelWatchModal watch={watch} open={editOpen} onClose={() => setEditOpen(false)} />
    </Card>
  );
}
