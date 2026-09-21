"use client";

import { useCallback, useRef, useState } from "react";
import { pxToTime, snapTime } from "./timelineMath";

export type DragKind = "start" | "end" | "move";

interface Window {
  start: number;
  end: number;
}

interface DragSession extends Window {
  kind: DragKind;
  originClientX: number;
}

/**
 * Generalizes the pointer-capture drag idiom already hand-rolled in
 * TimelineTrack.tsx / ClipVideoPreview.tsx / ManualCropEditor.tsx (capture on
 * pointer-down, stage moves in local "live" state, commit once on pointer-up)
 * over a single {start,end} time window — used for every layer/audio/caption/
 * effect block on the multi-track timeline. Video segments keep their own
 * neighbor-aware variant in TimelineTrack.tsx since layer windows, unlike
 * segments, are allowed to freely overlap each other.
 */
export function useDragWindow({
  pxPerSecond,
  min = 0,
  max = Infinity,
  minLength = 0.2,
  snapTargets,
  onChange,
}: {
  pxPerSecond: number;
  min?: number;
  max?: number;
  minLength?: number;
  // Times (same axis as the window) that the dragged edge should stick to —
  // the playhead, the clip bounds, every other block's edges. Snapping is
  // disabled entirely when omitted or empty.
  snapTargets?: number[];
  onChange: (window: Window) => void;
}) {
  const dragRef = useRef<DragSession | null>(null);
  const [live, setLive] = useState<Window | null>(null);
  // Which target the current drag is stuck to, if any — the timeline draws a
  // guide line at it so the snap is visible rather than just felt.
  const [snappedTo, setSnappedTo] = useState<number | null>(null);

  const startDrag = useCallback(
    (kind: DragKind, current: Window) => (e: React.PointerEvent) => {
      e.stopPropagation();
      (e.target as HTMLElement).setPointerCapture(e.pointerId);
      dragRef.current = { kind, start: current.start, end: current.end, originClientX: e.clientX };
      setLive(current);
    },
    []
  );

  const handlePointerMove = useCallback(
    (e: React.PointerEvent) => {
      const drag = dragRef.current;
      if (!drag) return;
      const deltaSeconds = pxToTime(e.clientX - drag.originClientX, pxPerSecond);
      // A fixed ~7px pull, converted to seconds at the current zoom, so the
      // magnetism feels identical at every scale (see snapTime()).
      const tolerance = snapTargets?.length ? pxToTime(7, pxPerSecond) : 0;
      let snapped: number | null = null;

      const stick = (value: number): number => {
        if (!tolerance) return value;
        const result = snapTime(value, snapTargets!, tolerance);
        if (result !== value) snapped = result;
        return result;
      };

      let next: Window;
      if (drag.kind === "start") {
        const raw = stick(drag.start + deltaSeconds);
        next = { start: Math.max(min, Math.min(raw, drag.end - minLength)), end: drag.end };
      } else if (drag.kind === "end") {
        const raw = stick(drag.end + deltaSeconds);
        next = { start: drag.start, end: Math.min(max, Math.max(raw, drag.start + minLength)) };
      } else {
        const span = drag.end - drag.start;
        // Moving a whole block: try to stick either edge, preferring whichever
        // lands closer, so a block snaps flush against a neighbour on its left
        // or its right rather than only ever by its start.
        const rawStart = drag.start + deltaSeconds;
        const stuckStart = stick(rawStart);
        const stuckEnd = stick(rawStart + span) - span;
        const candidate =
          Math.abs(stuckStart - rawStart) <= Math.abs(stuckEnd - rawStart) ? stuckStart : stuckEnd;
        const newStart = Math.max(min, Math.min(max - span, candidate));
        next = { start: newStart, end: newStart + span };
      }

      setSnappedTo(snapped);
      setLive(next);
    },
    [pxPerSecond, min, max, minLength, snapTargets]
  );

  const handlePointerUp = useCallback(() => {
    if (live) onChange({ start: Number(live.start.toFixed(2)), end: Number(live.end.toFixed(2)) });
    dragRef.current = null;
    setLive(null);
    setSnappedTo(null);
  }, [live, onChange]);

  return { live, snappedTo, dragging: live != null, startDrag, handlePointerMove, handlePointerUp };
}
