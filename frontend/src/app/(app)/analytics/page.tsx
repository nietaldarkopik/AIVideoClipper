"use client";

import { useState } from "react";
import Link from "next/link";
import {
  BarChart3,
  Eye,
  Heart,
  MessageCircle,
  Share2,
  ExternalLink,
  Info,
} from "lucide-react";
import { useApi } from "@/lib/hooks";
import { formatCount, formatRelativeTime } from "@/lib/format";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { PLATFORM_COLORS, PLATFORM_LABELS } from "@/components/social/platforms";
import { TrendChart } from "@/components/analytics/TrendChart";
import type { AnalyticsResponse } from "@/lib/types";

const RANGES = [
  { days: 7, label: "7 hari" },
  { days: 30, label: "30 hari" },
  { days: 90, label: "90 hari" },
];

function StatTile({
  icon: Icon,
  label,
  value,
  hint,
}: {
  icon: typeof Eye;
  label: string;
  value: string;
  hint?: string;
}) {
  return (
    <Card className="p-5">
      <div className="flex items-center justify-between">
        <span className="text-xs font-medium text-muted">{label}</span>
        <Icon className="size-4 text-accent-2" />
      </div>
      <p className="mt-2 text-2xl font-semibold">{value}</p>
      {hint && <p className="mt-1 text-xs text-muted">{hint}</p>}
    </Card>
  );
}

export default function AnalyticsPage() {
  const [days, setDays] = useState(30);
  const { data, isLoading } = useApi<AnalyticsResponse>(`/analytics?days=${days}`);

  const totals = data?.totals;
  // Bar widths are relative to the best-performing platform rather than to an
  // absolute scale — with one platform connected the bar would otherwise always
  // render as a sliver of an invisible maximum.
  const maxPlatformViews = Math.max(1, ...(data?.by_platform ?? []).map((p) => p.views));

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Analytics</h1>
          <p className="mt-1 text-sm text-muted">
            Performa klip yang sudah tayang, ditarik langsung dari tiap platform.
          </p>
        </div>
        <div className="flex rounded-xl border border-border-subtle bg-surface p-1">
          {RANGES.map((range) => (
            <button
              key={range.days}
              onClick={() => setDays(range.days)}
              className={
                "cursor-pointer rounded-lg px-3 py-1.5 text-xs font-medium transition-colors " +
                (days === range.days ? "bg-accent/15 text-accent-2" : "text-muted hover:text-foreground")
              }
            >
              {range.label}
            </button>
          ))}
        </div>
      </div>

      {isLoading || !data || !totals ? (
        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
      ) : totals.posts === 0 ? (
        <EmptyState
          icon={<BarChart3 className="size-6" />}
          title="Belum ada post yang tayang"
          description={`Belum ada klip yang dipublikasikan dalam ${days} hari terakhir. Jadwalkan lewat Scheduler, dan angkanya muncul di sini setelah tayang.`}
        />
      ) : (
        <>
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <StatTile
              icon={Eye}
              label="Views"
              value={formatCount(totals.views)}
              hint={`${totals.posts} post tayang`}
            />
            <StatTile icon={Heart} label="Likes" value={formatCount(totals.likes)} />
            <StatTile icon={MessageCircle} label="Comments" value={formatCount(totals.comments)} />
            <StatTile
              icon={Share2}
              label="Engagement"
              value={`${totals.engagement_rate}%`}
              hint="likes + komentar + share / views"
            />
          </div>

          {/* Sync freshness is part of the data: flat numbers because the scheduler
              is stopped look identical to flat numbers because nobody watched. */}
          <div className="flex flex-wrap items-center gap-2 text-xs text-muted">
            <Info className="size-3.5" />
            <span>
              Terakhir disinkronkan {formatRelativeTime(data.last_synced_at)} — otomatis tiap jam lewat{" "}
              <code className="text-[11px]">social:sync-metrics</code>.
            </span>
            {data.awaiting_first_sync > 0 && (
              <Badge tone="default">{data.awaiting_first_sync} post belum tersinkron</Badge>
            )}
          </div>

          {/* Small multiples, not one plot with two y-axes: views and likes differ
              by orders of magnitude, and a second axis would imply a correlation
              the data doesn't contain. */}
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card className="p-5">
              <TrendChart
                points={data.timeseries.map((p) => ({ date: p.date, value: p.views }))}
                label="Views per hari"
                color="var(--color-accent)"
                emptyHint="Tren muncul setelah sinkronisasi berjalan beberapa hari"
              />
            </Card>
            <Card className="p-5">
              <TrendChart
                points={data.timeseries.map((p) => ({ date: p.date, value: p.likes }))}
                label="Likes per hari"
                color="var(--color-accent-2)"
                emptyHint="Tren muncul setelah sinkronisasi berjalan beberapa hari"
              />
            </Card>
          </div>

          <Card className="p-5">
            <h3 className="text-sm font-semibold">Per platform</h3>
            <div className="mt-4 space-y-3">
              {data.by_platform.map((row) => (
                <div key={row.platform}>
                  <div className="flex items-center justify-between gap-3 text-xs">
                    <span className="font-medium">{PLATFORM_LABELS[row.platform] ?? row.platform}</span>
                    <span className="text-muted">
                      {formatCount(row.views)} views · {row.posts} post · rata-rata {formatCount(row.avg_views)}
                    </span>
                  </div>
                  <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-surface-elevated">
                    <div
                      className="h-full rounded-full"
                      style={{
                        width: `${Math.max(2, (row.views / maxPlatformViews) * 100)}%`,
                        backgroundColor: PLATFORM_COLORS[row.platform] ?? "var(--color-accent)",
                      }}
                    />
                  </div>
                </div>
              ))}
            </div>
          </Card>

          <Card className="p-5">
            <h3 className="text-sm font-semibold">Post terbaik</h3>
            {data.top_posts.length === 0 ? (
              <p className="mt-3 text-sm text-muted">
                Belum ada post yang punya angka — tunggu sinkronisasi berikutnya.
              </p>
            ) : (
              // Scrolls inside its own container rather than widening the page —
              // this table is the widest thing in the app on a phone.
              <div className="mt-4 -mx-5 overflow-x-auto px-5">
                <table className="w-full min-w-[560px] text-sm">
                  <thead>
                    <tr className="border-b border-border-subtle text-left text-xs text-muted">
                      <th className="pb-2 font-medium">Klip</th>
                      <th className="pb-2 font-medium">Platform</th>
                      <th className="pb-2 text-right font-medium">Views</th>
                      <th className="pb-2 text-right font-medium">Likes</th>
                      <th className="pb-2 text-right font-medium">Komentar</th>
                      <th className="pb-2" />
                    </tr>
                  </thead>
                  <tbody>
                    {data.top_posts.map((post) => (
                      <tr key={post.id} className="border-b border-border-subtle/60 last:border-0">
                        <td className="py-2.5 pr-3">
                          <Link
                            href={`/clips/${post.clip_id}`}
                            className="line-clamp-1 font-medium hover:text-accent-2"
                          >
                            {post.title ?? `Klip #${post.clip_id}`}
                          </Link>
                          <p className="text-xs text-muted">
                            {post.account_name} · {formatRelativeTime(post.published_at)}
                          </p>
                        </td>
                        <td className="py-2.5 pr-3">
                          <span
                            className="text-xs font-medium"
                            style={{ color: PLATFORM_COLORS[post.platform] ?? undefined }}
                          >
                            {PLATFORM_LABELS[post.platform] ?? post.platform}
                          </span>
                        </td>
                        <td className="py-2.5 text-right tabular-nums">
                          {formatCount(post.metrics?.views ?? 0)}
                        </td>
                        <td className="py-2.5 text-right tabular-nums">
                          {formatCount(post.metrics?.likes ?? 0)}
                        </td>
                        <td className="py-2.5 text-right tabular-nums">
                          {formatCount(post.metrics?.comments ?? 0)}
                        </td>
                        <td className="py-2.5 pl-3 text-right">
                          {post.post_url && (
                            <a
                              href={post.post_url}
                              target="_blank"
                              rel="noreferrer"
                              aria-label="Buka post di platform"
                              className="inline-flex text-muted hover:text-foreground"
                            >
                              <ExternalLink className="size-4" />
                            </a>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  );
}
