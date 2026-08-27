"use client";

import { useCallback, useRef, useState } from "react";
import { clsx } from "clsx";
import { Plus, X } from "lucide-react";
import { formatDuration } from "@/lib/format";
import type { Segment } from "@/lib/types";

type DragKind = "start" | "end" | "region";
type Drag = { index: number; kind: DragKind; originStart: number; originEnd: number; originClientX: number } | null;

const MIN_SEGMENT = 0.2;

export function TimelineTrack({
  duration,
  segments,
  currentTime,
  onChange,
  onSeek,
}: {
  duration: number;
  segments: Segment[];
  currentTime?: number;
  onChange: (segments: Segment[]) => void;
  onSeek?: (time: number) => void;
}) {
  const trackRef = useRef<HTMLDivElement>(null);
  const [drag, setDrag] = useState<Drag>(null);
  const [liveSegments, setLiveSegments] = useState<Segment[] | null>(null);

  const clamp = useCallback((t: number) => Math.max(0, Math.min(duration, t)), [duration]);

  const xToTime = useCallback(
    (clientX: number) => {
      const rect = trackRef.current?.getBoundingClientRect();
      if (!rect || rect.width === 0) return 0;
      return clamp(((clientX - rect.left) / rect.width) * duration);
    },
    [clamp, duration]
  );

  const active = liveSegments ?? segments;

  function neighborBounds(index: number) {
    const prevEnd = index > 0 ? active[index - 1].end : 0;
    const nextStart = index < active.length - 1 ? active[index + 1].start : duration;
    return { prevEnd, nextStart };
  }

  const startDrag = (index: number, kind: DragKind) => (e: React.PointerEvent) => {
    e.stopPropagation();
    (e.target as HTMLElement).setPointerCapture(e.pointerId);
    setDrag({ index, kind, originStart: active[index].start, originEnd: active[index].end, originClientX: e.clientX });
    setLiveSegments(active);
  };

  function handlePointerMove(e: React.PointerEvent) {
    if (!drag) return;
    const { prevEnd, nextStart } = neighborBounds(drag.index);
    const t = xToTime(e.clientX);
    const next = [...(liveSegments ?? active)];

    if (drag.kind === "start") {
      next[drag.index] = { start: Math.max(prevEnd, Math.min(t, drag.originEnd - MIN_SEGMENT)), end: drag.originEnd };
    } else if (drag.kind === "end") {
      next[drag.index] = { start: drag.originStart, end: Math.min(nextStart, Math.max(t, drag.originStart + MIN_SEGMENT)) };
    } else {
      const deltaSeconds = ((e.clientX - drag.originClientX) / (trackRef.current?.getBoundingClientRect().width || 1)) * duration;
      const span = drag.originEnd - drag.originStart;
      let newStart = drag.originStart + deltaSeconds;
      newStart = Math.max(prevEnd, Math.min(nextStart - span, newStart));
      next[drag.index] = { start: newStart, end: newStart + span };
    }

    setLiveSegments(next);
  }

  function handlePointerUp() {
    if (liveSegments) onChange(liveSegments.map((s) => ({ start: Number(s.start.toFixed(2)), end: Number(s.end.toFixed(2)) })));
    setDrag(null);
    setLiveSegments(null);
  }

  function handleTrackClick(e: React.MouseEvent) {
    if (drag) return;
    onSeek?.(xToTime(e.clientX));
  }

  function addSegment() {
    const anchor = currentTime ?? 0;
    // find a gap containing (or nearest to) the playhead to drop a new ~3s segment into
    const sorted = [...active].sort((a, b) => a.start - b.start);
    let gapStart = 0;
    for (const seg of sorted) {
      if (anchor < seg.start) break;
      gapStart = Math.max(gapStart, seg.end);
    }
    const gapEndCandidate = sorted.find((s) => s.start > gapStart)?.start ?? duration;
    const newStart = Math.max(gapStart, Math.min(anchor, gapEndCandidate - MIN_SEGMENT));
    const newEnd = Math.min(gapEndCandidate, newStart + Math.min(3, gapEndCandidate - newStart));
    if (newEnd - newStart < MIN_SEGMENT) return;
    onChange([...active, { start: newStart, end: newEnd }].sort((a, b) => a.start - b.start));
  }

  function removeSegment(index: number) {
    if (active.length <= 1) return;
    onChange(active.filter((_, i) => i !== index));
  }

  const pct = (t: number) => (duration > 0 ? (clamp(t) / duration) * 100 : 0);
  const totalKept = active.reduce((sum, s) => sum + Math.max(0, s.end - s.start), 0);

  return (
    <div className="select-none">
      <div
        ref={trackRef}
        onClick={handleTrackClick}
        onPointerMove={handlePointerMove}
        onPointerUp={handlePointerUp}
        className="relative h-12 w-full cursor-pointer rounded-lg bg-black/40"
      >
        {active.map((seg, i) => (
          <div key={i} className="group">
            <div
              onPointerDown={startDrag(i, "region")}
              className="absolute inset-y-0 cursor-grab bg-accent/25 active:cursor-grabbing"
              style={{ left: `${pct(seg.start)}%`, width: `${Math.max(0, pct(seg.end) - pct(seg.start))}%` }}
            />
            {active.length > 1 && (
              <button
                type="button"
                onClick={(e) => {
                  e.stopPropagation();
                  removeSegment(i);
                }}
                className="absolute top-0.5 z-20 rounded-full bg-black/60 p-0.5 text-white/70 opacity-0 hover:bg-danger hover:text-white group-hover:opacity-100 cursor-pointer"
                style={{ left: `calc(${pct(seg.start)}% + 3px)` }}
                title="Remove segment"
              >
                <X className="size-2.5" />
              </button>
            )}
            <div
              onPointerDown={startDrag(i, "start")}
              className="absolute inset-y-0 z-10 w-2.5 cursor-ew-resize rounded-l-lg bg-accent hover:brightness-110"
              style={{ left: `calc(${pct(seg.start)}% - 5px)` }}
            />
            <div
              onPointerDown={startDrag(i, "end")}
              className="absolute inset-y-0 z-10 w-2.5 cursor-ew-resize rounded-r-lg bg-accent hover:brightness-110"
              style={{ left: `calc(${pct(seg.end)}% - 5px)` }}
            />
          </div>
        ))}
        {currentTime != null && (
          <div className="pointer-events-none absolute inset-y-0 z-30 w-px bg-white" style={{ left: `${pct(currentTime)}%` }} />
        )}
      </div>

      <div className={clsx("mt-1.5 flex items-center justify-between text-[11px] text-muted", drag && "text-foreground")}>
        <span>
          {active.length} segment{active.length !== 1 ? "s" : ""} · {formatDuration(totalKept)} total
        </span>
        <button
          type="button"
          onClick={addSegment}
          className="flex items-center gap-1 rounded-lg px-1.5 py-0.5 text-accent-2 hover:bg-white/5 cursor-pointer"
        >
          <Plus className="size-3" />
          Add segment at playhead
        </button>
      </div>
    </div>
  );
}
