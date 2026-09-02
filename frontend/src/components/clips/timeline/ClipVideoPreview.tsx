"use client";

import { RefObject, useRef, useState } from "react";
import type {
  AudioLayerProps,
  ImageLayerProps,
  ProgressBarLayerProps,
  RectLayerProps,
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
  currentTime,
  duration,
  onTimeUpdate,
  interactive = false,
  selectedLayerId = null,
  onSelectLayer,
  onLayersChange,
}: {
  videoRef: RefObject<HTMLVideoElement | null>;
  videoUrl: string | null;
  posterUrl?: string | null;
  resolution: { width: number; height: number };
  layers: TemplateLayer[];
  currentTime: number;
  duration: number;
  onTimeUpdate: (t: number) => void;
  // When true (clip editor only — the template preview stays read-only),
  // layers can be dragged/resized directly on the video instead of only via
  // the numeric LayerEditor panel.
  interactive?: boolean;
  selectedLayerId?: string | null;
  onSelectLayer?: (id: string | null) => void;
  onLayersChange?: (layers: TemplateLayer[]) => void;
}) {
  const aspect = `${resolution.width} / ${resolution.height}`;
  const canInteract = interactive && !!onLayersChange;

  return (
    <div>
      <div
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
            onTimeUpdate={(e) => onTimeUpdate(e.currentTarget.currentTime)}
          />
        ) : (
          <div className="flex h-full items-center justify-center text-xs text-muted">No source video</div>
        )}

        {/* CSS-approximated overlay of template layers — final position/crop is
            computed by FFmpeg at render time (including smart-crop pan), this is a
            rough visual guide only, same approximation approach the template
            editor's caption preview already uses. */}
        {canInteract ? (
          <InteractiveLayerOverlay
            layers={layers}
            currentTime={currentTime}
            duration={duration}
            selectedId={selectedLayerId}
            onSelect={(id) => onSelectLayer?.(id)}
            onChange={(next) => onLayersChange?.(next)}
          />
        ) : (
          <div className="pointer-events-none absolute inset-0">
            {[...layers]
              .filter((l) => isActiveAt(l, currentTime))
              .sort((a, b) => a.z_index - b.z_index)
              .map((layer) => (
                <LayerOverlay key={layer.id} layer={layer} currentTime={currentTime} duration={duration} />
              ))}
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
    return { left: `${x}%`, top: `${y}%`, width: `${(layer.width ?? 0.2) * 100}%`, aspectRatio: "1 / 1" };
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
    return (
      <div
        className="absolute"
        style={{ left: `${x}%`, top: `${y}%`, width: layer.width ? `${layer.width * 100}%` : "20%", opacity }}
      >
        <div className="flex aspect-square w-full items-center justify-center rounded bg-white/10 text-[9px] text-muted">
          image
        </div>
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
