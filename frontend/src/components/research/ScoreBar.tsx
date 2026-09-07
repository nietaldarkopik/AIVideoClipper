"use client";

import { clsx } from "clsx";
import type { ContentIdea } from "@/lib/types";

/**
 * A 0-100 score with its label. Colour is derived from the value, not passed in,
 * so the same number always reads the same way across the dashboard, the list and
 * the detail view.
 */
export function ScoreBar({ label, value }: { label: string; value: number }) {
  return (
    <div>
      <div className="mb-1 flex items-center justify-between text-xs">
        <span className="text-muted">{label}</span>
        <span className="font-medium tabular-nums">{value}</span>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-white/5">
        <div className={clsx("h-full rounded-full", toneOf(value))} style={{ width: `${clamp(value)}%` }} />
      </div>
    </div>
  );
}

export function PriorityPill({ value }: { value: number }) {
  return (
    <span
      className={clsx(
        "inline-flex size-11 shrink-0 flex-col items-center justify-center rounded-xl text-sm font-semibold tabular-nums",
        value >= 75 && "bg-success/15 text-success",
        value >= 50 && value < 75 && "bg-accent/15 text-accent-2",
        value < 50 && "bg-white/5 text-muted"
      )}
      title={`Priority score ${value}/100`}
    >
      {value}
      <span className="text-[9px] font-normal opacity-70">prio</span>
    </span>
  );
}

export function ScoreGrid({ scores }: { scores: ContentIdea["scores"] }) {
  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <ScoreBar label="Trend" value={scores.trend} />
      <ScoreBar label="Relevansi" value={scores.relevance} />
      <ScoreBar label="Orisinalitas" value={scores.originality} />
      <ScoreBar label="Kesegaran" value={scores.freshness} />
      <ScoreBar label="Engagement" value={scores.engagement} />
      <ScoreBar label="Lintas Sumber" value={scores.cross_source} />
    </div>
  );
}

function clamp(value: number) {
  return Math.max(0, Math.min(100, value));
}

function toneOf(value: number) {
  if (value >= 75) return "bg-success";
  if (value >= 50) return "bg-accent";
  if (value >= 25) return "bg-warning";
  return "bg-white/20";
}
