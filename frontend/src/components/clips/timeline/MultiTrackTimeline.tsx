"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Maximize2, Music, Plus, Scissors, Sparkles, Trash2, ZoomIn, ZoomOut } from "lucide-react";
import { Select } from "@/components/ui/Input";
import { ELEMENT_LAYER_TYPES, LAYER_ICONS, LAYER_LABELS, layerLabel, newLayer } from "@/lib/layers";
import { isColorLayer } from "@/lib/videoFx";
import { mediaUrl } from "@/lib/api";
import { formatDuration } from "@/lib/format";
import {
  MAX_ZOOM,
  MIN_ZOOM,
  TRACK_OFFSET_PX,
  clipDuration,
  clipTimeToSource,
  pxToTime,
  sourceTimeToClip,
  timeToPx,
  trackWidthPx,
} from "./timelineMath";
import { useDragWindow } from "./useDragWindow";
import { MIN_SEGMENT, TimelineTrack } from "./TimelineTrack";
import type { AudioLayerProps, EditorSelection, LayerType, Segment, SubtitleCue, TemplateLayer } from "@/lib/types";

// Per-track-type colours, so a glance at the timeline tells you what kind of
// thing each block is without reading its label.
const BLOCK_TONES: Record<string, string> = {
  caption: "border-sky-400/70 bg-sky-500/25",
  text: "border-violet-400/70 bg-violet-500/25",
  image: "border-emerald-400/70 bg-emerald-500/25",
  logo: "border-emerald-400/70 bg-emerald-500/25",
  rect: "border-amber-400/70 bg-amber-500/25",
  progress_bar: "border-amber-400/70 bg-amber-500/25",
  audio: "border-teal-400/70 bg-teal-500/25",
  effect: "border-fuchsia-400/70 bg-fuchsia-500/25",
  filter: "border-rose-400/70 bg-rose-500/25",
};

function tickInterval(pxPerSecond: number): number {
  const candidates = [0.5, 1, 2, 5, 10, 15, 30, 60, 120, 300, 600, 1800];
  return candidates.find((c) => pxPerSecond * c >= 60) ?? 1800;
}

function TrackLabel({
  children,
  Icon,
  onRemove,
  muted = false,
}: {
  children: React.ReactNode;
  Icon?: typeof Plus;
  onRemove?: () => void;
  muted?: boolean;
}) {
  return (
    <div
      className={
        "sticky left-0 z-30 flex w-28 shrink-0 items-center gap-1.5 rounded-lg bg-surface px-2 py-1.5 text-[11px] " +
        (muted ? "text-muted" : "")
      }
    >
      {Icon && <Icon className="size-3 shrink-0 text-muted" />}
      <span className="truncate">{children}</span>
      {onRemove && (
        <button
          type="button"
          onClick={onRemove}
          className="ml-auto shrink-0 cursor-pointer text-muted hover:text-danger"
          title="Remove"
        >
          <Trash2 className="size-3" />
        </button>
      )}
    </div>
  );
}

/**
 * One draggable/resizable block on a lane. Everything except the video track is
 * built from these — captions, elements, audio, effects and filters differ only
 * in colour, label and what their {start,end} is written back to.
 *
 * The window it receives and emits is always in SOURCE-video seconds (the axis
 * the whole timeline is drawn on); callers convert to and from the clip-relative
 * timings that are actually persisted — see timelineMath's clipTimeToSource().
 */
function TimelineBlock({
  window,
  pxPerSecond,
  max,
  snapTargets,
  onSnapChange,
  selected,
  onSelect,
  onChange,
  tone,
  label,
  title,
  waveformUrl,
}: {
  window: { start: number; end: number };
  pxPerSecond: number;
  max: number;
  snapTargets: number[];
  onSnapChange: (t: number | null) => void;
  selected: boolean;
  onSelect: () => void;
  onChange: (window: { start: number; end: number }) => void;
  tone: string;
  label: string;
  title?: string;
  // Audio blocks only: the waveform generated when the track was uploaded (see
  // MediaUploadController::makeWaveform). Stretched to the block's width, so it
  // shows the shape of the sound over exactly the stretch of time the block
  // covers — trimming the block visibly trims the waveform with it.
  waveformUrl?: string | null;
}) {
  const drag = useDragWindow({ pxPerSecond, min: 0, max, minLength: 0.2, snapTargets, onChange });
  const live = drag.live ?? window;

  // Report the active snap target up so the timeline can draw one guide line,
  // and clear it when this block's drag ends.
  useEffect(() => {
    if (drag.dragging) onSnapChange(drag.snappedTo);
  }, [drag.dragging, drag.snappedTo, onSnapChange]);

  return (
    <div
      onPointerDown={drag.startDrag("move", window)}
      onPointerMove={drag.handlePointerMove}
      onPointerUp={() => {
        drag.handlePointerUp();
        onSnapChange(null);
      }}
      onClick={(e) => {
        e.stopPropagation();
        onSelect();
      }}
      title={title ?? label}
      className={
        "group absolute inset-y-0.5 cursor-grab overflow-hidden rounded-md border px-1.5 text-[10px] text-white/90 active:cursor-grabbing " +
        tone +
        (selected ? " ring-2 ring-white/80" : " hover:brightness-125")
      }
      style={{
        left: timeToPx(live.start, pxPerSecond),
        width: Math.max(4, timeToPx(live.end - live.start, pxPerSecond)),
      }}
    >
      {waveformUrl && (
        <div
          className="pointer-events-none absolute inset-0 opacity-50"
          style={{ backgroundImage: `url(${waveformUrl})`, backgroundSize: "100% 100%", backgroundRepeat: "no-repeat" }}
        />
      )}
      <span className="pointer-events-none relative block truncate leading-7">{label}</span>
      <div
        onPointerDown={(e) => {
          e.stopPropagation();
          drag.startDrag("start", window)(e);
        }}
        className="absolute inset-y-0 left-0 w-1.5 cursor-ew-resize bg-white/0 group-hover:bg-white/40"
      />
      <div
        onPointerDown={(e) => {
          e.stopPropagation();
          drag.startDrag("end", window)(e);
        }}
        className="absolute inset-y-0 right-0 w-1.5 cursor-ew-resize bg-white/0 group-hover:bg-white/40"
      />
    </div>
  );
}

function Lane({
  duration,
  pxPerSecond,
  onSeek,
  children,
  height = "h-9",
}: {
  duration: number;
  pxPerSecond: number;
  onSeek: (t: number) => void;
  children: React.ReactNode;
  height?: string;
}) {
  return (
    <div
      className={"relative shrink-0 cursor-pointer rounded-lg bg-black/30 " + height}
      style={{ width: trackWidthPx(duration, pxPerSecond) }}
      onClick={(e) => {
        const rect = e.currentTarget.getBoundingClientRect();
        onSeek(Math.max(0, Math.min(duration, pxToTime(e.clientX - rect.left, pxPerSecond))));
      }}
    >
      {children}
    </div>
  );
}

function GroupHeader({ label, action }: { label: string; action?: React.ReactNode }) {
  return (
    <div className="flex items-center gap-2 pt-2">
      <p className="text-[11px] font-semibold uppercase tracking-wide text-muted">{label}</p>
      {action}
    </div>
  );
}

function AddButton({ onClick, children }: { onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="flex cursor-pointer items-center gap-1 rounded-lg px-1.5 py-0.5 text-[11px] text-accent-2 hover:bg-white/5"
    >
      <Plus className="size-3" />
      {children}
    </button>
  );
}

export function MultiTrackTimeline({
  duration,
  segments,
  onSegmentsChange,
  layers,
  onLayersChange,
  captionCues,
  onCaptionCuesChange,
  selection,
  onSelectionChange,
  currentTime,
  onSeek,
  thumbnailStripUrl,
  waveformUrl,
}: {
  duration: number;
  segments: Segment[];
  onSegmentsChange: (segments: Segment[]) => void;
  layers: TemplateLayer[];
  onLayersChange: (layers: TemplateLayer[]) => void;
  captionCues: SubtitleCue[];
  onCaptionCuesChange: (cues: SubtitleCue[]) => void;
  selection: EditorSelection;
  // The second argument is only passed when selecting a layer this component
  // just created: it isn't in `layers` yet (the parent's state update hasn't
  // landed), so the parent can't look it up to decide which inspector panel to
  // open. Handing it over avoids a "selected but the wrong panel is showing"
  // frame right after adding something.
  onSelectionChange: (selection: EditorSelection, addedLayer?: TemplateLayer) => void;
  currentTime: number;
  onSeek: (t: number) => void;
  thumbnailStripUrl?: string | null;
  waveformUrl?: string | null;
}) {
  // Source videos here range from a few seconds to multiple hours, so there's
  // no single fixed px-per-second scale that works for all of them — a fixed
  // scale either makes short clips tiny or makes a 2-hour source render a
  // timeline hundreds of thousands of pixels wide. Instead, zoom 1x always
  // fits the whole duration inside the visible container ("Fit"), and zooming
  // in from there is what gives frame-accurate dragging.
  const containerRef = useRef<HTMLDivElement>(null);
  // Reasonable guess for the pre-measurement first paint (ResizeObserver's
  // first callback lands a frame later) — avoids a flash of a near-zero-width
  // timeline before the real container width is known.
  const [containerWidth, setContainerWidth] = useState(800);
  useEffect(() => {
    const el = containerRef.current;
    if (!el) return;
    const ro = new ResizeObserver((entries) => setContainerWidth(entries[0].contentRect.width));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  const [zoom, setZoom] = useState(MIN_ZOOM);
  const [addElementType, setAddElementType] = useState<LayerType>("text");
  const [snapGuide, setSnapGuide] = useState<number | null>(null);
  const handleSnapChange = useCallback((t: number | null) => setSnapGuide(t), []);

  const availableTrackWidth = Math.max(1, containerWidth - TRACK_OFFSET_PX);
  const fitPxPerSecond = duration > 0 ? availableTrackWidth / duration : 20;
  const pxPerSecond = fitPxPerSecond * zoom;
  const trackWidth = trackWidthPx(duration, pxPerSecond);

  const elements = layers.filter((l) => ELEMENT_LAYER_TYPES.includes(l.type));
  const audioLayers = layers.filter((l) => l.type === "audio");
  const fxLayers = layers.filter(isColorLayer);
  const outputDuration = clipDuration(segments);

  // Layer timings and caption cues are stored against the rendered output's
  // timeline; the ruler, filmstrip and <video> element all speak source time.
  const toSource = useCallback((t: number) => clipTimeToSource(t, segments), [segments]);
  const toClip = useCallback((t: number) => sourceTimeToClip(t, segments), [segments]);

  function layerWindow(layer: TemplateLayer) {
    return {
      start: toSource(layer.timing?.start ?? 0),
      end: toSource(layer.timing?.end ?? outputDuration),
    };
  }

  // Everything a dragged edge can stick to: the clip's own bounds, the playhead,
  // every cut, and every other block's edges.
  const snapTargets = useMemo(() => {
    const targets = [0, duration, currentTime];
    for (const segment of segments) targets.push(segment.start, segment.end);
    for (const layer of layers) {
      targets.push(clipTimeToSource(layer.timing?.start ?? 0, segments));
      targets.push(clipTimeToSource(layer.timing?.end ?? clipDuration(segments), segments));
    }
    for (const cue of captionCues) {
      targets.push(clipTimeToSource(cue.start, segments), clipTimeToSource(cue.end, segments));
    }
    return targets;
  }, [duration, currentTime, segments, layers, captionCues]);

  // Keep the playhead on screen while zoomed in — otherwise playback quickly
  // runs off the right edge and the user has to chase it with the scrollbar.
  useEffect(() => {
    const el = containerRef.current;
    if (!el || zoom === MIN_ZOOM) return;
    const x = TRACK_OFFSET_PX + timeToPx(currentTime, pxPerSecond);
    const margin = 80;
    if (x < el.scrollLeft + TRACK_OFFSET_PX + margin || x > el.scrollLeft + el.clientWidth - margin) {
      el.scrollLeft = Math.max(0, x - el.clientWidth / 2);
    }
  }, [currentTime, pxPerSecond, zoom]);

  function updateLayer(id: string, patch: Partial<TemplateLayer>) {
    onLayersChange(layers.map((l) => (l.id === id ? { ...l, ...patch } : l)));
  }

  function moveLayerWindow(layer: TemplateLayer, w: { start: number; end: number }) {
    updateLayer(layer.id, {
      timing: { start: Number(toClip(w.start).toFixed(2)), end: Number(toClip(w.end).toFixed(2)) },
    });
  }

  function removeLayer(id: string) {
    onLayersChange(layers.filter((l) => l.id !== id));
    if (selection?.kind === "layer" && selection.id === id) onSelectionChange(null);
  }

  function addLayer(type: LayerType) {
    const maxZ = layers.reduce((m, l) => Math.max(m, l.z_index), 0);
    // New blocks start at the playhead and run a few seconds, the way dropping a
    // sticker or a title into a CapCut timeline does — except a filter, whose
    // newLayer() default deliberately spans the whole clip.
    const startAt = Number(toClip(currentTime).toFixed(2));
    const endAt = type === "filter" ? null : Number(Math.min(outputDuration, startAt + 3).toFixed(2));
    const layer = newLayer(type, maxZ + 1, startAt, endAt);
    onLayersChange([...layers, layer]);
    onSelectionChange({ kind: "layer", id: layer.id }, layer);
  }

  function addSegmentAtPlayhead() {
    const sorted = [...segments].sort((a, b) => a.start - b.start);
    let gapStart = 0;
    for (const seg of sorted) {
      if (currentTime < seg.start) break;
      gapStart = Math.max(gapStart, seg.end);
    }
    const gapEnd = sorted.find((s) => s.start > gapStart)?.start ?? duration;
    const start = Math.max(gapStart, Math.min(currentTime, gapEnd - MIN_SEGMENT));
    const end = Math.min(gapEnd, start + Math.min(3, gapEnd - start));
    if (end - start < MIN_SEGMENT) return;
    onSegmentsChange([...segments, { start, end }].sort((a, b) => a.start - b.start));
  }

  /**
   * Split whatever is selected at the playhead — the video track by default.
   * This is a real in-timeline cut (one segment becomes two adjacent ones, one
   * cue becomes two), unlike the page's "Split at playhead" button, which cuts
   * the clip into two separate Clip rows for separate renders.
   */
  function splitAtPlayhead() {
    if (selection?.kind === "caption") {
      const cue = captionCues[selection.index];
      const at = toClip(currentTime);
      if (!cue || at <= cue.start + 0.1 || at >= cue.end - 0.1) return;
      // Word timings follow the split so per-word highlighting survives it; a
      // half with no words of its own simply renders as a plain styled line.
      const next = [...captionCues];
      next.splice(
        selection.index,
        1,
        { ...cue, end: at, words: (cue.words ?? []).filter((w) => w.start < at) },
        { ...cue, start: at, words: (cue.words ?? []).filter((w) => w.start >= at) }
      );
      onCaptionCuesChange(next);
      return;
    }

    if (selection?.kind === "layer") {
      const layer = layers.find((l) => l.id === selection.id);
      if (!layer) return;
      const at = Number(toClip(currentTime).toFixed(2));
      const start = layer.timing?.start ?? 0;
      const end = layer.timing?.end ?? outputDuration;
      if (at <= start + 0.1 || at >= end - 0.1) return;
      // The second half is the same layer in every respect but its id and its
      // timing — a split should never quietly reset a position or a style.
      const secondHalf: TemplateLayer = {
        ...layer,
        id: newLayer(layer.type, layer.z_index).id,
        timing: { start: at, end },
      };
      onLayersChange([...layers.map((l) => (l.id === layer.id ? { ...l, timing: { start, end: at } } : l)), secondHalf]);
      return;
    }

    const index = segments.findIndex((s) => currentTime > s.start + 0.1 && currentTime < s.end - 0.1);
    if (index < 0) return;
    const seg = segments[index];
    const at = Number(currentTime.toFixed(2));
    const next = [...segments];
    next.splice(index, 1, { start: seg.start, end: at }, { start: at, end: seg.end });
    onSegmentsChange(next);
    onSelectionChange({ kind: "segment", index });
  }

  function deleteSelection() {
    if (!selection) return;
    if (selection.kind === "layer") removeLayer(selection.id);
    if (selection.kind === "caption") {
      onCaptionCuesChange(captionCues.filter((_, i) => i !== selection.index));
      onSelectionChange(null);
    }
    if (selection.kind === "segment") {
      if (segments.length <= 1) return;
      onSegmentsChange(segments.filter((_, i) => i !== selection.index));
      onSelectionChange(null);
    }
    if (selection.kind === "transition") {
      onSegmentsChange(segments.map((s, i) => (i === selection.index ? { ...s, transition_in: null } : s)));
      onSelectionChange(null);
    }
  }

  const step = tickInterval(pxPerSecond);
  const ticks: number[] = [];
  for (let t = 0; t <= duration; t += step) ticks.push(t);

  function scrubFrom(clientX: number, el: HTMLElement) {
    const rect = el.getBoundingClientRect();
    onSeek(Math.max(0, Math.min(duration, pxToTime(clientX - rect.left, pxPerSecond))));
  }

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-1">
          <button
            type="button"
            onClick={splitAtPlayhead}
            className="flex cursor-pointer items-center gap-1 rounded-lg bg-surface-elevated px-2 py-1 text-[11px] hover:bg-white/5"
            title="Split the selected block (or the video track) at the playhead"
          >
            <Scissors className="size-3" />
            Split
          </button>
          <button
            type="button"
            onClick={addSegmentAtPlayhead}
            className="flex cursor-pointer items-center gap-1 rounded-lg bg-surface-elevated px-2 py-1 text-[11px] hover:bg-white/5"
            title="Pull another range of the source video in at the playhead (jump cut)"
          >
            <Plus className="size-3" />
            Segment
          </button>
          <button
            type="button"
            onClick={deleteSelection}
            disabled={!selection}
            className="flex cursor-pointer items-center gap-1 rounded-lg bg-surface-elevated px-2 py-1 text-[11px] hover:bg-white/5 disabled:cursor-not-allowed disabled:opacity-40"
            title="Delete the selected block"
          >
            <Trash2 className="size-3" />
            Delete
          </button>
          <span className="ml-1 text-[11px] text-muted">
            {segments.length} segment{segments.length !== 1 ? "s" : ""} · {formatDuration(outputDuration)} output
          </span>
        </div>

        <div className="flex items-center gap-1">
          <button
            type="button"
            onClick={() => setZoom((z) => Math.max(MIN_ZOOM, z / 1.5))}
            className="cursor-pointer rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground"
            title="Zoom out"
          >
            <ZoomOut className="size-3.5" />
          </button>
          <span className="w-10 text-center text-[11px] tabular-nums text-muted">{zoom.toFixed(1)}×</span>
          <button
            type="button"
            onClick={() => setZoom((z) => Math.min(MAX_ZOOM, z * 1.5))}
            className="cursor-pointer rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground"
            title="Zoom in"
          >
            <ZoomIn className="size-3.5" />
          </button>
          <button
            type="button"
            onClick={() => setZoom(MIN_ZOOM)}
            className="cursor-pointer rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground"
            title="Fit the whole video to the timeline's width"
          >
            <Maximize2 className="size-3.5" />
          </button>
        </div>
      </div>

      <div ref={containerRef} className="overflow-x-auto pb-1">
        <div className="relative space-y-1" style={{ width: Math.max(containerWidth, trackWidth + TRACK_OFFSET_PX) }}>
          {/* Ruler — also the scrub bar, so clicking or dragging anywhere along
              it moves the playhead the way it does in a real NLE. */}
          <div style={{ paddingLeft: TRACK_OFFSET_PX }}>
            <div
              className="relative h-5 cursor-ew-resize"
              style={{ width: trackWidth }}
              onPointerDown={(e) => {
                (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
                scrubFrom(e.clientX, e.currentTarget);
              }}
              onPointerMove={(e) => {
                if (e.buttons === 1) scrubFrom(e.clientX, e.currentTarget);
              }}
            >
              {ticks.map((t) => (
                <div
                  key={t}
                  className="absolute top-0 border-l border-border-subtle pl-1 text-[10px] text-muted"
                  style={{ left: timeToPx(t, pxPerSecond) }}
                >
                  {formatDuration(t)}
                </div>
              ))}
            </div>
          </div>

          <div className="flex items-center gap-2 py-0.5">
            <TrackLabel Icon={LAYER_ICONS.image} muted>
              Video
            </TrackLabel>
            <TimelineTrack
              duration={duration}
              segments={segments}
              onChange={onSegmentsChange}
              onSeek={onSeek}
              thumbnailStripUrl={thumbnailStripUrl}
              waveformUrl={waveformUrl}
              pxPerSecond={pxPerSecond}
              selectedIndex={selection?.kind === "segment" ? selection.index : null}
              onSelectSegment={(index) => onSelectionChange({ kind: "segment", index })}
              snapTargets={snapTargets}
              selectedTransitionIndex={selection?.kind === "transition" ? selection.index : null}
              onSelectTransition={(index) => onSelectionChange({ kind: "transition", index })}
            />
          </div>

          <div className="flex items-center gap-2 py-0.5">
            <TrackLabel muted>Captions</TrackLabel>
            <Lane duration={duration} pxPerSecond={pxPerSecond} onSeek={onSeek} height="h-8">
              {captionCues.length === 0 ? (
                <p className="pointer-events-none absolute inset-0 flex items-center px-2 text-[10px] text-muted">
                  Captions appear here once the clip has rendered at least once.
                </p>
              ) : (
                captionCues.map((cue, i) => (
                  <TimelineBlock
                    key={i}
                    window={{ start: toSource(cue.start), end: toSource(cue.end) }}
                    pxPerSecond={pxPerSecond}
                    max={duration}
                    snapTargets={snapTargets}
                    onSnapChange={handleSnapChange}
                    selected={selection?.kind === "caption" && selection.index === i}
                    onSelect={() => onSelectionChange({ kind: "caption", index: i })}
                    onChange={(w) =>
                      onCaptionCuesChange(
                        captionCues.map((c, j) =>
                          j === i
                            ? { ...c, start: Number(toClip(w.start).toFixed(2)), end: Number(toClip(w.end).toFixed(2)) }
                            : c
                        )
                      )
                    }
                    tone={BLOCK_TONES.caption}
                    label={cue.text}
                  />
                ))
              )}
            </Lane>
          </div>

          <GroupHeader
            label="Effects & filters"
            action={
              <>
                <AddButton onClick={() => addLayer("effect")}>Effect</AddButton>
                <AddButton onClick={() => addLayer("filter")}>Filter</AddButton>
              </>
            }
          />
          {fxLayers.length === 0 && (
            <p className="text-[11px] text-muted" style={{ paddingLeft: TRACK_OFFSET_PX }}>
              No effects or filters yet — these grade the footage itself, never the captions or overlays.
            </p>
          )}
          {fxLayers.map((layer) => (
            <div key={layer.id} className="flex items-center gap-2 py-0.5">
              <TrackLabel Icon={layer.type === "filter" ? LAYER_ICONS.filter : Sparkles} onRemove={() => removeLayer(layer.id)}>
                {layerLabel(layer)}
              </TrackLabel>
              <Lane duration={duration} pxPerSecond={pxPerSecond} onSeek={onSeek}>
                <TimelineBlock
                  window={layerWindow(layer)}
                  pxPerSecond={pxPerSecond}
                  max={duration}
                  snapTargets={snapTargets}
                  onSnapChange={handleSnapChange}
                  selected={selection?.kind === "layer" && selection.id === layer.id}
                  onSelect={() => onSelectionChange({ kind: "layer", id: layer.id })}
                  onChange={(w) => moveLayerWindow(layer, w)}
                  tone={BLOCK_TONES[layer.type]}
                  label={layerLabel(layer)}
                />
              </Lane>
            </div>
          ))}

          <GroupHeader
            label="Elements"
            action={
              <>
                <Select
                  value={addElementType}
                  onChange={(e) => setAddElementType(e.target.value as LayerType)}
                  className="h-7 w-auto py-1 text-[11px]"
                >
                  {ELEMENT_LAYER_TYPES.map((t) => (
                    <option key={t} value={t}>
                      {LAYER_LABELS[t]}
                    </option>
                  ))}
                </Select>
                <AddButton onClick={() => addLayer(addElementType)}>Add</AddButton>
              </>
            }
          />
          {elements.length === 0 && (
            <p className="text-[11px] text-muted" style={{ paddingLeft: TRACK_OFFSET_PX }}>
              No text, image or overlay elements yet.
            </p>
          )}
          {elements.map((layer) => (
            <div key={layer.id} className="flex items-center gap-2 py-0.5">
              <TrackLabel Icon={LAYER_ICONS[layer.type]} onRemove={() => removeLayer(layer.id)}>
                {layerLabel(layer)}
              </TrackLabel>
              <Lane duration={duration} pxPerSecond={pxPerSecond} onSeek={onSeek}>
                <TimelineBlock
                  window={layerWindow(layer)}
                  pxPerSecond={pxPerSecond}
                  max={duration}
                  snapTargets={snapTargets}
                  onSnapChange={handleSnapChange}
                  selected={selection?.kind === "layer" && selection.id === layer.id}
                  onSelect={() => onSelectionChange({ kind: "layer", id: layer.id })}
                  onChange={(w) => moveLayerWindow(layer, w)}
                  tone={BLOCK_TONES[layer.type]}
                  label={layerLabel(layer)}
                />
              </Lane>
            </div>
          ))}

          <GroupHeader label="Audio" action={<AddButton onClick={() => addLayer("audio")}>Background audio</AddButton>} />
          {audioLayers.length === 0 && (
            <p className="text-[11px] text-muted" style={{ paddingLeft: TRACK_OFFSET_PX }}>
              No background audio tracks yet.
            </p>
          )}
          {audioLayers.map((layer) => (
            <div key={layer.id} className="flex items-center gap-2 py-0.5">
              <TrackLabel Icon={Music} onRemove={() => removeLayer(layer.id)}>
                {layerLabel(layer)}
              </TrackLabel>
              <Lane duration={duration} pxPerSecond={pxPerSecond} onSeek={onSeek}>
                <TimelineBlock
                  window={layerWindow(layer)}
                  pxPerSecond={pxPerSecond}
                  max={duration}
                  snapTargets={snapTargets}
                  onSnapChange={handleSnapChange}
                  selected={selection?.kind === "layer" && selection.id === layer.id}
                  onSelect={() => onSelectionChange({ kind: "layer", id: layer.id })}
                  onChange={(w) => moveLayerWindow(layer, w)}
                  tone={BLOCK_TONES.audio}
                  label={layerLabel(layer)}
                  waveformUrl={mediaUrl((layer.props as AudioLayerProps)?.waveform_path)}
                />
              </Lane>
            </div>
          ))}

          {/* One playhead for every row, drawn last so it sits above the tracks
              but below the sticky labels (which must stay readable when the
              timeline is scrolled). */}
          <div
            className="pointer-events-none absolute inset-y-0 z-20 w-px bg-white"
            style={{ left: TRACK_OFFSET_PX + timeToPx(currentTime, pxPerSecond) }}
          >
            <div className="-ml-[5px] size-2.5 rounded-b-sm bg-white" />
          </div>

          {snapGuide != null && (
            <div
              className="pointer-events-none absolute inset-y-0 z-10 w-px bg-accent-2"
              style={{ left: TRACK_OFFSET_PX + timeToPx(snapGuide, pxPerSecond) }}
            />
          )}
        </div>
      </div>
    </div>
  );
}
