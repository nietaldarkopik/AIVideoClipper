"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import {
  AlertTriangle,
  ArrowLeft,
  CalendarClock,
  CalendarOff,
  Pencil,
  Play,
  Trash2,
} from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { useToastStore } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { ChannelFormModal } from "@/components/research/ChannelFormModal";
import { SourceConfigEditor } from "@/components/research/SourceConfigEditor";
import { PriorityPill } from "@/components/research/ScoreBar";
import { formatDayHeading, formatRelativeTime, formatUpcomingTime } from "@/lib/format";
import type { ContentChannel, ContentIdea, Paginated, ResearchRun } from "@/lib/types";

export default function ChannelDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;
  const router = useRouter();
  const push = useToastStore((s) => s.push);

  const { data: channel, isLoading, mutate } = useApi<{ data: ContentChannel }>(`/content-channels/${id}`);
  const { data: runs, mutate: mutateRuns } = useApi<Paginated<ResearchRun>>(
    `/content-channels/${id}/research-runs?per_page=10`,
    {
      refreshInterval: (latest?: Paginated<ResearchRun>) =>
        latest?.data.some((run) => run.status === "running") ? 3000 : 0,
    }
  );
  const { data: ideas, mutate: mutateIdeas } = useApi<Paginated<ContentIdea>>(
    `/content-channels/${id}/content-ideas?per_page=10`
  );

  const [editing, setEditing] = useState(false);
  const [running, setRunning] = useState(false);

  async function runNow() {
    setRunning(true);
    try {
      await api.post(`/content-channels/${id}/research-runs`);
      push("Riset dijalankan di background.", "success");
      mutateRuns();
    } catch (error) {
      push(error instanceof ApiError ? error.message : "Gagal menjalankan riset.", "danger");
    } finally {
      setRunning(false);
    }
  }

  async function remove() {
    if (!confirm(`Hapus channel "${channel?.data.name}" beserta semua ide dan riwayat risetnya?`)) return;

    try {
      await api.del(`/content-channels/${id}`);
      push("Channel dihapus.", "success");
      router.push("/research/channels");
    } catch (error) {
      push(error instanceof ApiError ? error.message : "Gagal menghapus channel.", "danger");
    }
  }

  if (isLoading || !channel) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-20" />
        <Skeleton className="h-64" />
      </div>
    );
  }

  const ch = channel.data;

  return (
    <div className="space-y-6">
      <div>
        <Link
          href="/research/channels"
          className="inline-flex items-center gap-1.5 text-xs text-muted hover:text-foreground"
        >
          <ArrowLeft className="size-3.5" />
          Semua channel
        </Link>

        <div className="mt-2 flex flex-wrap items-start justify-between gap-3">
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-xl font-semibold">{ch.name}</h1>
              {!ch.is_active && <Badge tone="muted">nonaktif</Badge>}
            </div>
            <p className="mt-1 text-sm text-muted">
              {ch.platform?.name}
              {ch.handle ? ` · ${ch.handle}` : ""} · {ch.niche ?? "niche belum diatur"}
            </p>
          </div>
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => setEditing(true)}>
              <Pencil className="size-4" />
              Edit
            </Button>
            <Button onClick={runNow} loading={running}>
              <Play className="size-4" />
              Jalankan Riset
            </Button>
          </div>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <Card>
            <CardHeader>
              <CardTitle>Profil Channel</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <Fact label="Target Audiens" value={ch.target_audience ?? "—"} />
              <div className="grid gap-4 sm:grid-cols-2">
                <TagList label="Sub Niche" items={ch.sub_niches} />
                <TagList label="Kata Kunci" items={ch.keywords} />
                <TagList label="Dikecualikan" items={ch.excluded_keywords} tone="danger" />
                <TagList label="Gaya Konten" items={ch.content_style} />
                <TagList label="Tipe Konten" items={ch.content_types} />
                <TagList label="Format" items={ch.content_formats} />
              </div>
              <div className="grid gap-4 sm:grid-cols-3 text-sm">
                <Fact label="Bahasa" value={ch.language} />
                <Fact label="Zona Waktu" value={ch.timezone} />
                <Fact label="Tone" value={ch.tone ?? "—"} />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex items-center justify-between">
              <CardTitle>Sumber Riset</CardTitle>
              <span className="text-xs text-muted">{ch.research_sources?.length ?? 0} sumber aktif</span>
            </CardHeader>
            <CardContent>
              {!ch.research_sources || ch.research_sources.length === 0 ? (
                <p className="text-sm text-muted">
                  Belum ada sumber. Edit channel ini untuk memilih sumber riset.
                </p>
              ) : (
                <ul className="space-y-2">
                  {ch.research_sources.map((source) => (
                    <li
                      key={source.id}
                      className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border-subtle bg-surface px-3 py-2.5"
                    >
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-1.5">
                          <span className="text-sm font-medium">{source.name}</span>
                          {source.requires_credentials && !source.is_configured && (
                            <Badge tone="warning" className="text-[10px]">
                              perlu API key
                            </Badge>
                          )}
                          {source.health.circuit_open && (
                            <Badge tone="danger" className="text-[10px]">
                              dilewati ({source.health.consecutive_failures}x gagal)
                            </Badge>
                          )}
                          {!source.enabled && (
                            <Badge tone="muted" className="text-[10px]">
                              dinonaktifkan global
                            </Badge>
                          )}
                        </div>
                        <p className="mt-0.5 text-xs text-muted">
                          bobot {source.pivot?.weight ?? 1} ·{" "}
                          {source.health.last_success_at
                            ? `sukses ${formatRelativeTime(source.health.last_success_at)}`
                            : "belum pernah sukses"}
                        </p>
                      </div>
                      <SourceConfigEditor channel={ch} source={source} onSaved={() => mutate()} />
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex items-center justify-between">
              <CardTitle>Ide Konten Terbaru</CardTitle>
              <Link
                href={`/research/ideas?content_channel_id=${ch.id}`}
                className="text-xs text-accent-2 hover:underline"
              >
                Lihat semua
              </Link>
            </CardHeader>
            <CardContent>
              {!ideas || ideas.data.length === 0 ? (
                <EmptyState
                  title="Belum ada ide"
                  description="Jalankan riset untuk menghasilkan ide konten pertama channel ini."
                />
              ) : (
                <ul className="space-y-2">
                  {ideas.data.map((idea) => (
                    <li key={idea.id}>
                      <Link
                        href={`/research/ideas/${idea.id}`}
                        className="flex items-center gap-3 rounded-xl border border-border-subtle bg-surface p-3 transition-colors hover:border-accent/40"
                      >
                        <PriorityPill value={idea.scores.priority} />
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-medium">{idea.title}</p>
                          <p className="mt-0.5 truncate text-xs text-muted">
                            {idea.topic}
                            {idea.research_date && (
                              <>
                                {" "}
                                · <span className="whitespace-nowrap">{formatDayHeading(idea.research_date)}</span>
                              </>
                            )}
                          </p>
                        </div>
                        <StatusBadge status={idea.status} />
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Pengaturan Riset</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
              <div className="flex items-center gap-2">
                {ch.scheduler_enabled ? (
                  <>
                    <CalendarClock className="size-4 text-accent-2" />
                    <span>Otomatis aktif</span>
                  </>
                ) : (
                  <>
                    <CalendarOff className="size-4 text-muted" />
                    <span className="text-muted">Otomatis nonaktif</span>
                  </>
                )}
              </div>
              <Fact label="Jadwal" value={ch.schedule_times.join(" · ")} />
              <Fact label="Frekuensi" value={ch.research_frequency.replace(/_/g, " ")} />
              <Fact label="Ide per riset" value={String(ch.ideas_per_run)} />
              <Fact
                label="Minimum skor"
                value={`relevansi ${ch.min_relevance_score} · trend ${ch.min_trend_score}`}
              />
              <Fact
                label="Riset terakhir"
                value={ch.last_research_at ? formatRelativeTime(ch.last_research_at) : "belum pernah"}
              />
              <Fact
                label="Riset berikutnya"
                value={
                  ch.scheduler_enabled && ch.next_research_at
                    ? formatUpcomingTime(ch.next_research_at)
                    : "tidak dijadwalkan"
                }
              />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Riwayat Riset</CardTitle>
            </CardHeader>
            <CardContent>
              {!runs || runs.data.length === 0 ? (
                <p className="text-sm text-muted">Belum ada riset dijalankan.</p>
              ) : (
                <ul className="space-y-2">
                  {runs.data.map((run) => (
                    <li key={run.id} className="rounded-xl border border-border-subtle bg-surface p-3">
                      <div className="flex items-center justify-between gap-2">
                        <span className="text-xs text-muted">{formatRelativeTime(run.created_at)}</span>
                        <StatusBadge status={run.status} />
                      </div>
                      <p className="mt-1 text-sm">{run.message ?? "—"}</p>
                      {run.status === "running" && (
                        <div className="mt-2 h-1 overflow-hidden rounded-full bg-white/5">
                          <div className="h-full bg-accent" style={{ width: `${run.progress}%` }} />
                        </div>
                      )}
                      {run.providers_failed.length > 0 && (
                        <div className="mt-2 space-y-1">
                          {run.providers_failed.map((provider) => (
                            <p
                              key={provider.source_key}
                              className="flex items-start gap-1.5 text-[11px] text-warning"
                            >
                              <AlertTriangle className="mt-0.5 size-3 shrink-0" />
                              <span className="min-w-0">
                                <span className="font-medium">{provider.source_key}</span>:{" "}
                                <span className="line-clamp-2 text-muted">{provider.error}</span>
                              </span>
                            </p>
                          ))}
                        </div>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>

          <Button variant="danger" className="w-full" onClick={remove}>
            <Trash2 className="size-4" />
            Hapus Channel
          </Button>
        </div>
      </div>

      <ChannelFormModal
        open={editing}
        channel={ch}
        onClose={() => setEditing(false)}
        onSaved={() => {
          mutate();
          mutateIdeas();
        }}
      />
    </div>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs text-muted">{label}</p>
      <p className="mt-0.5 text-sm">{value}</p>
    </div>
  );
}

function TagList({
  label,
  items,
  tone = "muted",
}: {
  label: string;
  items: string[];
  tone?: "muted" | "danger";
}) {
  return (
    <div>
      <p className="mb-1.5 text-xs text-muted">{label}</p>
      {items.length === 0 ? (
        <p className="text-sm text-muted">—</p>
      ) : (
        <div className="flex flex-wrap gap-1">
          {items.map((item) => (
            <Badge key={item} tone={tone} className="text-[10px]">
              {item}
            </Badge>
          ))}
        </div>
      )}
    </div>
  );
}
