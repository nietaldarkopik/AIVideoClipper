"use client";

import { useEffect, useMemo, useState } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { clsx } from "clsx";
import { PLATFORM_LABELS } from "@/components/social/platforms";
import type { SocialPost } from "@/lib/types";

const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

const PILL_TONE: Record<string, string> = {
  scheduled: "bg-accent/20 text-accent-2",
  ready: "bg-white/10 text-foreground",
  published: "bg-success/20 text-success",
  failed: "bg-danger/20 text-danger",
  retrying: "bg-warning/20 text-warning",
  uploading: "bg-accent/20 text-accent-2",
  publishing: "bg-accent/20 text-accent-2",
  cancelled: "bg-white/5 text-muted",
};

function sameDay(a: Date, b: Date) {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

export function SchedulerCalendar({
  posts,
  onSelectPost,
  onRangeChange,
}: {
  posts: SocialPost[];
  onSelectPost: (post: SocialPost) => void;
  // Reports the currently displayed month so the parent can scope its fetch to
  // it (see scheduler/page.tsx) — otherwise a fixed-size fetch can silently
  // omit posts that belong in this month once the account has enough rows,
  // which looks like "items disappeared" even though nothing was lost.
  onRangeChange?: (fromIso: string, toIso: string) => void;
}) {
  const [cursor, setCursor] = useState(() => {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), 1);
  });

  const cells = useMemo(() => {
    const year = cursor.getFullYear();
    const month = cursor.getMonth();
    const firstOfMonth = new Date(year, month, 1);
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const leading = firstOfMonth.getDay();

    const days: { date: Date | null }[] = [];
    for (let i = 0; i < leading; i++) days.push({ date: null });
    for (let d = 1; d <= daysInMonth; d++) days.push({ date: new Date(year, month, d) });
    while (days.length % 7 !== 0) days.push({ date: null });

    return days;
  }, [cursor]);

  useEffect(() => {
    const year = cursor.getFullYear();
    const month = cursor.getMonth();
    const from = new Date(year, month, 1);
    const to = new Date(year, month + 1, 1);
    onRangeChange?.(from.toISOString(), to.toISOString());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cursor]);

  const postsByDay = useMemo(() => {
    const dated = posts.filter((p) => p.scheduled_at);
    return (date: Date) => dated.filter((p) => sameDay(new Date(p.scheduled_at as string), date));
  }, [posts]);

  const today = new Date();
  const monthLabel = cursor.toLocaleDateString(undefined, { month: "long", year: "numeric" });

  return (
    <div>
      <div className="mb-3 flex items-center justify-between">
        <h3 className="text-sm font-semibold">{monthLabel}</h3>
        <div className="flex items-center gap-1.5">
          <button
            onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))}
            className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
          >
            <ChevronLeft className="size-4" />
          </button>
          <button
            onClick={() => setCursor(new Date(today.getFullYear(), today.getMonth(), 1))}
            className="rounded-lg px-2.5 py-1 text-xs text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
          >
            Today
          </button>
          <button
            onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))}
            className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
          >
            <ChevronRight className="size-4" />
          </button>
        </div>
      </div>

      <div className="grid grid-cols-7 gap-px overflow-hidden rounded-xl border border-border-subtle bg-border-subtle">
        {WEEKDAYS.map((w) => (
          <div key={w} className="bg-surface-elevated px-2 py-1.5 text-center text-[11px] font-medium text-muted">
            {w}
          </div>
        ))}
        {cells.map((cell, i) => {
          if (!cell.date) return <div key={i} className="min-h-24 bg-surface" />;
          const dayPosts = postsByDay(cell.date);
          const isToday = sameDay(cell.date, today);
          return (
            <div key={i} className="min-h-24 space-y-1 bg-surface p-1.5">
              <p className={clsx("text-[11px]", isToday ? "font-semibold text-accent-2" : "text-muted")}>
                {cell.date.getDate()}
              </p>
              {dayPosts.slice(0, 3).map((p) => (
                <button
                  key={p.id}
                  onClick={() => onSelectPost(p)}
                  title={`${PLATFORM_LABELS[p.platform] ?? p.platform} — ${p.social_account?.account_name ?? ""}`}
                  className={clsx(
                    "block w-full truncate rounded-md px-1.5 py-0.5 text-left text-[10px] font-medium cursor-pointer",
                    PILL_TONE[p.status] ?? "bg-white/10 text-foreground"
                  )}
                >
                  {new Date(p.scheduled_at as string).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}{" "}
                  {PLATFORM_LABELS[p.platform] ?? p.platform}
                </button>
              ))}
              {dayPosts.length > 3 && (
                <p className="px-1.5 text-[10px] text-muted">+{dayPosts.length - 3} more</p>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}
