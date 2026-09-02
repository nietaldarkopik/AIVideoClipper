"use client";

import { useState } from "react";
import { Check } from "lucide-react";
import { Label, Input } from "@/components/ui/Input";

export type AspectRatioBucket = "9:16" | "1:1" | "16:9";

const PRESETS: { label: string; sub: string; width: number; height: number }[] = [
  { label: "1080 × 1920", sub: "9:16 · TikTok / Reels / Shorts", width: 1080, height: 1920 },
  { label: "1080 × 1080", sub: "1:1 · Square (Feed)", width: 1080, height: 1080 },
  { label: "1920 × 1080", sub: "16:9 · Landscape (YouTube)", width: 1920, height: 1080 },
  { label: "720 × 1280", sub: "9:16 · Vertical, lighter file", width: 720, height: 1280 },
  { label: "1280 × 720", sub: "16:9 · Landscape, lighter file", width: 1280, height: 720 },
];

const MIN_DIMENSION = 100;
const MAX_DIMENSION = 4000;

// Nearest of the 3 buckets FFmpegService/AspectRatio.php ultimately falls back
// to (see Clip::targetResolution()'s docblock) — kept in log-ratio space since
// aspect ratios compose multiplicatively (16:9 and 9:16 are each other's
// reciprocal, not symmetric on a plain linear width/height distance).
export function nearestAspectRatio(width: number, height: number): AspectRatioBucket {
  const ratio = Math.max(1, width) / Math.max(1, height);
  const buckets: { key: AspectRatioBucket; ratio: number }[] = [
    { key: "9:16", ratio: 9 / 16 },
    { key: "1:1", ratio: 1 },
    { key: "16:9", ratio: 16 / 9 },
  ];

  return buckets.reduce((best, cur) =>
    Math.abs(Math.log(ratio) - Math.log(cur.ratio)) < Math.abs(Math.log(ratio) - Math.log(best.ratio)) ? cur : best
  ).key;
}

export function clampDimension(value: number): number {
  if (!Number.isFinite(value)) return MIN_DIMENSION;
  return Math.max(MIN_DIMENSION, Math.min(MAX_DIMENSION, Math.round(value)));
}

/**
 * Preset grid + a "Custom" tile that reveals free-typed width/height inputs —
 * the render pipeline stores an exact pixel canvas size per template
 * (Template::resolution_width/height) independently of the coarse 9:16/1:1/16:9
 * bucket used elsewhere for template<->clip matching (see
 * Clip::targetResolution()), so ANY size typed here is a real, renderable
 * canvas — not just a label. onChange always reports the nearest bucket
 * alongside the exact size, since callers need both.
 */
export function CanvasSizeField({
  width,
  height,
  onChange,
  disabled = false,
}: {
  width: number;
  height: number;
  onChange: (size: { width: number; height: number; aspectRatio: AspectRatioBucket }) => void;
  disabled?: boolean;
}) {
  const matchedPreset = PRESETS.find((p) => p.width === width && p.height === height);
  const [customOpen, setCustomOpen] = useState(!matchedPreset);

  function apply(w: number, h: number) {
    const clampedW = clampDimension(w);
    const clampedH = clampDimension(h);
    onChange({ width: clampedW, height: clampedH, aspectRatio: nearestAspectRatio(clampedW, clampedH) });
  }

  return (
    <fieldset disabled={disabled} className="space-y-3 disabled:opacity-60">
      <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
        {PRESETS.map((p) => {
          const active = !customOpen && p.label === matchedPreset?.label;
          return (
            <button
              key={p.label}
              type="button"
              onClick={() => {
                setCustomOpen(false);
                apply(p.width, p.height);
              }}
              className={
                "relative rounded-xl border px-3 py-2.5 text-left transition-colors cursor-pointer " +
                (active
                  ? "border-accent bg-accent/10"
                  : "border-border-subtle bg-surface-elevated hover:border-accent/50")
              }
            >
              {active && <Check className="absolute right-2 top-2 size-3.5 text-accent" />}
              <div className="text-sm font-medium">{p.label}</div>
              <div className="mt-0.5 pr-4 text-[11px] text-muted">{p.sub}</div>
            </button>
          );
        })}
        <button
          type="button"
          onClick={() => setCustomOpen(true)}
          className={
            "rounded-xl border px-3 py-2.5 text-left transition-colors cursor-pointer " +
            (customOpen ? "border-accent bg-accent/10" : "border-border-subtle bg-surface-elevated hover:border-accent/50")
          }
        >
          <div className="text-sm font-medium">Custom</div>
          <div className="mt-0.5 text-[11px] text-muted">Type your own size</div>
        </button>
      </div>

      {customOpen && (
        <div className="grid grid-cols-2 gap-3 rounded-xl border border-border-subtle bg-surface-elevated p-3">
          <div>
            <Label>Width (px)</Label>
            <Input
              type="number"
              min={MIN_DIMENSION}
              max={MAX_DIMENSION}
              value={width}
              onChange={(e) => apply(Number(e.target.value), height)}
            />
          </div>
          <div>
            <Label>Height (px)</Label>
            <Input
              type="number"
              min={MIN_DIMENSION}
              max={MAX_DIMENSION}
              value={height}
              onChange={(e) => apply(width, Number(e.target.value))}
            />
          </div>
        </div>
      )}

      <p className="text-[11px] text-muted">
        {width} × {height}px · nearest standard ratio {nearestAspectRatio(width, height)}
      </p>
    </fieldset>
  );
}
