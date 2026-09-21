"use client";

import { useCallback, useRef, useState } from "react";
import { Shuffle, X } from "lucide-react";
import { pxToTime, snapTime, trackWidthPx } from "./timelineMath";
import type { Segment } from "@/lib/types";

type DragKind = "start" | "end" | "region";
type Drag = { index: number; kind: DragKind; originStart: number; originEnd: number; originClientX: number } | null;

export const MIN_SEGMENT = 0.2;

/**
 * The video track: the clip's kept ranges of the source video, drawn over the
 * source's filmstrip/waveform. Segments (unlike every other track's blocks)
 * can't overlap or reorder — each drag is clamped by its neighbours — so this
 * keeps its own drag handling rather than using useDragWindow.
 *
 * It renders only the track itself: the playhead spans every row and is drawn
 * once by MultiTrackTimeline, and the add/split/delete controls live in that
 * component's toolbar so they can act on whatever is selected.
 */
export function TimelineTrack({
  duration,
  segments,
  onChange,
  onSeek,
  thumbnailStripUrl,
  waveformUrl,
  pxPerSecond,
  selectedIndex,
  onSelectSegment,
  snapTargets,
  selectedTransitionIndex = null,
  onSelectTransition,
}: {
  duration: number;
  segments: Segment[];
  onChange: (segments: Segment[]) => void;
  onSeek?: (time: number) => void;
  // Both null for a video imported before this feature, generation failed, or
  // (waveform only) the source has no audio — the track falls back to its
  // original flat bar. Neither needs the tile count: the strip is stretched
  // to exactly fill the track's width regardless of how many tiles it has
  // (they're evenly spaced across the same [0, duration] range this track
  // already renders), and the waveform PNG is just stretched the same way.
  thumbnailStripUrl?: string | null;
  waveformUrl?: string | null;
  // Pixel-per-second scale shared with every other row in MultiTrackTimeline
  // so they all render at the same width and stay aligned under one
  // playhead/scroll container — see that component's `fitPxPerSecond` for how
  // it's derived (fits the container at zoom 1x regardless of the source
  // video's actual length, since that can range from seconds to hours).
  pxPerSecond: number;
  selectedIndex: number | null;
  onSelectSegment: (index: number) => void;
  snapTargets: number[];
  // The transition INTO segments[index] — see EditorSelection's "transition"
  // kind. Only ever segments[1..length-1]; a first segment has nothing before
  // it to fade from.
  selectedTransitionIndex?: number | null;
  onSelectTransition?: (index: number) => void;
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

  // Same fixed ~7px magnetism useDragWindow applies to every other track, so a
  // trim handle sticks to the playhead or a neighbouring cut just as readily.
  const stick = (t: number) => (snapTargets.length ? snapTime(t, snapTargets, pxToTime(7, pxPerSecond)) : t);

  const startDrag = (index: number, kind: DragKind) => (e: React.PointerEvent) => {
    e.stopPropagation();
    (e.target as HTMLElement).setPointerCapture(e.pointerId);
    onSelectSegment(index);
    setDrag({ index, kind, originStart: active[index].start, originEnd: active[index].end, originClientX: e.clientX });
    setLiveSegments(active);
  };

  function handlePointerMove(e: React.PointerEvent) {
    if (!drag) return;
    const { prevEnd, nextStart } = neighborBounds(drag.index);
    const t = stick(xToTime(e.clientX));
    const next = [...(liveSegments ?? active)];

    if (drag.kind === "start") {
      next[drag.index] = { start: Math.max(prevEnd, Math.min(t, drag.originEnd - MIN_SEGMENT)), end: drag.originEnd };
    } else if (drag.kind === "end") {
      next[drag.index] = { start: drag.originStart, end: Math.min(nextStart, Math.max(t, drag.originStart + MIN_SEGMENT)) };
    } else {
      const deltaSeconds = pxToTime(e.clientX - drag.originClientX, pxPerSecond);
      const span = drag.originEnd - drag.originStart;
      let newStart = stick(drag.originStart + deltaSeconds);
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

  function removeSegment(index: number) {
    if (active.length <= 1) return;
    onChange(active.filter((_, i) => i !== index));
  }

  const pct = (t: number) => (duration > 0 ? (clamp(t) / duration) * 100 : 0);

  return (
    <div
      ref={trackRef}
      onClick={handleTrackClick}
      onPointerMove={handlePointerMove}
      onPointerUp={handlePointerUp}
      className="relative h-12 shrink-0 cursor-pointer select-none overflow-hidden rounded-lg bg-black/40"
      style={{
        width: trackWidthPx(duration, pxPerSecond),
        ...(thumbnailStripUrl
          ? { backgroundImage: `url(${thumbnailStripUrl})`, backgroundSize: "100% 100%", backgroundRepeat: "no-repeat" }
          : undefined),
      }}
    >
      {/* Dims the whole strip so the accent-tinted "kept" regions below still
          read as clearly brighter/selected — without this a full-color
          filmstrip makes the trim/cut distinction hard to see at a glance. */}
      {thumbnailStripUrl && <div className="pointer-events-none absolute inset-0 bg-black/45" />}
      {waveformUrl && (
        <div
          className="pointer-events-none absolute inset-x-0 bottom-0 h-1/2 opacity-60"
          style={{ backgroundImage: `url(${waveformUrl})`, backgroundSize: "100% 100%", backgroundRepeat: "no-repeat" }}
        />
      )}

      {active.map((seg, i) => (
        <div key={i} className="group">
          <div
            onPointerDown={startDrag(i, "region")}
            onClick={(e) => {
              e.stopPropagation();
              onSelectSegment(i);
            }}
            className={
              "absolute inset-y-0 cursor-grab border-y-2 active:cursor-grabbing " +
              (selectedIndex === i ? "border-accent bg-accent/40" : "border-transparent bg-accent/25 hover:bg-accent/30")
            }
            style={{ left: `${pct(seg.start)}%`, width: `${Math.max(0, pct(seg.end) - pct(seg.start))}%` }}
          />
          {active.length > 1 && (
            <button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                removeSegment(i);
              }}
              className="absolute top-0.5 z-20 cursor-pointer rounded-full bg-black/60 p-0.5 text-white/70 opacity-0 hover:bg-danger hover:text-white group-hover:opacity-100"
              style={{ left: `calc(${pct(seg.start)}% + 3px)` }}
              title="Remove segment"
            >
              <X className="size-2.5" />
            </button>
          )}
          <div
            onPointerDown={startDrag(i, "start")}
            className="absolute inset-y-0 z-30 w-2.5 cursor-ew-resize rounded-l-lg bg-accent hover:brightness-110"
            style={{ left: `calc(${pct(seg.start)}% - 5px)` }}
          />
          <div
            onPointerDown={startDrag(i, "end")}
            className="absolute inset-y-0 z-30 w-2.5 cursor-ew-resize rounded-r-lg bg-accent hover:brightness-110"
            style={{ left: `calc(${pct(seg.end)}% - 5px)` }}
          />
        </div>
      ))}

      {/* One marker per boundary between adjacent segments — the CUT the
          transition applies to, not a moment on the source timeline. Sits at
          the midpoint of the (often nonzero, since a jump cut can skip a big
          stretch of source) gap between the two segments, which collapses to
          exactly the shared edge when they happen to be contiguous. Skipped
          entirely while a drag is live: liveSegments can transiently overlap
          or reorder mid-drag, and a marker computed off that would jump around
          distractingly until the drag settles. */}
      {!liveSegments &&
        active.slice(1).map((seg, i) => {
          const index = i + 1;
          const prevEnd = active[index - 1].end;
          // TransitionType is never "none" — an absent/null transition_in IS
          // "no transition", so presence alone is the full check.
          const hasTransition = !!seg.transition_in;
          const midpoint = (prevEnd + seg.start) / 2;
          return (
            <button
              key={`transition-${index}`}
              type="button"
              onPointerDown={(e) => e.stopPropagation()}
              onClick={(e) => {
                e.stopPropagation();
                onSelectTransition?.(index);
              }}
              title={hasTransition ? `Transition: ${seg.transition_in!.type}` : "Add a transition"}
              className={
                "absolute top-1/2 z-20 flex size-5 -translate-x-1/2 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full border shadow transition-opacity " +
                (selectedTransitionIndex === index
                  ? "border-white bg-accent text-white"
                  : hasTransition
                    ? "border-accent-2 bg-accent-2/90 text-white hover:brightness-110"
                    : "border-white/40 bg-black/70 text-white/50 opacity-60 hover:opacity-100")
              }
              style={{ left: `${pct(midpoint)}%` }}
            >
              <Shuffle className="size-2.5" />
            </button>
          );
        })}
    </div>
  );
}
