"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Lightbulb, Search } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { Card } from "@/components/ui/Card";
import { Input, Select } from "@/components/ui/Input";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { PriorityPill } from "@/components/research/ScoreBar";
import { GroupedByDate } from "@/components/research/GroupedByDate";
import { formatRelativeTime } from "@/lib/format";
import type { ContentChannel, ContentIdea, Paginated } from "@/lib/types";

type DatePreset = "" | "today" | "yesterday" | "7d" | "30d" | "custom";

/** Local YYYY-MM-DD — NOT toISOString(), which shifts by a day near local midnight. */
function toDateInputValue(date: Date): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

/** @returns [date_from, date_to] as YYYY-MM-DD, or [null, null] for "Semua Tanggal". */
function resolveDateRange(preset: DatePreset, customFrom: string, customTo: string): [string | null, string | null] {
  const today = new Date();

  switch (preset) {
    case "today":
      return [toDateInputValue(today), toDateInputValue(today)];
    case "yesterday": {
      const yesterday = new Date(today);
      yesterday.setDate(today.getDate() - 1);
      return [toDateInputValue(yesterday), toDateInputValue(yesterday)];
    }
    case "7d": {
      const from = new Date(today);
      from.setDate(today.getDate() - 6);
      return [toDateInputValue(from), toDateInputValue(today)];
    }
    case "30d": {
      const from = new Date(today);
      from.setDate(today.getDate() - 29);
      return [toDateInputValue(from), toDateInputValue(today)];
    }
    case "custom":
      return [customFrom || null, customTo || null];
    default:
      return [null, null];
  }
}

function IdeasPageInner() {
  const searchParams = useSearchParams();

  const [channelId, setChannelId] = useState(searchParams.get("content_channel_id") ?? "");
  const [status, setStatus] = useState(searchParams.get("status") ?? "");
  const [sort, setSort] = useState("priority");
  const [query, setQuery] = useState("");
  const [datePreset, setDatePreset] = useState<DatePreset>("");
  const [customFrom, setCustomFrom] = useState("");
  const [customTo, setCustomTo] = useState("");

  const { data: channels } = useApi<{ data: ContentChannel[] }>("/content-channels");

  const params = new URLSearchParams({ per_page: "30", sort });
  if (channelId) params.set("content_channel_id", channelId);
  if (status) params.set("status", status);
  if (query.trim()) params.set("q", query.trim());

  const [dateFrom, dateTo] = resolveDateRange(datePreset, customFrom, customTo);
  if (dateFrom) params.set("date_from", dateFrom);
  if (dateTo) params.set("date_to", dateTo);

  const { data, isLoading } = useApi<Paginated<ContentIdea>>(`/content-ideas?${params.toString()}`);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Ide Konten</h1>
        <p className="mt-1 text-sm text-muted">
          Hasil riset harian, sudah diperingkat. Setiap ide menyimpan sumber riset yang mendasarinya.
        </p>
      </div>

      <Card className="p-3">
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
          <div className="relative">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
            <Input
              className="pl-9"
              placeholder="Cari judul atau topik..."
              value={query}
              onChange={(e) => setQuery(e.target.value)}
            />
          </div>
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
            <option value="idea">Ide baru</option>
            <option value="selected">Dipilih</option>
            <option value="scripting">Penulisan naskah</option>
            <option value="draft">Draft</option>
            <option value="approved">Disetujui</option>
            <option value="published">Terbit</option>
            <option value="rejected">Ditolak</option>
          </Select>
          <Select value={sort} onChange={(e) => setSort(e.target.value)}>
            <option value="priority">Urut: Prioritas</option>
            <option value="trend">Urut: Trend</option>
            <option value="relevance">Urut: Relevansi</option>
            <option value="freshness">Urut: Kesegaran</option>
            <option value="created">Urut: Terbaru</option>
          </Select>
        </div>

        <div className="mt-2 flex flex-wrap items-center gap-2">
          <Select
            className="w-auto"
            value={datePreset}
            onChange={(e) => setDatePreset(e.target.value as DatePreset)}
          >
            <option value="">Semua Tanggal</option>
            <option value="today">Hari Ini</option>
            <option value="yesterday">Kemarin</option>
            <option value="7d">7 Hari Terakhir</option>
            <option value="30d">30 Hari Terakhir</option>
            <option value="custom">Rentang Kustom...</option>
          </Select>

          {datePreset === "custom" && (
            <>
              <Input
                type="date"
                className="w-auto"
                value={customFrom}
                max={customTo || undefined}
                onChange={(e) => setCustomFrom(e.target.value)}
              />
              <span className="text-xs text-muted">sampai</span>
              <Input
                type="date"
                className="w-auto"
                value={customTo}
                min={customFrom || undefined}
                onChange={(e) => setCustomTo(e.target.value)}
              />
            </>
          )}
        </div>
      </Card>

      {isLoading || !data ? (
        <div className="space-y-2">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-20" />
          ))}
        </div>
      ) : data.data.length === 0 ? (
        <EmptyState
          icon={<Lightbulb className="size-8" />}
          title="Tidak ada ide yang cocok"
          description="Ubah filter, atau jalankan riset dari salah satu channel."
        />
      ) : (
        // Grouped by research_date rather than a flat list: each day's research run
        // ADDS a batch of ideas rather than replacing the previous day's, and a flat
        // score-sorted list made that invisible — an old high-scoring idea could
        // bury today's results indefinitely, indistinguishable from them having
        // been silently dropped. The backend already orders by research_date first
        // (see ContentIdeaController), so consecutive items share a date group.
        <GroupedByDate
          items={data.data}
          keyOf={(idea) => idea.id}
          dateOf={(idea) => idea.research_date}
          renderGroupCount={(count) => `${count} ide`}
          renderItem={(idea) => (
            <Link
              href={`/research/ideas/${idea.id}`}
              className="flex items-start gap-3 rounded-2xl border border-border-subtle bg-surface p-4 transition-colors hover:border-accent/40"
            >
              <PriorityPill value={idea.scores.priority} />

              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <p className="text-sm font-medium">{idea.title}</p>
                  <StatusBadge status={idea.status} />
                </div>

                {idea.short_description && (
                  <p className="mt-1 line-clamp-2 text-xs text-muted">{idea.short_description}</p>
                )}

                <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                  <span>{idea.channel?.name ?? "—"}</span>
                  <span>trend {idea.scores.trend}</span>
                  <span>relevansi {idea.scores.relevance}</span>
                  <span>{idea.sources_count ?? idea.sources?.length ?? 0} sumber</span>
                  <span>{formatRelativeTime(idea.created_at)}</span>
                </div>

                {idea.keywords.length > 0 && (
                  <div className="mt-2 flex flex-wrap gap-1">
                    {idea.keywords.slice(0, 5).map((keyword) => (
                      <Badge key={keyword} tone="muted" className="text-[10px]">
                        {keyword}
                      </Badge>
                    ))}
                  </div>
                )}
              </div>
            </Link>
          )}
        />
      )}
    </div>
  );
}

export default function ResearchIdeasPage() {
  // useSearchParams needs a Suspense boundary in the app router — same pattern as
  // the content-briefs page.
  return (
    <Suspense fallback={<Skeleton className="h-64" />}>
      <IdeasPageInner />
    </Suspense>
  );
}
