"use client";

import { useState } from "react";
import { ChevronDown, ChevronUp, Plus, Trash2, Type, Image as ImageIcon, Music, BarChart3, PictureInPicture, Square } from "lucide-react";
import { clsx } from "clsx";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { Select } from "@/components/ui/Input";
import { LayerPropsFields } from "@/components/layers/LayerPropsFields";
import type { LayerType, TemplateLayer } from "@/lib/types";

const LAYER_ICONS: Record<LayerType, typeof Type> = {
  text: Type,
  image: ImageIcon,
  logo: ImageIcon,
  pip_video: PictureInPicture,
  audio: Music,
  progress_bar: BarChart3,
  rect: Square,
};

const LAYER_LABELS: Record<LayerType, string> = {
  text: "Text",
  image: "Image",
  logo: "Logo",
  pip_video: "Picture-in-picture",
  audio: "Background audio",
  progress_bar: "Progress bar",
  rect: "Color bar",
};

function newLayer(type: LayerType, nextZIndex: number): TemplateLayer {
  const id = `${type}-${(globalThis.crypto?.randomUUID?.() ?? Date.now().toString(36)).slice(0, 8)}`;
  const base: TemplateLayer = {
    id,
    type,
    z_index: nextZIndex,
    x: 0.5,
    y: type === "progress_bar" ? 0 : 0.1,
    opacity: 1,
    timing: { start: 0, end: null },
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

  return base;
}

function layerLabel(layer: TemplateLayer): string {
  const props = layer.props as { content?: string; image_path?: string; audio_path?: string } | undefined;
  return props?.content || props?.image_path || props?.audio_path || LAYER_LABELS[layer.type] || layer.type;
}

export function LayerEditor({
  layers,
  onChange,
  disabled = false,
  overriddenIds,
  selectedId,
  onSelectChange,
}: {
  layers: TemplateLayer[];
  onChange: (layers: TemplateLayer[]) => void;
  disabled?: boolean;
  overriddenIds?: Set<string>;
  // Controlled selection (clip editor passes this so dragging a layer on the
  // video preview expands its panel here, and vice versa). Falls back to
  // internal state when omitted (e.g. the read-only template preview usage).
  selectedId?: string | null;
  onSelectChange?: (id: string | null) => void;
}) {
  const [internalExpandedId, setInternalExpandedId] = useState<string | null>(null);
  const expandedId = selectedId !== undefined ? selectedId : internalExpandedId;
  const setExpandedId = onSelectChange ?? setInternalExpandedId;
  const [addType, setAddType] = useState<LayerType>("text");
  const sorted = [...layers].sort((a, b) => a.z_index - b.z_index);

  function updateLayer(id: string, patch: Partial<TemplateLayer>) {
    onChange(layers.map((l) => (l.id === id ? { ...l, ...patch } : l)));
  }

  function removeLayer(id: string) {
    onChange(layers.filter((l) => l.id !== id));
    if (expandedId === id) setExpandedId(null);
  }

  function addLayer() {
    const maxZ = layers.reduce((m, l) => Math.max(m, l.z_index), 0);
    const layer = newLayer(addType, maxZ + 1);
    onChange([...layers, layer]);
    setExpandedId(layer.id);
  }

  function move(id: string, direction: -1 | 1) {
    const idx = sorted.findIndex((l) => l.id === id);
    const swapIdx = idx + direction;
    if (idx < 0 || swapIdx < 0 || swapIdx >= sorted.length) return;
    const a = sorted[idx];
    const b = sorted[swapIdx];
    onChange(layers.map((l) => (l.id === a.id ? { ...l, z_index: b.z_index } : l.id === b.id ? { ...l, z_index: a.z_index } : l)));
  }

  return (
    <div className="space-y-3">
      {sorted.length === 0 && <p className="text-xs text-muted">No layers yet.</p>}

      <div className="space-y-2">
        {sorted.map((layer, i) => {
          const Icon = LAYER_ICONS[layer.type] ?? Type;
          const expanded = expandedId === layer.id;
          const overridden = overriddenIds?.has(layer.id);

          return (
            <div key={layer.id} className="overflow-hidden rounded-xl border border-border-subtle bg-surface-elevated">
              <div className="flex items-center gap-2 px-3 py-2.5">
                <button
                  type="button"
                  onClick={() => setExpandedId(expanded ? null : layer.id)}
                  className="flex flex-1 items-center gap-2 text-left cursor-pointer"
                >
                  <Icon className="size-3.5 shrink-0 text-muted" />
                  <span className="truncate text-sm">{layerLabel(layer)}</span>
                  <Badge tone="muted" className="shrink-0">
                    z{layer.z_index}
                  </Badge>
                  {overridden && (
                    <Badge tone="accent" className="shrink-0">
                      Overridden
                    </Badge>
                  )}
                </button>
                <div className="flex items-center gap-0.5">
                  <button
                    type="button"
                    disabled={disabled || i === 0}
                    onClick={() => move(layer.id, -1)}
                    className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground disabled:opacity-30 cursor-pointer disabled:cursor-not-allowed"
                  >
                    <ChevronUp className="size-3.5" />
                  </button>
                  <button
                    type="button"
                    disabled={disabled || i === sorted.length - 1}
                    onClick={() => move(layer.id, 1)}
                    className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground disabled:opacity-30 cursor-pointer disabled:cursor-not-allowed"
                  >
                    <ChevronDown className="size-3.5" />
                  </button>
                  <button
                    type="button"
                    disabled={disabled}
                    onClick={() => removeLayer(layer.id)}
                    className="rounded-lg p-1.5 text-muted hover:bg-danger/10 hover:text-danger cursor-pointer disabled:opacity-30"
                  >
                    <Trash2 className="size-3.5" />
                  </button>
                </div>
              </div>

              {expanded && (
                <div className={clsx("space-y-3 border-t border-border-subtle p-3.5", disabled && "pointer-events-none opacity-60")}>
                  <LayerPropsFields layer={layer} onChange={(patch) => updateLayer(layer.id, patch)} />
                </div>
              )}
            </div>
          );
        })}
      </div>

      {!disabled && (
        <div className="flex items-center gap-2">
          <Select value={addType} onChange={(e) => setAddType(e.target.value as LayerType)} className="w-auto">
            {(Object.keys(LAYER_LABELS) as LayerType[])
              .filter((t) => t !== "pip_video")
              .map((t) => (
                <option key={t} value={t}>
                  {LAYER_LABELS[t]}
                </option>
              ))}
          </Select>
          <Button type="button" variant="outline" size="sm" onClick={addLayer}>
            <Plus className="size-3.5" />
            Add layer
          </Button>
        </div>
      )}
    </div>
  );
}
