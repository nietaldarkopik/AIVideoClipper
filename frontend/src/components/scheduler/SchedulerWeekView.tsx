"use client";

import { useEffect, useMemo, useRef, useState, type DragEvent } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { clsx } from "clsx";
import { PLATFORM_LABELS } from "@/components/social/platforms";
import type { SocialPost } from "@/lib/types";

const ROW_HEIGHT = 56; // px per hour
const CHIP_HEIGHT = 20;
// Chips are point-in-time schedules, not real events with a duration — this is
// only used to decide when two chips are "close enough" to need side-by-side
// layout instead of overlapping outright.
const CHIP_VISUAL_DURATION_MIN = 30;
const SNAP_MINUTES = 15;
const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
const HOURS = Array.from({ length: 24 }, (_, i) => i);
const LOCKED_STATUSES = new Set(["published", "uploading", "publishing"]);

const PILL_TONE: Record<string, string> = {
  scheduled: "bg-accent/20 text-accent-2 border-accent/40",
  ready: "bg-white/10 text-foreground border-white/20",
  published: "bg-success/20 text-success border-success/40",
  failed: "bg-danger/20 text-danger border-danger/40",
  retrying: "bg-warning/20 text-warning border-warning/40",
  uploading: "bg-accent/20 text-accent-2 border-accent/40",
  publishing: "bg-accent/20 text-accent-2 border-accent/40",
  cancelled: "bg-white/5 text-muted border-white/10",
};

function startOfWeek(d: Date): Date {
  const date = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  date.setDate(date.getDate() - date.getDay());
  return date;
}

function addDays(d: Date, n: number): Date {
  const date = new Date(d);
  date.setDate(date.getDate() + n);
  return date;
}

function sameDay(a: Date, b: Date) {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

function minutesOfDay(date: Date): number {
  return date.getHours() * 60 + date.getMinutes();
}

type Laid = { post: SocialPost; col: number; cols: number; startMin: number };

// Greedy interval-graph coloring: posts within CHIP_VISUAL_DURATION_MIN of
// each other share the day's width side-by-side instead of stacking on top of
// one another. Not optimal packing, just enough to keep close-together chips
// readable and clickable.
function layoutDayPosts(dayPosts: SocialPost[]): Laid[] {
  const items = dayPosts
    .map((post) => ({ post, startMin: minutesOfDay(new Date(post.scheduled_at as string)) }))
    .sort((a, b) => a.startMin - b.startMin);

  type Item = (typeof items)[number];
  const active: { endMin: number; col: number }[] = [];
  let cluster: { item: Item; col: number }[] = [];
  const clusters: { item: Item; col: number }[][] = [];
  const assigned: { item: Item; col: number }[] = [];

  for (const item of items) {
    for (let i = active.length - 1; i >= 0; i--) {
      if (active[i].endMin <= item.startMin) active.splice(i, 1);
    }
    if (active.length === 0 && cluster.length > 0) {
      clusters.push(cluster);
      cluster = [];
    }
    const usedCols = new Set(active.map((a) => a.col));
    let col = 0;
    while (usedCols.has(col)) col++;
    active.push({ endMin: item.startMin + CHIP_VISUAL_DURATION_MIN, col });
    const entry = { item, col };
    cluster.push(entry);
    assigned.push(entry);
  }
  if (cluster.length > 0) clusters.push(cluster);

  const colsByItem = new Map<Item, number>();
  for (const c of clusters) {
    const maxCol = Math.max(...c.map((x) => x.col));
    for (const { item } of c) colsByItem.set(item, maxCol + 1);
  }

  return assigned.map(({ item, col }) => ({
    post: item.post,
    col,
    cols: colsByItem.get(item) ?? 1,
    startMin: item.startMin,
  }));
}

export function SchedulerWeekView({
  posts,
  onSelectPost,
  onReschedule,
  onRangeChange,
}: {
  posts: SocialPost[];
  onSelectPost: (post: SocialPost) => void;
  onReschedule: (post: SocialPost, newScheduledAtIso: string) => void;
  // Reports the currently displayed 7-day range so the parent can scope its
  // fetch to it (see scheduler/page.tsx) — without this, a fixed-size fetch
  // sorted some other way can silently omit posts that belong in this week
  // once the account has enough total rows, which looks exactly like "items
  // disappeared" even though they're still in the database untouched.
  onRangeChange?: (fromIso: string, toIso: string) => void;
}) {
  const [weekStart, setWeekStart] = useState(() => startOfWeek(new Date()));
  const [dragOverDay, setDragOverDay] = useState<number | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  const columnRefs = useRef<(HTMLDivElement | null)[]>([]);

  const days = useMemo(() => Array.from({ length: 7 }, (_, i) => addDays(weekStart, i)), [weekStart]);
  const today = new Date();

  useEffect(() => {
    if (!scrollRef.current) return;
    const targetHour = Math.max(0, today.getHours() - 2);
    scrollRef.current.scrollTop = targetHour * ROW_HEIGHT;
    // Only re-scroll when the visible week changes (e.g. "This week" click) —
    // deliberately not depending on `today`/posts so a live refresh doesn't
    // yank the user's scroll position back.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [weekStart]);

  useEffect(() => {
    onRangeChange?.(weekStart.toISOString(), addDays(weekStart, 7).toISOString());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [weekStart]);

  const postsByDay = useMemo(() => {
    const dated = posts.filter((p) => p.scheduled_at);
    return (date: Date) => dated.filter((p) => sameDay(new Date(p.scheduled_at as string), date));
  }, [posts]);

  const rangeLabel =
    `${weekStart.toLocaleDateString(undefined, { month: "short", day: "numeric" })} – ` +
    addDays(weekStart, 6).toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });

  function handleDrop(e: DragEvent<HTMLDivElement>, dayIndex: number) {
    e.preventDefault();
    setDragOverDay(null);
    const postId = Number(e.dataTransfer.getData("text/plain"));
    const post = posts.find((p) => p.id === postId);
    const columnEl = columnRefs.current[dayIndex];
    if (!post || !columnEl) return;

    const rect = columnEl.getBoundingClientRect();
    const offsetY = e.clientY - rect.top;
    let totalMinutes = (offsetY / (ROW_HEIGHT * 24)) * (24 * 60);
    totalMinutes = Math.round(totalMinutes / SNAP_MINUTES) * SNAP_MINUTES;
    totalMinutes = Math.max(0, Math.min(24 * 60 - 1, totalMinutes));

    const target = new Date(days[dayIndex]);
    target.setHours(0, 0, 0, 0);
    target.setMinutes(totalMinutes);

    onReschedule(post, target.toISOString());
  }

  return (
    <div>
      <div className="mb-3 flex items-center justify-between">
        <h3 className="text-sm font-semibold">{rangeLabel}</h3>
        <div className="flex items-center gap-1.5">
          <button
            onClick={() => setWeekStart(addDays(weekStart, -7))}
            className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
          >
            <ChevronLeft className="size-4" />
          </button>
          <button
            onClick={() => setWeekStart(startOfWeek(new Date()))}
            className="rounded-lg px-2.5 py-1 text-xs text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
          >
            This week
          </button>
          <button
            onClick={() => setWeekStart(addDays(weekStart, 7))}
            className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
          >
            <ChevronRight className="size-4" />
          </button>
        </div>
      </div>

      <div className="rounded-xl border border-border-subtle overflow-hidden">
        <div className="grid grid-cols-[56px_repeat(7,1fr)] border-b border-border-subtle bg-surface-elevated">
          <div />
          {days.map((d, i) => (
            <div
              key={i}
              className={clsx("px-2 py-2 text-center text-xs font-medium", sameDay(d, today) ? "text-accent-2" : "text-muted")}
            >
              {WEEKDAYS[d.getDay()]} <span className={sameDay(d, today) ? "font-semibold" : ""}>{d.getDate()}</span>
            </div>
          ))}
        </div>

        <div ref={scrollRef} className="max-h-[640px] overflow-y-auto">
          <div className="grid grid-cols-[56px_repeat(7,1fr)]">
            <div className="relative" style={{ height: ROW_HEIGHT * 24 }}>
              {HOURS.map((h) => (
                <div
                  key={h}
                  className="absolute left-0 right-0 border-t border-border-subtle px-1.5 text-[10px] text-muted"
                  style={{ top: h * ROW_HEIGHT }}
                >
                  {String(h).padStart(2, "0")}:00
                </div>
              ))}
            </div>

            {days.map((d, dayIndex) => {
              const laidOut = layoutDayPosts(postsByDay(d));
              const isToday = sameDay(d, today);
              return (
                <div
                  key={dayIndex}
                  ref={(el) => {
                    columnRefs.current[dayIndex] = el;
                  }}
                  onDragOver={(e) => {
                    e.preventDefault();
                    setDragOverDay(dayIndex);
                  }}
                  onDragLeave={() => setDragOverDay((cur) => (cur === dayIndex ? null : cur))}
                  onDrop={(e) => handleDrop(e, dayIndex)}
                  className={clsx("relative border-l border-border-subtle", dragOverDay === dayIndex && "bg-accent/5")}
                  style={{ height: ROW_HEIGHT * 24 }}
                >
                  {HOURS.map((h) => (
                    <div
                      key={h}
                      className="absolute left-0 right-0 border-t border-border-subtle"
                      style={{ top: h * ROW_HEIGHT }}
                    />
                  ))}

                  {isToday && (
                    <div
                      className="absolute left-0 right-0 z-10 h-px bg-accent-2"
                      style={{ top: (minutesOfDay(today) / 60) * ROW_HEIGHT }}
                    />
                  )}

                  {laidOut.map(({ post, col, cols, startMin }) => {
                    const locked = LOCKED_STATUSES.has(post.status);
                    return (
                      <button
                        key={post.id}
                        type="button"
                        draggable={!locked}
                        onDragStart={(e) => {
                          e.dataTransfer.setData("text/plain", String(post.id));
                          e.dataTransfer.effectAllowed = "move";
                        }}
                        onClick={() => onSelectPost(post)}
                        title={`${PLATFORM_LABELS[post.platform] ?? post.platform} — ${post.social_account?.account_name ?? ""}`}
                        className={clsx(
                          "absolute overflow-hidden rounded-md border px-1 text-left text-[10px] font-medium leading-tight",
                          PILL_TONE[post.status] ?? "bg-white/10 text-foreground border-white/20",
                          locked ? "cursor-pointer" : "cursor-grab active:cursor-grabbing"
                        )}
                        style={{
                          top: (startMin / 60) * ROW_HEIGHT + 1,
                          height: CHIP_HEIGHT,
                          left: `calc(${(col / cols) * 100}% + 1px)`,
                          width: `calc(${100 / cols}% - 2px)`,
                        }}
                      >
                        {new Date(post.scheduled_at as string).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}{" "}
                        {PLATFORM_LABELS[post.platform] ?? post.platform}
                      </button>
                    );
                  })}
                </div>
              );
            })}
          </div>
        </div>
      </div>
    </div>
  );
}
