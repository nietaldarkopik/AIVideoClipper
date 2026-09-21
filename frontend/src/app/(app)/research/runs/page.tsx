"use client";

import { useState } from "react";
import Link from "next/link";
import { History } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { Card } from "@/components/ui/Card";
import { Select } from "@/components/ui/Input";
import { StatusBadge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { formatRelativeTime } from "@/lib/format";
import type { ContentChannel, Paginated, ResearchRun } from "@/lib/types";

export default function ResearchRunsPage() {
  const [channelId, setChannelId] = useState("");
  const [status, setStatus] = useState("");

  const { data: channels } = useApi<{ data: ContentChannel[] }>("/content-channels");

  const params = new URLSearchParams({ per_page: "30" });
  if (channelId) params.set("content_channel_id", channelId);
  if (status) params.set("status", status);

  const { data, isLoading } = useApi<Paginated<ResearchRun>>(`/research/runs?${params.toString()}`, {
    refreshInterval: (latest?: Paginated<ResearchRun>) =>
      latest?.data.some((run) => run.status === "running") ? 3000 : 0,
  });

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Riwayat Riset</h1>
        <p className="mt-1 text-sm text-muted">
          Setiap eksekusi riset, termasuk sumber mana yang berhasil dan mana yang gagal.
        </p>
      </div>

      <Card className="p-3">
        <div className="grid gap-2 sm:grid-cols-2">
          <Select value={channelId} onChange={(e) => setChannelId(e.target.value)}>
            <option value="">Semua channel</option>
            {channels?.data.map((channel) => (
              <option key={channel.id} value={channel.id}>
                {channel.name}
              </option>
            ))}
          </Select>
          <Select value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">Semua status</option>
            <option value="running">Berjalan</option>
            <option value="success">Sukses</option>
            <option value="partial">Parsial</option>
            <option value="failed">Gagal</option>
          </Select>
        </div>
      </Card>

      {isLoading || !data ? (
        <div className="space-y-2">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-20" />
          ))}
        </div>
      ) : data.data.length === 0 ? (
        <EmptyState icon={<History className="size-8" />} title="Belum ada riset dijalankan" />
      ) : (
        <ul className="space-y-2">
          {data.data.map((run) => (
            <li key={run.id}>
              <Link
                href={`/research/runs/${run.id}`}
                className="block rounded-2xl border border-border-subtle bg-surface p-4 transition-colors hover:border-accent/40"
              >
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-sm font-medium">{run.channel?.name ?? `Run #${run.id}`}</p>
                    <p className="mt-0.5 text-xs text-muted">
                      {run.trigger === "manual" ? "Manual" : "Terjadwal"} ·{" "}
                      {formatRelativeTime(run.created_at)}
                      {run.duration_ms !== null && ` · ${(run.duration_ms / 1000).toFixed(1)}s`}
                    </p>
                  </div>
                  <StatusBadge status={run.status} />
                </div>

                <p className="mt-2 text-sm text-muted">{run.message ?? "—"}</p>

                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                  <span>{run.results_collected} hasil</span>
                  <span>{run.topics_found} topik</span>
                  <span>{run.ideas_generated} ide</span>
                  {run.duplicates_skipped > 0 && <span>{run.duplicates_skipped} duplikat dilewati</span>}
                  <span className="text-success">{run.providers_used.length} sumber ok</span>
                  {run.providers_failed.length > 0 && (
                    <span className="text-warning">{run.providers_failed.length} sumber bermasalah</span>
                  )}
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
