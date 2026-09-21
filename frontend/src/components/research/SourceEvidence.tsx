"use client";

import { ExternalLink } from "lucide-react";
import { formatRelativeTime } from "@/lib/format";
import type { ContentIdeaSource } from "@/lib/types";

/**
 * "View Research" — the sources that caused a topic to be recommended.
 *
 * Every row is a real retrieved URL. Engagement numbers are shown only when the
 * provider actually returned them; a missing metric is omitted rather than
 * rendered as 0, which would read as "nobody engaged" instead of "not reported".
 */
export function SourceEvidence({ sources }: { sources: ContentIdeaSource[] }) {
  if (sources.length === 0) {
    return <p className="text-sm text-muted">Tidak ada bukti riset tersimpan untuk ide ini.</p>;
  }

  return (
    <ul className="space-y-2">
      {sources.map((source) => (
        <li key={source.id} className="rounded-xl border border-border-subtle bg-surface p-3">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <div className="flex items-center gap-2">
                <span className="rounded-md bg-white/5 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted">
                  {source.source_key.replace(/_/g, " ")}
                </span>
                {source.published_at && (
                  <span className="text-xs text-muted">{formatRelativeTime(source.published_at)}</span>
                )}
              </div>
              <p className="mt-1.5 truncate text-sm font-medium">{source.source_title}</p>
              {source.extracted_summary && (
                <p className="mt-1 line-clamp-2 text-xs text-muted">{source.extracted_summary}</p>
              )}
              <EngagementMetrics metrics={source.engagement_metrics} />
            </div>
            <a
              href={source.source_url}
              target="_blank"
              rel="noreferrer noopener"
              className="shrink-0 rounded-lg p-1.5 text-muted transition-colors hover:bg-white/5 hover:text-foreground"
              title="Buka sumber asli"
            >
              <ExternalLink className="size-4" />
            </a>
          </div>
        </li>
      ))}
    </ul>
  );
}

const METRIC_LABELS: Record<string, string> = {
  score: "skor",
  comments: "komentar",
  views: "views",
  likes: "likes",
  votes: "votes",
  forks: "forks",
  rank: "peringkat",
  popularity: "popularitas",
  upvote_ratio: "rasio upvote",
  discount_percent: "diskon %",
  open_issues: "isu terbuka",
};

function EngagementMetrics({ metrics }: { metrics: Record<string, number> }) {
  const entries = Object.entries(metrics ?? {}).filter(([, value]) => typeof value === "number");

  if (entries.length === 0) return null;

  return (
    <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted">
      {entries.map(([key, value]) => (
        <span key={key} className="tabular-nums">
          {METRIC_LABELS[key] ?? key.replace(/_/g, " ")}:{" "}
          <span className="font-medium text-foreground">{value.toLocaleString("id-ID")}</span>
        </span>
      ))}
    </div>
  );
}
