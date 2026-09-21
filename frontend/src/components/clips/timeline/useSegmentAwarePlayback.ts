"use client";

import { useEffect, useRef } from "react";
import type { Segment } from "@/lib/types";

// Fixed pixel-independent tolerance for "close enough to a segment's own end
// that the next timeupdate tick would otherwise play into the cut-out footage
// past it" — timeupdate fires at a browser-controlled, not-quite-continuous
// rate, so landing exactly on a boundary can't be relied on.
const GAP_EPSILON = 0.05;

/**
 * Makes the preview's <video> element actually represent a multi-segment edit
 * DURING PLAYBACK, not only while scrubbing. Without this, hitting Play on a
 * clip with jump cuts plays straight through the raw SOURCE video — including
 * every stretch of footage the segments cut out — because a plain HTML5
 * <video> has no built-in concept of "skip this range". This watches the
 * video's own timeupdate events and, whenever playback reaches the end of its
 * current kept segment (or has drifted into a cut-out gap — e.g. a manual seek
 * there followed by Play), jumps straight to the next kept segment's start, so
 * continuous playback approximates the same footage order the render actually
 * produces. Reaching the end of the LAST segment pauses instead, so playback
 * doesn't run on into footage past the clip's own envelope.
 *
 * Only active while playing (`isPlaying`) — a paused scrub that happens to land
 * inside a gap is left alone; someone may be deliberately inspecting cut
 * footage. Segments are read fresh on every tick via a ref rather than
 * re-binding the listener on every edit, so dragging a segment doesn't tear
 * this down while the clip happens to be playing.
 *
 * Deliberately unaware of transitions — see ClipVideoPreview's
 * TransitionPreviewOverlay for the crossfade blend itself, which reads the
 * same segment boundaries independently.
 */
export function useSegmentAwarePlayback(
  videoRef: React.RefObject<HTMLVideoElement | null>,
  segments: Segment[],
  isPlaying: boolean
) {
  const segmentsRef = useRef(segments);
  useEffect(() => {
    segmentsRef.current = segments;
  }, [segments]);

  useEffect(() => {
    const el = videoRef.current;
    if (!el || !isPlaying) return;

    function onTimeUpdate() {
      const segs = segmentsRef.current;
      // A single (or no) segment has no boundary to jump across — every clip
      // before multi-segment editing existed, and the overwhelmingly common
      // case since, takes this no-op path untouched.
      if (segs.length < 2) return;

      const t = el!.currentTime;
      const activeIndex = segs.findIndex((s) => t >= s.start && t < s.end);

      if (activeIndex !== -1) {
        const seg = segs[activeIndex];
        if (t >= seg.end - GAP_EPSILON) {
          if (activeIndex < segs.length - 1) {
            el!.currentTime = segs[activeIndex + 1].start;
          } else {
            el!.pause();
          }
        }
        return;
      }

      // Not inside any kept segment — playback drifted into a gap. Jump
      // forward to the nearest upcoming one rather than play through it.
      const next = segs.find((s) => s.start > t);
      if (next) el!.currentTime = next.start;
    }

    el.addEventListener("timeupdate", onTimeUpdate);
    return () => el.removeEventListener("timeupdate", onTimeUpdate);
  }, [videoRef, isPlaying]);
}
