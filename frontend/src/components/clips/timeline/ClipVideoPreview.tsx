"use client";

import { RefObject, useEffect, useRef, useState } from "react";
import { activeFxCss, activeVignetteOpacity, isColorLayer } from "@/lib/videoFx";
import { mediaUrl } from "@/lib/api";
import { useSegmentAwarePlayback } from "./useSegmentAwarePlayback";
import type {
  AudioLayerProps,
  ImageLayerProps,
  ProgressBarLayerProps,
  RectLayerProps,
  Segment,
  SubtitleCue,
  TemplateLayer,
  TextLayerProps,
} from "@/lib/types";

export function isActiveAt(layer: TemplateLayer, t: number): boolean {
  const start = layer.timing?.start ?? 0;
  const end = layer.timing?.end;
  return t >= start && (end == null || t <= end);
}

// Layer types whose x/y actually drives their rendered position (progress_bar
// is pinned top/bottom via a prop, audio's badge position is hardcoded — see
// LayerOverlay below — so neither is draggable).
const DRAGGABLE_LAYER_TYPES = new Set<TemplateLayer["type"]>(["text", "image", "logo", "rect"]);
const BOX_LAYER_TYPES = new Set<TemplateLayer["type"]>(["rect", "image", "logo"]);

export function ClipVideoPreview({
  videoRef,
  videoUrl,
  posterUrl,
  resolution,
  layers,
  clipTime,
  duration,
  captionCues = [],
  activeCaptionIndex = null,
  onTimeUpdate,
  interactive = false,
  selectedLayerId = null,
  onSelectLayer,
  onLayersChange,
  segments = [],
  isPlaying = false,
  captionConfig,
}: {
  videoRef: RefObject<HTMLVideoElement | null>;
  videoUrl: string | null;
  posterUrl?: string | null;
  resolution: { width: number; height: number };
  layers: TemplateLayer[];
  clipTime: number;
  duration: number;
  captionCues?: SubtitleCue[];
  activeCaptionIndex?: number | null;
  onTimeUpdate: (t: number) => void;
  interactive?: boolean;
  selectedLayerId?: string | null;
  onSelectLayer?: (id: string | null) => void;
  onLayersChange?: (layers: TemplateLayer[]) => void;
  segments?: Segment[];
  isPlaying?: boolean;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  captionConfig?: Record<string, any>;
}) {
  const aspect = `${resolution.width} / ${resolution.height}`;
  const canInteract = interactive && !!onLayersChange;
  const containerRef = useRef<HTMLDivElement>(null);

  useSegmentAwarePlayback(videoRef, segments, isPlaying);
  const [previewWidth, setPreviewWidth] = useState(380);
  useEffect(() => {
    const el = containerRef.current;
    if (!el) return;
    const ro = new ResizeObserver((entries) => setPreviewWidth(entries[0].contentRect.width));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  const isActiveNow = (l: TemplateLayer) => isActiveAt(l, clipTime);
  const previewScale = previewWidth / resolution.width;
  const fxCss = activeFxCss(layers, isActiveNow, previewScale);
  const vignette = activeVignetteOpacity(layers, isActiveNow);

  const overlayLayers = layers.filter((l) => !isColorLayer(l));
  const caption = captionCues.find((cue) => clipTime >= cue.start && clipTime <= cue.end) ?? null;

  // Caption styling computation matching template defaults
  const font = captionConfig?.font || "Arial";
  const color = captionConfig?.color || "#ffffff";
  const highlightColor = captionConfig?.highlight_color || "#ffd100";
  const strokeColor = captionConfig?.stroke_color || "#000000";
  const strokeWidth = captionConfig?.stroke_width ?? 3;
  const bgHex = captionConfig?.background;
  const bgOpacity = captionConfig?.background_opacity ?? 0;
  const bgEnabled = !!bgHex && bgOpacity > 0;
  const position = captionConfig?.position || "bottom";
  const autoFontSize = Math.max(36, Math.round(resolution.height / 20));
  const effectiveFontSize = (captionConfig?.font_size ?? autoFontSize) * previewScale;

  function hexToRgba(hex: string, op: number) {
    const clean = hex.replace("#", "");
    const r = parseInt(clean.slice(0, 2), 16) || 0;
    const g = parseInt(clean.slice(2, 4), 16) || 0;
    const b = parseInt(clean.slice(4, 6), 16) || 0;
    return `rgba(${r}, ${g}, ${b}, ${Math.max(0, Math.min(1, op))})`;
  }

  const bgStyle = bgEnabled ? hexToRgba(bgHex, bgOpacity) : "transparent";
  const paddingPx = bgEnabled ? Math.max(2, (captionConfig?.background_padding ?? 8) * previewScale) : 0;

  return (
    <div>
      <div
        ref={containerRef}
        className="relative w-full overflow-hidden rounded-2xl bg-black"
        style={{ aspectRatio: aspect }}
        onPointerDown={canInteract ? () => onSelectLayer?.(null) : undefined}
      >
        {videoUrl ? (
          <video
            ref={videoRef}
            src={videoUrl}
            poster={posterUrl ?? undefined}
            controls
            className="h-full w-full object-cover"
            style={fxCss ? { filter: fxCss } : undefined}
            onTimeUpdate={(e) => onTimeUpdate(e.currentTarget.currentTime)}
          />
        ) : (
          <div className="flex h-full items-center justify-center text-xs text-muted">No source video</div>
        )}

        {videoUrl && segments.length > 1 && (
          <TransitionPreviewOverlay
            videoRef={videoRef}
            videoUrl={videoUrl}
            posterUrl={posterUrl}
            segments={segments}
            isPlaying={isPlaying}
            fxCss={fxCss}
          />
        )}

        {vignette > 0 && (
          <div
            className="pointer-events-none absolute inset-0"
            style={{
              background: `radial-gradient(ellipse at center, transparent 35%, rgba(0,0,0,${(0.85 * vignette).toFixed(2)}) 100%)`,
            }}
          />
        )}

        {/* CSS-approximated overlay of template layers — final position/crop is
            computed by FFmpeg at render time (including smart-crop pan), this is a
            rough visual guide only, same approximation approach the template
            editor's caption preview already uses. */}
        {canInteract ? (
          <InteractiveLayerOverlay
            layers={overlayLayers}
            currentTime={clipTime}
            duration={duration}
            selectedId={selectedLayerId}
            onSelect={(id) => onSelectLayer?.(id)}
            onChange={(next) => onLayersChange?.([...next, ...layers.filter(isColorLayer)])}
          />
        ) : (
          <div className="pointer-events-none absolute inset-0">
            {[...overlayLayers]
              .filter(isActiveNow)
              .sort((a, b) => a.z_index - b.z_index)
              .map((layer) => (
                <LayerOverlay key={layer.id} layer={layer} currentTime={clipTime} duration={duration} />
              ))}
          </div>
        )}

        {caption && (
          <div
            className="pointer-events-none absolute inset-x-0 flex justify-center px-4"
            style={{
              top: position === "top" ? "6%" : position === "center" ? "45%" : "auto",
              bottom: position === "bottom" ? "12%" : "auto",
            }}
          >
            <span
              style={{
                fontFamily: font,
                color: color,
                WebkitTextStroke: bgEnabled ? "0px" : `${Math.max(0.5, strokeWidth * previewScale)}px ${strokeColor}`,
                fontWeight: captionConfig?.bold ? 800 : 600,
                fontStyle: captionConfig?.italic ? "italic" : "normal",
                textTransform: captionConfig?.uppercase ? "uppercase" : "none",
                background: bgStyle,
                padding: bgEnabled ? `${paddingPx}px ${paddingPx * 1.5}px` : "2px 8px",
                borderRadius: bgEnabled ? 4 : 4,
                fontSize: effectiveFontSize,
                lineHeight: 1.3,
                textAlign: "center",
              }}
              className={
                activeCaptionIndex != null && captionCues[activeCaptionIndex] === caption ? "ring-2 ring-accent" : ""
              }
            >
              {caption.text}
            </span>
          </div>
        )}
      </div>
      <p className="mt-2 text-center text-[11px] text-muted">
        {canInteract
          ? `Drag a text/image/color-bar layer to reposition it (${resolution.width}×${resolution.height}) — actual crop/pan is computed at render time.`
          : `Approximate preview (${resolution.width}×${resolution.height}) — actual crop/pan is computed at render time.`}
      </p>
    </div>
  );
}

/**
 * Approximates a transition's crossfade in the small preview: a second
 * <video>, same source, seeked to track the UPCOMING segment and faded in as
 * the main video nears the end of its current one — driven directly off the
 * main video's own timeupdate event rather than a prop-drilled time, so it
 * stays correct regardless of how the main video's currentTime got there
 * (native controls, the toolbar button, or useSegmentAwarePlayback's own
 * jumps).
 *
 * Deliberately an approximation, in the same spirit as the rest of this
 * preview:
 *   - "fade" and "dissolve" both render as the same linear opacity blend here
 *     — a real per-pixel randomized dissolve isn't practical to reproduce with
 *     a plain <video> element. Only the RENDER (ffmpeg's xfade) actually tells
 *     the two apart; see FFmpegService::extractWithoutSilence().
 *   - Timed against the SOURCE video's own position (the last `duration`
 *     seconds of the current segment), not the shortened OUTPUT timeline the
 *     final render actually produces once a transition is in play — so the
 *     blend visually takes a hair longer here than in the rendered result.
 *     Reproducing that exact shortened-timeline arithmetic in the preview
 *     would mean duplicating FFmpegService::extractWithoutSilence()'s own
 *     cumulative-offset fold on the frontend — another place for the two to
 *     drift apart, for a difference that's sub-second and only visible during
 *     the blend itself. Not worth it for a preview that only needs to look
 *     roughly right; the render's own timing is covered by its own tests.
 */
function TransitionPreviewOverlay({
  videoRef,
  videoUrl,
  posterUrl,
  segments,
  isPlaying,
  fxCss,
}: {
  videoRef: RefObject<HTMLVideoElement | null>;
  videoUrl: string;
  posterUrl?: string | null;
  segments: Segment[];
  isPlaying: boolean;
  // Effects/filters are graded on the MAIN video's <video> element (see
  // above) — applying the identical CSS filter here keeps the blended-in
  // upcoming segment looking consistent with it rather than suddenly
  // "ungrading" mid-transition.
  fxCss: string;
}) {
  const overlayRef = useRef<HTMLVideoElement>(null);
  const [opacity, setOpacity] = useState(0);

  useEffect(() => {
    const main = videoRef.current;
    const overlay = overlayRef.current;
    if (!main || !overlay) return;

    function onTimeUpdate() {
      const t = main!.currentTime;
      const activeIndex = segments.findIndex((s) => t >= s.start && t < s.end);
      const nextSegment = activeIndex === -1 ? undefined : segments[activeIndex + 1];
      const transition = nextSegment?.transition_in;

      if (activeIndex === -1 || !nextSegment || !transition) {
        setOpacity(0);
        return;
      }

      const currentSegment = segments[activeIndex];
      const windowStart = currentSegment.end - transition.duration;
      if (t < windowStart) {
        setOpacity(0);
        return;
      }

      setOpacity(Math.min(1, Math.max(0, (t - windowStart) / transition.duration)));

      // Tracks the corresponding elapsed offset into the NEXT segment. Only
      // re-seeked when it's actually drifted — a redundant same-value write
      // still costs a decode on some browsers.
      const target = nextSegment.start + (t - windowStart);
      if (Math.abs(overlay!.currentTime - target) > 0.08) {
        overlay!.currentTime = target;
      }
    }

    main.addEventListener("timeupdate", onTimeUpdate);
    return () => main.removeEventListener("timeupdate", onTimeUpdate);
  }, [videoRef, segments]);

  return (
    <video
      ref={overlayRef}
      src={videoUrl}
      poster={posterUrl ?? undefined}
      muted
      playsInline
      preload="auto"
      className="pointer-events-none absolute inset-0 h-full w-full object-cover"
      // Forced to 0 the instant playback stops, rather than via a separate
      // effect reacting to `isPlaying` — so a paused frame never gets stuck
      // mid-blend, and there's no extra render-triggering effect for it.
      style={{ opacity: isPlaying ? opacity : 0, ...(fxCss ? { filter: fxCss } : undefined) }}
    />
  );
}

// Hit area + visual box for drag/resize purposes. Doesn't need to match
// LayerOverlay's rendered pixels exactly (e.g. free-floating text has no
// fixed box) — just close enough to grab and give resize handles to.
function layerHitboxStyle(layer: TemplateLayer): React.CSSProperties {
  const x = (layer.x ?? 0.5) * 100;
  const y = (layer.y ?? 0.5) * 100;

  if (layer.type === "rect") {
    return {
      left: `${x}%`,
      top: `${y}%`,
      width: `${(layer.width ?? 1) * 100}%`,
      height: `${(layer.height ?? 0.1) * 100}%`,
    };
  }
  if (layer.type === "image" || layer.type === "logo") {
    return {
      left: `${x}%`,
      top: `${y}%`,
      width: `${(layer.width ?? 0.2) * 100}%`,
      aspectRatio: "1 / 1",
      // The grab box turns with the sticker, so the handle stays on the corner
      // the user can actually see.
      transform: layer.rotation ? `rotate(${layer.rotation}deg)` : undefined,
    };
  }
  // text: centered anchor (matches the -translate-x/y-1/2 LayerOverlay uses).
  // A box (width/height set) only exists in auto-size mode; free-floating
  // text gets a generous fixed grab area since its true glyph size depends
  // on content/font — not measured here.
  const hasBox = layer.width != null;
  return {
    left: `${x}%`,
    top: `${y}%`,
    width: hasBox ? `${(layer.width ?? 0.26) * 100}%` : "26%",
    height: hasBox ? `${(layer.height ?? 0.1) * 100}%` : "10%",
    transform: "translate(-50%, -50%)",
  };
}

function isResizable(layer: TemplateLayer): boolean {
  return BOX_LAYER_TYPES.has(layer.type) || (layer.type === "text" && layer.width != null);
}

type DragState = {
  id: string;
  mode: "move" | "resize";
  originX: number;
  originY: number;
  originWidth: number;
  originHeight: number;
  startClientX: number;
  startClientY: number;
} | null;

function InteractiveLayerOverlay({
  layers,
  currentTime,
  duration,
  selectedId,
  onSelect,
  onChange,
}: {
  layers: TemplateLayer[];
  currentTime: number;
  duration: number;
  selectedId: string | null;
  onSelect: (id: string | null) => void;
  onChange: (layers: TemplateLayer[]) => void;
}) {
  const canvasRef = useRef<HTMLDivElement>(null);
  const dragRef = useRef<DragState>(null);
  const [liveLayers, setLiveLayers] = useState<TemplateLayer[] | null>(null);

  const active = liveLayers ?? layers;
  const visible = [...active].filter((l) => isActiveAt(l, currentTime)).sort((a, b) => a.z_index - b.z_index);

  function patchLayer(id: string, patch: Partial<TemplateLayer>) {
    setLiveLayers((prev) => (prev ?? layers).map((l) => (l.id === id ? { ...l, ...patch } : l)));
  }

  function startDrag(layer: TemplateLayer, mode: "move" | "resize") {
    return (e: React.PointerEvent) => {
      e.stopPropagation();
      (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
      onSelect(layer.id);
      dragRef.current = {
        id: layer.id,
        mode,
        originX: layer.x ?? 0.5,
        originY: layer.y ?? 0.5,
        originWidth: layer.width ?? (layer.type === "rect" ? 1 : 0.2),
        originHeight: layer.height ?? 0.1,
        startClientX: e.clientX,
        startClientY: e.clientY,
      };
      setLiveLayers(layers);
    };
  }

  function handlePointerMove(e: React.PointerEvent) {
    const drag = dragRef.current;
    if (!drag) return;
    const rect = canvasRef.current?.getBoundingClientRect();
    if (!rect || rect.width === 0 || rect.height === 0) return;
    const dx = (e.clientX - drag.startClientX) / rect.width;
    const dy = (e.clientY - drag.startClientY) / rect.height;

    if (drag.mode === "move") {
      patchLayer(drag.id, {
        x: Math.max(0, Math.min(1, drag.originX + dx)),
        y: Math.max(0, Math.min(1, drag.originY + dy)),
      });
    } else {
      // Rect/image are top-left anchored, so a corner drag grows width/height
      // 1:1 with pointer movement. Text is center anchored (matches the
      // -translate-1/2 in LayerOverlay), so both edges move at once — double
      // the delta to keep the center fixed under the pointer's drag origin.
      const layer = active.find((l) => l.id === drag.id);
      const factor = layer?.type === "text" ? 2 : 1;
      patchLayer(drag.id, {
        width: Math.max(0.03, Math.min(1, drag.originWidth + dx * factor)),
        height: Math.max(0.03, Math.min(1, drag.originHeight + dy * factor)),
      });
    }
  }

  function handlePointerUp() {
    if (liveLayers) onChange(liveLayers);
    dragRef.current = null;
    setLiveLayers(null);
  }

  return (
    <div
      ref={canvasRef}
      className="pointer-events-none absolute inset-0"
      onPointerMove={handlePointerMove}
      onPointerUp={handlePointerUp}
    >
      <div className="pointer-events-none absolute inset-0">
        {visible.map((layer) => (
          <LayerOverlay key={layer.id} layer={layer} currentTime={currentTime} duration={duration} />
        ))}
      </div>

      {visible
        .filter((l) => DRAGGABLE_LAYER_TYPES.has(l.type))
        .map((layer) => {
          const selected = selectedId === layer.id;
          const resizable = isResizable(layer);
          return (
            <div
              key={layer.id}
              onPointerDown={startDrag(layer, "move")}
              className={
                "pointer-events-auto absolute cursor-move rounded-sm border " +
                (selected ? "border-accent" : "border-transparent hover:border-white/40")
              }
              style={layerHitboxStyle(layer)}
            >
              {selected && resizable && (
                // Kept flush inside the box corner (not overhanging it) — the
                // preview container has overflow-hidden, which would clip an
                // overhanging handle for any layer that touches the edge
                // (e.g. the default full-width rect).
                <div
                  onPointerDown={startDrag(layer, "resize")}
                  className="pointer-events-auto absolute bottom-0 right-0 size-3 cursor-nwse-resize rounded-full border border-white bg-accent"
                />
              )}
            </div>
          );
        })}
    </div>
  );
}

export function LayerOverlay({ layer, currentTime, duration }: { layer: TemplateLayer; currentTime: number; duration: number }) {
  const x = (layer.x ?? 0.5) * 100;
  const y = (layer.y ?? 0.5) * 100;
  const opacity = layer.opacity ?? 1;

  if (layer.type === "rect") {
    // Top-left positioned, same convention as LayerCompositionService::buildRectLayer()
    // (drawbox x/y are the box's top-left corner, not a center like 'text'/'image').
    const props = (layer.props as RectLayerProps) ?? {};
    return (
      <div
        className="absolute"
        style={{
          left: `${x}%`,
          top: `${y}%`,
          width: `${(layer.width ?? 1) * 100}%`,
          height: `${(layer.height ?? 0.1) * 100}%`,
          background: props.color || "#000000",
          opacity,
        }}
      />
    );
  }

  if (layer.type === "text") {
    const props = (layer.props as TextLayerProps) ?? {};
    return (
      <span
        className="absolute -translate-x-1/2 -translate-y-1/2 whitespace-nowrap font-bold"
        style={{
          left: `${x}%`,
          top: `${y}%`,
          opacity,
          color: props.color || "#fff",
          fontSize: Math.max(10, (props.font_size ?? 32) * 0.22),
          WebkitTextStroke: props.stroke_width ? `${Math.max(0.5, props.stroke_width * 0.3)}px ${props.stroke_color || "#000"}` : undefined,
          textAlign: props.align ?? "center",
        }}
      >
        {props.content || "Text"}
      </span>
    );
  }

  if (layer.type === "image" || layer.type === "logo") {
    // Top-left positioned, same convention as LayerCompositionService::buildImageLayer()
    // (ffmpeg's overlay=x:y anchors the overlay's top-left corner, not a center).
    const props = (layer.props as ImageLayerProps) ?? {};
    if (!props.image_path) return null;
    const src = mediaUrl(props.image_path);
    return (
      <div
        className="absolute"
        style={{
          left: `${x}%`,
          top: `${y}%`,
          width: layer.width ? `${layer.width * 100}%` : "20%",
          opacity,
          // Turned about its own centre, matching buildImageLayer()'s
          // centre-preserving rotate + overlay offset.
          transform: layer.rotation ? `rotate(${layer.rotation}deg)` : undefined,
        }}
      >
        {/* The real asset, at the layer's own width — FFmpeg's overlay scales to
            width and keeps the source's aspect ratio (buildImageLayer's
            scale=W:-1), so height is left to follow the image here too rather
            than being forced square as this used to. Plain <img>: these are
            user-uploaded files served from the media disk, not build-time assets
            next/image could optimize. */}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={src ?? undefined} alt="" className="w-full" />
      </div>
    );
  }

  if (layer.type === "progress_bar") {
    const props = (layer.props as ProgressBarLayerProps) ?? {};
    const start = layer.timing?.start ?? 0;
    const end = layer.timing?.end ?? duration;
    const span = Math.max(0.001, end - start);
    const fillPct = Math.max(0, Math.min(1, (currentTime - start) / span)) * 100;
    return (
      <div
        className="absolute inset-x-0"
        style={{
          [props.position === "top" ? "top" : "bottom"]: 0,
          height: `${props.height_px ?? 6}px`,
          background: props.background_color ?? "#000",
        }}
      >
        <div className="h-full" style={{ width: `${fillPct}%`, background: props.color ?? "#7c5cff" }} />
      </div>
    );
  }

  if (layer.type === "audio") {
    const props = (layer.props as AudioLayerProps) ?? {};
    return (
      <div className="absolute bottom-2 left-2 rounded-full bg-black/60 px-2 py-1 text-[10px] text-white/80">
        ♪ {props.audio_path ? props.audio_path.split("/").pop() : "background audio"}
      </div>
    );
  }

  return null;
}
