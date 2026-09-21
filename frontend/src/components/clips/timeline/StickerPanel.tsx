"use client";

import { useApi } from "@/lib/hooks";
import { mediaUrl } from "@/lib/api";
import { newLayer } from "@/lib/layers";
import { LayerPropsFields } from "@/components/layers/LayerPropsFields";
import { ArrowLeft } from "lucide-react";
import type { ImageLayerProps, Sticker, TemplateLayer } from "@/lib/types";

/**
 * Browse the built-in sticker set and drop one onto the clip.
 *
 * A sticker is an ordinary image layer — there is no separate layer type, on
 * either side of the wire, so a placed sticker drags, resizes, retimes and
 * renders through exactly the same paths as any other image. This panel is only
 * a browser plus a sensible set of starting values; everything after that is the
 * normal inspector.
 */
export function StickerPanel({
  layers,
  onChange,
  selectedId,
  onSelect,
  addAt,
  outputDuration,
}: {
  layers: TemplateLayer[];
  onChange: (layers: TemplateLayer[]) => void;
  selectedId: string | null;
  onSelect: (id: string | null, addedLayer?: TemplateLayer) => void;
  // Playhead position on the output's timeline — where a new sticker starts.
  addAt: number;
  outputDuration: number;
}) {
  const { data, isLoading } = useApi<{ data: Sticker[] }>("/stickers");
  const stickers = data?.data ?? [];
  const selected = layers.find((l) => l.id === selectedId && (l.type === "image" || l.type === "logo")) ?? null;

  // Image layers already on the clip, whatever put them there — a sticker and a
  // logo are the same kind of object, so pretending otherwise here would just
  // hide half of them from the panel that can edit them.
  const placed = layers.filter((l) => l.type === "image" || l.type === "logo");

  function add(sticker: Sticker) {
    const maxZ = layers.reduce((m, l) => Math.max(m, l.z_index), 0);
    const layer = newLayer(
      "image",
      maxZ + 1,
      Number(addAt.toFixed(2)),
      Number(Math.min(outputDuration, addAt + 3).toFixed(2))
    );
    // Roughly centred and a quarter of the frame wide: visible immediately, and
    // clearly grabbable for the drag/resize that usually follows. x/y are the
    // image's top-left (buildImageLayer's overlay anchor), hence 0.375 rather
    // than 0.5 for a 0.25-wide sticker.
    layer.x = 0.375;
    layer.y = 0.4;
    layer.width = 0.25;
    layer.props = { image_path: sticker.path } satisfies ImageLayerProps;
    onChange([...layers, layer]);
    onSelect(layer.id, layer);
  }

  if (selected) {
    return (
      <div className="space-y-3">
        <button
          type="button"
          onClick={() => onSelect(null)}
          className="flex cursor-pointer items-center gap-1 text-xs text-muted hover:text-foreground"
        >
          <ArrowLeft className="size-3" />
          All stickers
        </button>
        <LayerPropsFields
          layer={selected}
          onChange={(patch) => onChange(layers.map((l) => (l.id === selected.id ? { ...l, ...patch } : l)))}
        />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div>
        <h4 className="mb-2 text-xs font-semibold text-muted">Add a sticker</h4>
        {isLoading ? (
          <p className="text-[11px] text-muted">Loading stickers…</p>
        ) : (
          <div className="grid grid-cols-5 gap-1.5">
            {stickers.map((sticker) => (
              <button
                key={sticker.path}
                type="button"
                onClick={() => add(sticker)}
                title={sticker.name}
                className="cursor-pointer rounded-lg border border-border-subtle bg-black/20 p-1.5 hover:border-accent"
              >
                {/* Plain <img>: these are generated at runtime onto the media
                    disk, not build-time assets next/image could optimize. */}
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={sticker.url} alt={sticker.name} className="aspect-square w-full object-contain" />
              </button>
            ))}
          </div>
        )}
      </div>

      {placed.length > 0 && (
        <div>
          <h4 className="mb-2 text-xs font-semibold text-muted">On this clip</h4>
          <div className="grid grid-cols-5 gap-1.5">
            {placed.map((layer) => {
              const src = mediaUrl((layer.props as ImageLayerProps)?.image_path);
              return (
                <button
                  key={layer.id}
                  type="button"
                  onClick={() => onSelect(layer.id)}
                  title="Edit this sticker"
                  className="cursor-pointer rounded-lg border border-border-subtle bg-black/20 p-1.5 hover:border-accent"
                >
                  {src ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                      src={src}
                      alt=""
                      className="aspect-square w-full object-contain"
                      style={{ transform: layer.rotation ? `rotate(${layer.rotation}deg)` : undefined }}
                    />
                  ) : (
                    <span className="flex aspect-square w-full items-center justify-center text-[9px] text-muted">
                      empty
                    </span>
                  )}
                </button>
              );
            })}
          </div>
        </div>
      )}

      <p className="text-[11px] text-muted">
        Stickers are image layers: drag or resize one on the preview, drag its block on the timeline to change when it
        appears, and rotate it from its properties. You can also upload your own from the Elements tab.
      </p>
    </div>
  );
}
