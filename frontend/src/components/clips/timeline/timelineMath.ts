// Shared time<->pixel conversion for every row in MultiTrackTimeline (video,
// each layer, captions) so they all line up under one ruler/playhead/scroll
// container. `pxPerSecond` is NOT a fixed constant — source videos here can
// run from seconds to multiple hours, so MultiTrackTimeline computes it per
// clip as "fit the container at zoom 1x, zoom in from there" (see its
// `fitPxPerSecond`) rather than using one absolute scale for every duration.
import type { Segment } from "@/lib/types";

export const MIN_ZOOM = 1;
export const MAX_ZOOM = 40;

// Sticky per-row label chip (w-28) plus the flex gap after it. Every row uses
// the same two, so this is where the track area starts — the ruler's left
// padding and the playhead's x-offset both derive from it.
export const TRACK_LABEL_PX = 112;
export const TRACK_GAP_PX = 8;
export const TRACK_OFFSET_PX = TRACK_LABEL_PX + TRACK_GAP_PX;

export function timeToPx(t: number, pxPerSecond: number): number {
  return t * pxPerSecond;
}

export function pxToTime(px: number, pxPerSecond: number): number {
  return px / pxPerSecond;
}

export function trackWidthPx(duration: number, pxPerSecond: number): number {
  return Math.max(1, duration * pxPerSecond);
}

/**
 * Snaps a time to the nearest of `targets` within `tolerance` seconds, or
 * returns it unchanged. Targets are supplied by the timeline (playhead, clip
 * bounds, every segment/layer edge) and the tolerance is derived from a fixed
 * *pixel* distance, so snapping feels equally sticky whether the user is zoomed
 * out over an hour of footage or in on a single second.
 */
export function snapTime(t: number, targets: number[], tolerance: number): number {
  let best = t;
  let bestDistance = tolerance;
  for (const target of targets) {
    const distance = Math.abs(target - t);
    if (distance < bestDistance) {
      best = target;
      bestDistance = distance;
    }
  }

  return best;
}

/**
 * The timeline draws everything on the SOURCE video's axis (that's what the
 * filmstrip, the waveform and the <video> element's currentTime all speak), but
 * layer timings and caption cues are stored against the RENDERED OUTPUT's axis —
 * FFmpeg gates them with `enable='between(t,...)'`, where t is output time, and
 * output time starts at 0 no matter where the trim starts. With a plain single
 * segment those two axes differ only by a constant offset, which is why treating
 * them as interchangeable mostly worked; with multi-segment jump cuts they
 * genuinely diverge, and a layer timed to the second half of the clip would be
 * drawn (and previewed) in the wrong place entirely.
 *
 * These two functions are the only place that conversion happens, so the
 * timeline, the video preview and the inspector can never disagree about when
 * something is on screen.
 */
export function clipTimeToSource(t: number, segments: Segment[]): number {
  if (segments.length === 0) return t;
  let elapsed = 0;
  for (const segment of segments) {
    const span = Math.max(0, segment.end - segment.start);
    if (t <= elapsed + span) return segment.start + (t - elapsed);
    elapsed += span;
  }

  return segments[segments.length - 1].end;
}

export function sourceTimeToClip(t: number, segments: Segment[]): number {
  if (segments.length === 0) return t;
  let elapsed = 0;
  for (const segment of segments) {
    const span = Math.max(0, segment.end - segment.start);
    // A source time inside a *gap* between segments has no output time at all
    // (those frames are cut) — clamp it to the boundary of the segment that
    // follows, which is where playback would resume.
    if (t < segment.start) return elapsed;
    if (t <= segment.end) return elapsed + (t - segment.start);
    elapsed += span;
  }

  return elapsed;
}

/** Total kept (rendered) length across every segment — the output's duration. */
export function clipDuration(segments: Segment[]): number {
  return segments.reduce((sum, s) => sum + Math.max(0, s.end - s.start), 0);
}
