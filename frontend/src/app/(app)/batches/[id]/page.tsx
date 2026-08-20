"use client";

import { use, useState } from "react";
import Link from "next/link";
import { mutate } from "swr";
import { ArrowLeft, Square, ExternalLink, RotateCcw } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { formatRelativeTime } from "@/lib/format";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Skeleton } from "@/components/ui/Skeleton";
import { StatusBadge } from "@/components/ui/Badge";
import { ProgressBar } from "@/components/ui/ProgressBar";
import { ScheduledPublishing } from "@/components/projects/ScheduledPublishing";
import type { VideoBatch } from "@/lib/types";

const ACTIVE_STATUSES = ["pending", "running"];

export default function BatchDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const batchId = Number(id);
  const [stopping, setStopping] = useState(false);
  const [retryingId, setRetryingId] = useState<number | null>(null);

  const key = `/video-batches/${batchId}`;
  const { data: res, isLoading } = useApi<{ data: VideoBatch }>(key, {
    refreshInterval: (latest?: { data: VideoBatch }) =>
      latest && ACTIVE_STATUSES.includes(latest.data.status) ? 2000 : 0,
  });
  const batch = res?.data;

  async function handleCancel() {
    if (!confirm("Stop this batch? Videos already in progress will finish their current step, then the rest are skipped.")) {
      return;
    }
    setStopping(true);
    try {
      await api.post(`/video-batches/${batchId}/cancel`);
      await mutate(key);
      toast("Batch stop requested.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to stop batch.", "danger");
    } finally {
      setStopping(false);
    }
  }

  async function handleRetryItem(itemId: number) {
    setRetryingId(itemId);
    try {
      await api.post(`/video-batches/${batchId}/items/${itemId}/retry`);
      await mutate(key);
      toast("Retrying this video.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to retry.", "danger");
    } finally {
      setRetryingId(null);
    }
  }

  if (isLoading || !batch) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-96" />
      </div>
    );
  }

  const canStop = ACTIVE_STATUSES.includes(batch.status);
  const overallProgress = batch.total_items ? (batch.completed_items / batch.total_items) * 100 : 0;

  return (
    <div className="space-y-6">
      <div>
        <Link href="/batches" className="mb-3 flex items-center gap-1.5 text-xs text-muted hover:text-foreground">
          <ArrowLeft className="size-3.5" />
          Back to Batches
        </Link>
        <div className="flex items-start justify-between gap-4">
          <div>
            <div className="flex items-center gap-2.5">
              <h1 className="text-xl font-semibold">{batch.name || `Batch #${batch.id}`}</h1>
              <StatusBadge status={batch.status} />
            </div>
            <p className="mt-1 text-sm text-muted">
              {batch.completed_items}/{batch.total_items} videos done
              {batch.failed_items > 0 && ` · ${batch.failed_items} failed`}
              {" · "}
              Started {formatRelativeTime(batch.created_at)}
            </p>
          </div>
          {canStop && (
            <Button variant="danger" size="sm" onClick={handleCancel} loading={stopping}>
              <Square className="size-3.5" />
              Stop Batch
            </Button>
          )}
        </div>
      </div>

      <Card className="p-5">
        <div className="flex items-center justify-between text-xs text-muted">
          <span>Overall progress</span>
          <span>{Math.round(overallProgress)}%</span>
        </div>
        <ProgressBar
          className="mt-2"
          value={overallProgress}
          tone={batch.failed_items > 0 ? "danger" : "accent"}
        />
        <div className="mt-4 grid grid-cols-3 gap-4 text-center text-xs">
          <div>
            <p className="text-lg font-semibold text-foreground">{batch.total_items}</p>
            <p className="text-muted">Total</p>
          </div>
          <div>
            <p className="text-lg font-semibold text-success">{batch.completed_items}</p>
            <p className="text-muted">Completed</p>
          </div>
          <div>
            <p className="text-lg font-semibold text-danger">{batch.failed_items}</p>
            <p className="text-muted">Failed</p>
          </div>
        </div>
      </Card>

      <div className="space-y-3">
        {(batch.items ?? []).map((item) => (
          <Card key={item.id} className="p-4">
            <div className="flex items-center justify-between gap-3">
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{item.project_title || item.source_url}</p>
                <p className="truncate text-xs text-muted">{item.source_url}</p>
              </div>
              <StatusBadge status={item.status} />
            </div>

            {!["completed", "failed", "cancelled", "skipped"].includes(item.status) && (
              <ProgressBar className="mt-3" value={item.progress} />
            )}

            <div className="mt-2 flex items-center justify-between text-xs text-muted">
              <span>
                {item.status === "failed" ? item.failure_reason : item.message}
                {item.status === "completed" &&
                  ` · ${item.clips_generated} clip${item.clips_generated === 1 ? "" : "s"}, ${item.posts_published} post${item.posts_published === 1 ? "" : "s"} published`}
              </span>
              <span className="flex shrink-0 items-center gap-3">
                {item.project_id && (
                  <Link
                    href={`/projects/${item.project_id}`}
                    className="flex items-center gap-1 text-accent-2 hover:underline"
                  >
                    Open project
                    <ExternalLink className="size-3" />
                  </Link>
                )}
                {item.status === "failed" && (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => handleRetryItem(item.id)}
                    loading={retryingId === item.id}
                  >
                    <RotateCcw className="size-3" />
                    Retry
                  </Button>
                )}
              </span>
            </div>

            {item.project_id && (
              <div className="mt-3">
                <ScheduledPublishing projectId={item.project_id} />
              </div>
            )}
          </Card>
        ))}
      </div>
    </div>
  );
}
