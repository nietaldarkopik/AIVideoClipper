"use client";

import { useState } from "react";
import Link from "next/link";
import { Plus, Bot, ArrowRight } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusBadge } from "@/components/ui/Badge";
import { ProgressBar } from "@/components/ui/ProgressBar";
import { formatRelativeTime } from "@/lib/format";
import { NewBatchModal } from "@/components/batches/NewBatchModal";
import type { Paginated, VideoBatch } from "@/lib/types";

const ACTIVE_STATUSES = ["pending", "running"];

export default function BatchesPage() {
  const [modalOpen, setModalOpen] = useState(false);
  const { data, isLoading } = useApi<Paginated<VideoBatch>>("/video-batches?per_page=50", {
    refreshInterval: (latest?: Paginated<VideoBatch>) =>
      latest?.data.some((b: VideoBatch) => ACTIVE_STATUSES.includes(b.status)) ? 3000 : 0,
  });

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Batch Autobot</h1>
          <p className="mt-1 text-sm text-muted">
            Paste a list of video URLs — each one gets imported, transcribed, clipped, and
            published automatically, one at a time.
          </p>
        </div>
        <Button onClick={() => setModalOpen(true)}>
          <Plus className="size-4" />
          New Batch
        </Button>
      </div>

      {isLoading || !data ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
      ) : data.data.length === 0 ? (
        <EmptyState
          icon={<Bot className="size-6" />}
          title="No batches yet"
          description="Start a batch to automate a whole list of videos end-to-end."
          action={
            <Button size="sm" onClick={() => setModalOpen(true)}>
              <Plus className="size-4" />
              New Batch
            </Button>
          }
        />
      ) : (
        <div className="space-y-3">
          {data.data.map((batch) => (
            <Link key={batch.id} href={`/batches/${batch.id}`}>
              <Card className="group flex items-center justify-between gap-4 p-5 transition-colors hover:border-accent/40">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2.5">
                    <h3 className="truncate text-sm font-semibold">
                      {batch.name || `Batch #${batch.id}`}
                    </h3>
                    <StatusBadge status={batch.status} />
                  </div>
                  <p className="mt-1 text-xs text-muted">
                    {batch.completed_items}/{batch.total_items} videos done
                    {batch.failed_items > 0 && ` · ${batch.failed_items} failed`}
                    {" · "}
                    Started {formatRelativeTime(batch.created_at)}
                  </p>
                  <ProgressBar
                    className="mt-3 max-w-sm"
                    value={batch.total_items ? (batch.completed_items / batch.total_items) * 100 : 0}
                    tone={batch.failed_items > 0 ? "danger" : "accent"}
                  />
                </div>
                <ArrowRight className="size-4 shrink-0 text-muted transition-colors group-hover:text-accent-2" />
              </Card>
            </Link>
          ))}
        </div>
      )}

      <NewBatchModal open={modalOpen} onClose={() => setModalOpen(false)} />
    </div>
  );
}
