import { BarChart3, Image as ImageIcon, Music, Palette, PictureInPicture, Sparkles, Square, Type } from "lucide-react";
import { fxLabel } from "@/lib/videoFx";
import type { LayerType, TemplateLayer } from "@/lib/types";

export const LAYER_ICONS: Record<LayerType, typeof Type> = {
  text: Type,
  image: ImageIcon,
  logo: ImageIcon,
  pip_video: PictureInPicture,
  audio: Music,
  progress_bar: BarChart3,
  rect: Square,
  effect: Sparkles,
  filter: Palette,
};

export const LAYER_LABELS: Record<LayerType, string> = {
  text: "Text",
  image: "Image",
  logo: "Logo",
  pip_video: "Picture-in-picture",
  audio: "Background audio",
  progress_bar: "Progress bar",
  rect: "Color bar",
  effect: "Effect",
  filter: "Filter",
};

// Element/overlay layers — the ones that draw something at an x/y position and
// therefore get their own row on the timeline's "Elements" group.
export const ELEMENT_LAYER_TYPES: LayerType[] = ["text", "image", "logo", "rect", "progress_bar"];

/**
 * z_index tiers. Effects and filters render before the caption burn-in no matter
 * what (see FFmpegService::partitionLayersAroundCaption()), but their z_index
 * still orders them against each other — a grade should sit under a spot effect,
 * not over it, so a filter starts further back than an effect does.
 */
const FILTER_Z_INDEX = -100;
const EFFECT_Z_INDEX = -50;

export function newLayer(type: LayerType, nextZIndex: number, startAt = 0, endAt: number | null = null): TemplateLayer {
  const id = `${type}-${(globalThis.crypto?.randomUUID?.() ?? Date.now().toString(36)).slice(0, 8)}`;
  const base: TemplateLayer = {
    id,
    type,
    z_index: nextZIndex,
    x: 0.5,
    y: type === "progress_bar" ? 0 : 0.1,
    opacity: 1,
    timing: { start: startAt, end: endAt },
    props: {},
  };

  if (type === "text") base.props = { content: "New text", font_size: 48, color: "#FFFFFF", align: "center" };
  if (type === "image" || type === "logo") base.props = { image_path: "" };
  if (type === "audio") base.props = { audio_path: "", volume: 0.3, fade_in: 1, fade_out: 1 };
  if (type === "progress_bar") base.props = { color: "#7C5CFF", background_color: "#000000", height_px: 6, position: "bottom" };
  if (type === "rect") {
    base.x = 0;
    base.y = 0;
    base.width = 1;
    base.height = 0.15;
    base.props = { color: "#000000" };
  }
  if (type === "effect") {
    base.z_index = EFFECT_Z_INDEX;
    base.props = { effect: "blur", intensity: 0.6 };
  }
  if (type === "filter") {
    base.z_index = FILTER_Z_INDEX;
    // A grade is a property of the whole clip by default (CapCut applies a
    // filter to the track, not to a moment) — the user can still shorten it on
    // the timeline afterwards, which grades only that stretch.
    base.timing = { start: 0, end: null };
    base.props = { preset: "warm", intensity: 1 };
  }

  return base;
}

export function layerLabel(layer: TemplateLayer): string {
  const props = layer.props as
    | { content?: string; image_path?: string; audio_path?: string; preset?: string; effect?: string }
    | undefined;
  if (layer.type === "filter" || layer.type === "effect") {
    // "Warm" / "Blur", not "Filter" / "Effect" — the chosen look is what
    // identifies the block on the timeline and in the layer list.
    return fxLabel(layer);
  }

  return props?.content || props?.image_path || props?.audio_path || LAYER_LABELS[layer.type] || layer.type;
}
