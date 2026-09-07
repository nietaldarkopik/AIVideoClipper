"use client";

import Link from "next/link";
import {
  Activity,
  AlertTriangle,
  ArrowRight,
  CalendarClock,
  CheckCircle2,
  Compass,
  Flame,
  Lightbulb,
  Plus,
  Radar,
  Radio,
} from "lucide-react";
import { useApi } from "@/lib/hooks";
import { Button } from "@/components/ui/Button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { PriorityPill } from "@/components/research/ScoreBar";
import { GroupedByDate } from "@/components/research/GroupedByDate";
import { formatDayHeading, formatRelativeTime, formatUpcomingTime } from "@/lib/format";
import type { ResearchDashboard } from "@/lib/types";

export default function ResearchDashboardPage() {
  const { data, isLoading } = useApi<ResearchDashboard>("/research/dashboard", {
    // Poll only while something is actually running, so an idle dashboard costs
    // nothing — same pattern as the content-briefs list.
    refreshInterval: (latest?: ResearchDashboard) => (latest?.scheduler.running ? 4000 : 0),
  });

  if (isLoading || !data) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-16" />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
        <Skeleton className="h-64" />
      </div>
    );
  }

  const { summary, scheduler } = data;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Content Research</h1>
          <p className="mt-1 text-sm text-muted">
            Riset otomatis multi-sumber per channel, lalu ide konten harian yang sudah diperingkat.
          </p>
        </div>
        <div className="flex gap-2">
          <Link href="/research/sources">
            <Button variant="secondary">
              <Radio className="size-4" />
              Sumber Riset
            </Button>
          </Link>
          <Link href="/research/channels">
            <Button>
              <Plus className="size-4" />
              Kelola Channel
            </Button>
          </Link>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard icon={<Lightbulb className="size-4" />} label="Ide hari ini" value={summary.ideas_today} />
        <StatCard icon={<Activity className="size-4" />} label="Menunggu review" value={summary.ideas_waiting_review} />
        <StatCard icon={<Flame className="size-4" />} label="Prioritas tinggi" value={summary.ideas_high_priority} />
        <StatCard
          icon={<Radar className="size-4" />}
          label="Channel terjadwal"
          value={`${summary.channels_scheduled}/${summary.channels_total}`}
        />
      </div>

      <Card>
        <CardHeader className="flex items-center justify-between">
          <CardTitle>Status Scheduler</CardTitle>
          {scheduler.running > 0 && <Badge tone="accent">{scheduler.running} riset berjalan</Badge>}
        </CardHeader>
        <CardContent className="grid gap-4 sm:grid-cols-3">
          <SchedulerFact
            icon={<CheckCircle2 className="size-4 text-success" />}
            label="Riset sukses terakhir"
            value={scheduler.last_success_at ? formatRelativeTime(scheduler.last_success_at) : "Belum pernah"}
          />
          <SchedulerFact
            icon={<CalendarClock className="size-4 text-accent-2" />}
            label="Riset terjadwal berikutnya"
            value={scheduler.next_research_at ? formatUpcomingTime(scheduler.next_research_at) : "Tidak ada"}
          />
          <SchedulerFact
            icon={
              <AlertTriangle
                className={scheduler.runs_failed_24h > 0 ? "size-4 text-warning" : "size-4 text-muted"}
              />
            }
            label="Gagal / parsial (24 jam)"
            value={String(scheduler.runs_failed_24h)}
          />
        </CardContent>
      </Card>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="flex items-center justify-between">
            <CardTitle>Ide Prioritas Tertinggi</CardTitle>
            <Link href="/research/ideas" className="text-xs text-accent-2 hover:underline">
              Lihat semua
            </Link>
          </CardHeader>
          <CardContent>
            {data.top_ideas.length === 0 ? (
              <EmptyState
                icon={<Lightbulb className="size-8" />}
                title="Belum ada ide konten"
                description="Jalankan riset dari salah satu channel untuk menghasilkan ide pertama."
                action={
                  <Link href="/research/channels">
                    <Button size="sm">Ke daftar channel</Button>
                  </Link>
                }
              />
            ) : (
              <ul className="space-y-2">
                {data.top_ideas.map((idea) => (
                  <li key={idea.id}>
                    <Link
                      href={`/research/ideas/${idea.id}`}
                      className="flex items-center gap-3 rounded-xl border border-border-subtle bg-surface p-3 transition-colors hover:border-accent/40"
                    >
                      <PriorityPill value={idea.scores.priority} />
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">{idea.title}</p>
                        <p className="mt-0.5 truncate text-xs text-muted">
                          {idea.channel?.name ?? "—"} · {idea.topic}
                          {idea.research_date && ` · ${formatDayHeading(idea.research_date)}`}
                        </p>
                      </div>
                      <ArrowRight className="size-4 shrink-0 text-muted" />
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Topik Lintas Channel</CardTitle>
          </CardHeader>
          <CardContent>
            {data.trending_topics.length === 0 ? (
              <p className="text-sm text-muted">Belum ada data riset dalam 14 hari terakhir.</p>
            ) : (
              // Grouped by research_date, same as the ideas list: a topic from a few
              // days ago must stay visible under its own date rather than silently
              // disappearing the moment newer research comes in — nothing here gets
              // replaced, only added to.
              <GroupedByDate
                items={data.trending_topics.slice(0, 15)}
                keyOf={(topic) => `${topic.research_date}-${topic.topic_key}`}
                dateOf={(topic) => topic.research_date}
                renderItem={(topic) => (
                  <div className="flex items-start gap-2.5">
                    <Compass className="mt-0.5 size-3.5 shrink-0 text-muted" />
                    <div className="min-w-0">
                      <p className="truncate text-sm">{topic.title}</p>
                      <p className="text-xs text-muted">
                        {topic.sources} sumber · {topic.mentions} penyebutan
                      </p>
                    </div>
                  </div>
                )}
              />
            )}
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Ide per Channel</CardTitle>
          </CardHeader>
          <CardContent>
            {data.ideas_by_channel.length === 0 ? (
              <p className="text-sm text-muted">Belum ada channel.</p>
            ) : (
              <ul className="space-y-2">
                {data.ideas_by_channel.map((channel) => (
                  <li key={channel.id}>
                    <Link
                      href={`/research/channels/${channel.id}`}
                      className="flex items-center justify-between gap-3 rounded-xl border border-border-subtle bg-surface px-3 py-2.5 transition-colors hover:border-accent/40"
                    >
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium">{channel.name}</p>
                        <p className="truncate text-xs text-muted">
                          {channel.niche ?? "Niche belum diatur"} ·{" "}
                          {channel.scheduler_enabled
                            ? `berikutnya ${formatUpcomingTime(channel.next_research_at)}`
                            : "scheduler nonaktif"}
                        </p>
                      </div>
                      <div className="shrink-0 text-right">
                        <p className="text-sm font-semibold tabular-nums">{channel.ideas_count}</p>
                        <p className="text-[10px] text-muted">+{channel.ideas_today_count} hari ini</p>
                      </div>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex items-center justify-between">
            <CardTitle>Riset Terakhir</CardTitle>
            <Link href="/research/runs" className="text-xs text-accent-2 hover:underline">
              Riwayat
            </Link>
          </CardHeader>
          <CardContent>
            {data.recent_runs.length === 0 ? (
              <p className="text-sm text-muted">Belum ada riset dijalankan.</p>
            ) : (
              <ul className="space-y-2">
                {data.recent_runs.map((run) => (
                  <li
                    key={run.id}
                    className="flex items-center justify-between gap-3 rounded-xl border border-border-subtle bg-surface px-3 py-2.5"
                  >
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium">{run.channel?.name ?? `Run #${run.id}`}</p>
                      <p className="truncate text-xs text-muted">
                        {run.message ?? "—"} · {formatRelativeTime(run.created_at)}
                      </p>
                    </div>
                    <StatusBadge status={run.status} />
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

function StatCard({ icon, label, value }: { icon: React.ReactNode; label: string; value: number | string }) {
  return (
    <Card className="p-4">
      <div className="flex items-center gap-2 text-muted">
        {icon}
        <span className="text-xs">{label}</span>
      </div>
      <p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p>
    </Card>
  );
}

function SchedulerFact({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
  return (
    <div className="flex items-start gap-2.5">
      <span className="mt-0.5">{icon}</span>
      <div>
        <p className="text-xs text-muted">{label}</p>
        <p className="mt-0.5 text-sm font-medium">{value}</p>
      </div>
    </div>
  );
}
