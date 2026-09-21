"use client";

import { useState } from "react";
import { ChevronDown, ChevronUp, Plus, Trash2, Type } from "lucide-react";
import { clsx } from "clsx";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { Select } from "@/components/ui/Input";
import { LayerPropsFields } from "@/components/layers/LayerPropsFields";
import { LAYER_ICONS, LAYER_LABELS, layerLabel, newLayer } from "@/lib/layers";
import type { LayerType, TemplateLayer } from "@/lib/types";

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
              // effect/filter are layers too, but the clip editor gives them
              // their own rail sections (and the template editor has no UI for
              // them yet) — offering them here as well would give one object two
              // places to be created from.
              .filter((t) => t !== "pip_video" && t !== "effect" && t !== "filter")
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
