import type { EffectLayerProps, EffectName, FilterLayerProps, FilterPreset, TemplateLayer } from "@/lib/types";

/**
 * The editor-side half of LayerCompositionService::buildColorLayer(): the same
 * catalog of effects and grade presets, plus a CSS approximation of each so the
 * preview shows roughly what the render will produce.
 *
 * The CSS is deliberately an APPROXIMATION, in the same spirit as the layer
 * overlay it sits behind — `filter:` has no equivalent of FFmpeg's per-channel
 * colorbalance or of `curves=preset=vintage`, so the warm/cool/vintage looks are
 * matched by eye with sepia/hue-rotate/saturate rather than computed. What must
 * stay exact is the *catalog*: every name here has to exist in the backend's
 * match(), or the user would be able to pick a look that silently renders as a
 * no-op.
 */
export const EFFECTS: { value: EffectName; label: string; hint: string }[] = [
  { value: "blur", label: "Blur", hint: "Softens the footage — useful for hiding a face or a logo." },
  { value: "grayscale", label: "Grayscale", hint: "Drains colour for the effect's duration." },
  { value: "brightness", label: "Brightness", hint: "Lifts a dark stretch of footage." },
  { value: "contrast", label: "Contrast", hint: "Deepens blacks and brightens highlights." },
  { value: "vignette", label: "Vignette", hint: "Darkens the frame's corners to draw the eye in." },
];

export const FILTERS: { value: FilterPreset; label: string }[] = [
  { value: "normal", label: "Normal" },
  { value: "warm", label: "Warm" },
  { value: "cool", label: "Cool" },
  { value: "bw", label: "Black & White" },
  { value: "vintage", label: "Vintage" },
  { value: "high_contrast", label: "High Contrast" },
];

function effectCss(name: EffectName, intensity: number, previewScale: number): string {
  switch (name) {
    // FFmpeg's gblur sigma is in OUTPUT pixels (a 1080-wide canvas); the preview
    // is a few hundred pixels wide, so the same sigma would look drastically
    // blurrier here. previewScale rescales it to the on-screen size.
    case "blur":
      return `blur(${((1 + 19 * intensity) * previewScale).toFixed(2)}px)`;
    case "grayscale":
      return `grayscale(${intensity})`;
    case "brightness":
      return `brightness(${(1 + 0.45 * intensity).toFixed(3)})`;
    case "contrast":
      return `contrast(${(1 + intensity).toFixed(3)})`;
    // No CSS filter darkens only the corners — the vignette is drawn as a
    // separate radial-gradient overlay instead (see vignetteOpacity below).
    case "vignette":
      return "";
    default:
      return "";
  }
}

function filterCss(preset: FilterPreset, intensity: number): string {
  switch (preset) {
    case "warm":
      return `sepia(${(0.3 * intensity).toFixed(3)}) saturate(${(1 + 0.15 * intensity).toFixed(3)}) hue-rotate(${(-8 * intensity).toFixed(1)}deg)`;
    case "cool":
      return `saturate(${(1 + 0.05 * intensity).toFixed(3)}) hue-rotate(${(12 * intensity).toFixed(1)}deg) brightness(${(1 + 0.03 * intensity).toFixed(3)})`;
    case "bw":
      return `grayscale(${intensity}) contrast(${(1 + 0.2 * intensity).toFixed(3)})`;
    case "vintage":
      return `sepia(${(0.45 * intensity).toFixed(3)}) saturate(${(1 - 0.25 * intensity).toFixed(3)}) contrast(${(1 + 0.05 * intensity).toFixed(3)})`;
    case "high_contrast":
      return `contrast(${(1 + 0.5 * intensity).toFixed(3)}) saturate(${(1 + 0.2 * intensity).toFixed(3)})`;
    case "normal":
    default:
      return "";
  }
}

export function isColorLayer(layer: TemplateLayer): boolean {
  return layer.type === "effect" || layer.type === "filter";
}

function intensityOf(layer: TemplateLayer): number {
  const props = (layer.props as EffectLayerProps & FilterLayerProps) ?? {};
  return Math.max(0, Math.min(1, props.intensity ?? 1));
}

/**
 * Composite CSS `filter` value for every effect/filter layer active at this
 * moment, in z_index order — the preview's equivalent of chaining them in the
 * filtergraph. `previewScale` is the preview's on-screen width divided by the
 * render's canvas width (see effectCss's blur case).
 */
export function activeFxCss(layers: TemplateLayer[], isActive: (l: TemplateLayer) => boolean, previewScale: number): string {
  return layers
    .filter((l) => isColorLayer(l) && isActive(l))
    .sort((a, b) => a.z_index - b.z_index)
    .map((layer) => {
      const intensity = intensityOf(layer);
      if (intensity <= 0) return "";
      const props = (layer.props as EffectLayerProps & FilterLayerProps) ?? {};
      return layer.type === "filter"
        ? filterCss(props.preset ?? "normal", intensity)
        : effectCss(props.effect ?? "blur", intensity, previewScale);
    })
    .filter(Boolean)
    .join(" ");
}

/**
 * Strength of the preview's vignette overlay — the one look in the catalog CSS
 * `filter:` can't express (see effectCss). 0 when no vignette effect is active.
 */
export function activeVignetteOpacity(layers: TemplateLayer[], isActive: (l: TemplateLayer) => boolean): number {
  return layers.reduce((strongest, layer) => {
    if (layer.type !== "effect" || !isActive(layer)) return strongest;
    if (((layer.props as EffectLayerProps)?.effect ?? null) !== "vignette") return strongest;
    return Math.max(strongest, intensityOf(layer));
  }, 0);
}

export function fxLabel(layer: TemplateLayer): string {
  const props = (layer.props as EffectLayerProps & FilterLayerProps) ?? {};
  if (layer.type === "filter") {
    return FILTERS.find((f) => f.value === (props.preset ?? "normal"))?.label ?? "Filter";
  }
  return EFFECTS.find((e) => e.value === (props.effect ?? "blur"))?.label ?? "Effect";
}
