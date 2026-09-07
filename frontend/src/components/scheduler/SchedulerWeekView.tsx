"use client";

import { useEffect, useMemo, useRef, useState, type DragEvent } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { clsx } from "clsx";
import { PLATFORM_LABELS } from "@/components/social/platforms";
import type { SocialPost } from "@/lib/types";

// Minutes per grid row. 5 is the default because the scheduler itself can place
// posts as little as 10 minutes apart (see AutoPublishScheduler's stagger), and
// an hourly grid makes those impossible to read apart or drop precisely onto.
// Coarser steps stay available since a 5-minute grid necessarily makes a day
// several thousand pixels tall — fine for fine-tuning one afternoon, poor for
// eyeballing a whole week.
const GRANULARITIES = [5, 15, 30, 60] as const;
type Granularity = (typeof GRANULARITIES)[number];

// Row height per granularity, picked so the row's own time label stays legible
// at each step rather than being a fixed px-per-minute zoom.
const SLOT_PX: Record<Granularity, number> = { 5: 14, 15: 26, 30: 34, 60: 64 };

const CHIP_HEIGHT = 38;
const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
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

// Greedy interval-graph coloring: posts whose chips would physically overlap at
// the current zoom share the day's width side-by-side instead of stacking on top
// of one another. $overlapMinutes is derived from the chip's real height at the
// current zoom (not a fixed span) — zoomed into 5-minute rows, two posts 20
// minutes apart no longer collide and shouldn't be squeezed into half-columns.
function layoutDayPosts(dayPosts: SocialPost[], overlapMinutes: number): Laid[] {
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
    active.push({ endMin: item.startMin + overlapMinutes, col });
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
  const [granularity, setGranularity] = useState<Granularity>(5);
  const [dragOverDay, setDragOverDay] = useState<number | null>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  const columnRefs = useRef<(HTMLDivElement | null)[]>([]);

  const slotPx = SLOT_PX[granularity];
  const pxPerMinute = slotPx / granularity;
  const hourHeight = pxPerMinute * 60;
  const dayHeight = hourHeight * 24;
  // An hour row still reads as one at 60-minute steps only if it keeps its
  // half-hour tick, so that one step draws its minor lines at 30 minutes.
  const minorMinutes = granularity === 60 ? 30 : granularity;
  // Chips whose boxes would physically collide get columns — see layoutDayPosts.
  // Purely the chip's own height expressed in minutes at this zoom: at hourly
  // rows that's ~36min (what the old fixed constant approximated), at 5-minute
  // rows only ~14min, so zooming in genuinely buys separation instead of
  // leaving posts needlessly squeezed into half-width columns.
  const overlapMinutes = Math.max(1, Math.round(CHIP_HEIGHT / pxPerMinute));

  // Thousands of grid lines as real elements (288 rows x 7 columns at
  // 5-minute steps) is a lot of DOM to re-render on every 20s refresh — CSS
  // repeating gradients draw the same lines with none.
  const gridBackground = useMemo(
    () => ({
      backgroundImage: [
        `repeating-linear-gradient(to bottom, var(--border-subtle) 0 1px, transparent 1px ${hourHeight}px)`,
        `repeating-linear-gradient(to bottom, color-mix(in srgb, var(--border-subtle) 55%, transparent) 0 1px, transparent 1px ${
          minorMinutes * pxPerMinute
        }px)`,
      ].join(","),
    }),
    [hourHeight, minorMinutes, pxPerMinute]
  );

  const days = useMemo(() => Array.from({ length: 7 }, (_, i) => addDays(weekStart, i)), [weekStart]);
  const today = new Date();

  // Every row's own label, from 00:00 to 23:55 at the current step.
  const rows = useMemo(() => {
    const out: { minutes: number; isHour: boolean }[] = [];
    for (let m = 0; m < 24 * 60; m += minorMinutes) {
      out.push({ minutes: m, isHour: m % 60 === 0 });
    }
    return out;
  }, [minorMinutes]);

  useEffect(() => {
    if (!scrollRef.current) return;
    // Two hours before now, so the current time sits comfortably in view rather
    // than at the very top — matters much more at 5-minute rows, where a whole
    // day is several thousand pixels tall.
    const target = Math.max(0, (minutesOfDay(new Date()) - 120) * pxPerMinute);
    scrollRef.current.scrollTop = target;
    // Deliberately not depending on `today`/posts so a live refresh doesn't yank
    // the user's scroll position back; granularity IS included because the whole
    // canvas resizes under it and the old offset would point somewhere random.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [weekStart, granularity]);

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
    // Snapped to the grid actually on screen, so a drop lands exactly on the
    // line it was released against.
    let totalMinutes = Math.round(offsetY / pxPerMinute / granularity) * granularity;
    totalMinutes = Math.max(0, Math.min(24 * 60 - granularity, totalMinutes));

    const target = new Date(days[dayIndex]);
    target.setHours(0, 0, 0, 0);
    target.setMinutes(totalMinutes);

    onReschedule(post, target.toISOString());
  }

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold">{rangeLabel}</h3>
        <div className="flex items-center gap-3">
          <div className="flex items-center gap-1 rounded-lg bg-surface-elevated p-0.5">
            {GRANULARITIES.map((g) => (
              <button
                key={g}
                onClick={() => setGranularity(g)}
                title={`${g}-minute rows`}
                className={clsx(
                  "rounded-md px-2 py-1 text-[11px] cursor-pointer",
                  granularity === g ? "bg-accent text-white" : "text-muted hover:text-foreground"
                )}
              >
                {g}m
              </button>
            ))}
          </div>
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
      </div>

      <div className="rounded-xl border border-border-subtle overflow-hidden">
        <div className="grid grid-cols-[64px_repeat(7,1fr)] border-b border-border-subtle bg-surface-elevated">
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
          <div className="grid grid-cols-[64px_repeat(7,1fr)]">
            <div className="relative" style={{ height: dayHeight }}>
              {rows.map(({ minutes, isHour }) => (
                <div
                  key={minutes}
                  className={clsx(
                    "absolute left-0 right-0 border-t px-1.5",
                    isHour
                      ? "border-border-subtle text-[10px] font-medium text-muted"
                      : "border-border-subtle/50 text-[9px] text-muted/60"
                  )}
                  style={{ top: minutes * pxPerMinute, height: minorMinutes * pxPerMinute, lineHeight: 1.1 }}
                >
                  {String(Math.floor(minutes / 60)).padStart(2, "0")}:{String(minutes % 60).padStart(2, "0")}
                </div>
              ))}
            </div>

            {days.map((d, dayIndex) => {
              const laidOut = layoutDayPosts(postsByDay(d), overlapMinutes);
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
                  style={{ height: dayHeight, ...gridBackground }}
                >
                  {isToday && (
                    <div
                      className="absolute left-0 right-0 z-10 h-px bg-accent-2"
                      style={{ top: minutesOfDay(today) * pxPerMinute }}
                    />
                  )}

                  {laidOut.map(({ post, col, cols, startMin }) => {
                    const locked = LOCKED_STATUSES.has(post.status);
                    const timeStr = new Date(post.scheduled_at as string).toLocaleTimeString([], {
                      hour: "2-digit",
                      minute: "2-digit",
                    });
                    const platformStr = PLATFORM_LABELS[post.platform] ?? post.platform;
                    const channelStr = post.social_account?.account_name ? ` · ${post.social_account.account_name}` : "";
                    const titleStr = post.clip?.title ?? post.title ?? `Clip #${post.clip_id}`;

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
                        title={`${timeStr} — ${platformStr}${channelStr} — ${titleStr}`}
                        className={clsx(
                          "absolute overflow-hidden rounded-md border p-1 text-left text-[10px] font-medium leading-tight shadow-xs transition-shadow hover:z-20",
                          PILL_TONE[post.status] ?? "bg-white/10 text-foreground border-white/20",
                          locked ? "cursor-pointer" : "cursor-grab active:cursor-grabbing"
                        )}
                        style={{
                          top: startMin * pxPerMinute + 1,
                          height: CHIP_HEIGHT,
                          left: `calc(${(col / cols) * 100}% + 1px)`,
                          width: `calc(${100 / cols}% - 2px)`,
                        }}
                      >
                        <div className="flex items-center justify-between gap-1 font-semibold opacity-90 truncate">
                          <span>{timeStr}</span>
                          <span className="truncate opacity-75">{platformStr}{channelStr}</span>
                        </div>
                        <div className="truncate opacity-95 text-[9.5px] font-normal leading-none mt-0.5">
                          {titleStr}
                        </div>
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
