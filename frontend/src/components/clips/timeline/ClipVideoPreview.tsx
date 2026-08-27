"use client";

import { RefObject } from "react";
import type { AudioLayerProps, ImageLayerProps, ProgressBarLayerProps, TemplateLayer, TextLayerProps } from "@/lib/types";

function isActiveAt(layer: TemplateLayer, t: number): boolean {
  const start = layer.timing?.start ?? 0;
  const end = layer.timing?.end;
  return t >= start && (end == null || t <= end);
}

export function ClipVideoPreview({
  videoRef,
  videoUrl,
  posterUrl,
  resolution,
  layers,
  currentTime,
  duration,
  onTimeUpdate,
}: {
  videoRef: RefObject<HTMLVideoElement | null>;
  videoUrl: string | null;
  posterUrl?: string | null;
  resolution: { width: number; height: number };
  layers: TemplateLayer[];
  currentTime: number;
  duration: number;
  onTimeUpdate: (t: number) => void;
}) {
  const aspect = `${resolution.width} / ${resolution.height}`;

  return (
    <div>
      <div
        className="relative w-full overflow-hidden rounded-2xl bg-black"
        style={{ aspectRatio: aspect }}
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
        <div className="pointer-events-none absolute inset-0">
          {[...layers]
            .filter((l) => isActiveAt(l, currentTime))
            .sort((a, b) => a.z_index - b.z_index)
            .map((layer) => (
              <LayerOverlay key={layer.id} layer={layer} currentTime={currentTime} duration={duration} />
            ))}
        </div>
      </div>
      <p className="mt-2 text-center text-[11px] text-muted">
        Approximate preview ({resolution.width}×{resolution.height}) — actual crop/pan is computed at render time.
      </p>
    </div>
  );
}

function LayerOverlay({ layer, currentTime, duration }: { layer: TemplateLayer; currentTime: number; duration: number }) {
  const x = (layer.x ?? 0.5) * 100;
  const y = (layer.y ?? 0.5) * 100;
  const opacity = layer.opacity ?? 1;

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
    const props = (layer.props as ImageLayerProps) ?? {};
    if (!props.image_path) return null;
    return (
      <div
        className="absolute -translate-x-1/2 -translate-y-1/2"
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
