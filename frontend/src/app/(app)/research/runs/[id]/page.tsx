"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { AlertTriangle, ArrowLeft, CheckCircle2, ExternalLink, SkipForward } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { StatusBadge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { PriorityPill } from "@/components/research/ScoreBar";
import { formatRelativeTime } from "@/lib/format";
import type { ResearchRun } from "@/lib/types";

export default function ResearchRunDetailPage() {
  const params = useParams<{ id: string }>();

  const { data, isLoading } = useApi<{ data: ResearchRun }>(`/research/runs/${params.id}`, {
    refreshInterval: (latest?: { data: ResearchRun }) => (latest?.data.status === "running" ? 3000 : 0),
  });

  if (isLoading || !data) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-20" />
        <Skeleton className="h-64" />
      </div>
    );
  }

  const run = data.data;

  return (
    <div className="space-y-6">
      <div>
        <Link
          href="/research/runs"
          className="inline-flex items-center gap-1.5 text-xs text-muted hover:text-foreground"
        >
          <ArrowLeft className="size-3.5" />
          Riwayat riset
        </Link>

        <div className="mt-2 flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-lg font-semibold">
              {run.channel ? (
                <Link href={`/research/channels/${run.channel.id}`} className="hover:underline">
                  {run.channel.name}
                </Link>
              ) : (
                `Run #${run.id}`
              )}
            </h1>
            <p className="mt-1 text-sm text-muted">
              {run.trigger === "manual" ? "Dijalankan manual" : "Terjadwal"} ·{" "}
              {formatRelativeTime(run.created_at)}
              {run.duration_ms !== null && ` · selesai dalam ${(run.duration_ms / 1000).toFixed(1)}s`}
            </p>
          </div>
          <StatusBadge status={run.status} />
        </div>
      </div>

      {run.error_message && (
        <Card className="border-danger/40 p-4">
          <p className="text-sm text-danger">{run.error_message}</p>
        </Card>
      )}

      <div className="grid gap-4 sm:grid-cols-4">
        <Stat label="Hasil terkumpul" value={run.results_collected} />
        <Stat label="Topik" value={run.topics_found} />
        <Stat label="Ide dibuat" value={run.ideas_generated} />
        <Stat label="Duplikat dilewati" value={run.duplicates_skipped} />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Sumber Riset</CardTitle>
        </CardHeader>
        <CardContent className="space-y-2">
          {run.providers_used.map((provider) => (
            <div
              key={provider.source_key}
              className="flex items-center justify-between gap-3 rounded-xl border border-border-subtle bg-surface px-3 py-2"
            >
              <span className="flex items-center gap-2 text-sm">
                <CheckCircle2 className="size-4 text-success" />
                {provider.source_key.replace(/_/g, " ")}
              </span>
              <span className="text-xs text-muted">{provider.items} hasil</span>
            </div>
          ))}

          {run.providers_failed.map((provider) => (
            <div
              key={provider.source_key}
              className="rounded-xl border border-border-subtle bg-surface px-3 py-2"
            >
              <span className="flex items-center gap-2 text-sm">
                {provider.skipped ? (
                  <SkipForward className="size-4 text-muted" />
                ) : (
                  <AlertTriangle className="size-4 text-warning" />
                )}
                {provider.source_key.replace(/_/g, " ")}
                <span className="text-xs text-muted">{provider.skipped ? "dilewati" : "gagal"}</span>
              </span>
              <p className="mt-1 line-clamp-3 text-xs text-muted">{provider.error}</p>
            </div>
          ))}

          {run.providers_used.length === 0 && run.providers_failed.length === 0 && (
            <p className="text-sm text-muted">Tidak ada sumber yang dijalankan.</p>
          )}
        </CardContent>
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Ide yang Dihasilkan</CardTitle>
          </CardHeader>
          <CardContent>
            {!run.ideas || run.ideas.length === 0 ? (
              <p className="text-sm text-muted">Riset ini tidak menghasilkan ide baru.</p>
            ) : (
              <ul className="space-y-2">
                {run.ideas.map((idea) => (
                  <li key={idea.id}>
                    <Link
                      href={`/research/ideas/${idea.id}`}
                      className="flex items-center gap-3 rounded-xl border border-border-subtle bg-surface p-3 transition-colors hover:border-accent/40"
                    >
                      <PriorityPill value={idea.scores.priority} />
                      <span className="min-w-0 flex-1 truncate text-sm">{idea.title}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Hasil Riset Mentah</CardTitle>
          </CardHeader>
          <CardContent>
            {!run.results || run.results.length === 0 ? (
              <p className="text-sm text-muted">Tidak ada hasil tersimpan.</p>
            ) : (
              <ul className="max-h-96 space-y-1.5 overflow-y-auto pr-1">
                {run.results.map((result) => (
                  <li key={result.id} className="rounded-lg border border-border-subtle bg-surface p-2.5">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <span className="rounded bg-white/5 px-1.5 py-0.5 text-[10px] uppercase text-muted">
                          {result.source_key.replace(/_/g, " ")}
                        </span>
                        <p className="mt-1 line-clamp-2 text-xs">{result.title}</p>
                      </div>
                      <a
                        href={result.url}
                        target="_blank"
                        rel="noreferrer noopener"
                        className="shrink-0 text-muted hover:text-foreground"
                      >
                        <ExternalLink className="size-3.5" />
                      </a>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <Card className="p-4">
      <p className="text-xs text-muted">{label}</p>
      <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
    </Card>
  );
}
