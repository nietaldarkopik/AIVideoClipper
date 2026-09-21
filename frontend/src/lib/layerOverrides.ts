import type { LayerOverrides, TemplateLayer } from "@/lib/types";

/**
 * Recomputes the full layer_overrides patch by diffing the editor's current
 * (merged, user-edited) layers array against the template's raw layers — not
 * against whatever overrides existed before. This is correct even when the clip
 * already had overrides: the editor's initial state already has them baked in
 * (see /clips/{id}/preview-config's merged `layers`), so re-diffing against the
 * raw template on every save regenerates the right override set — including
 * dropping an override entirely if the user edits a layer back to match the
 * template exactly. Mirrors LayerOverrideMerger on the backend, in reverse.
 */
export function diffLayers(templateLayers: TemplateLayer[], editedLayers: TemplateLayer[]): LayerOverrides | null {
  const templateById = new Map(templateLayers.map((l) => [l.id, l]));
  const editedById = new Map(editedLayers.map((l) => [l.id, l]));

  const overrides: LayerOverrides = {};
  let hasOverrides = false;

  for (const layer of templateLayers) {
    const edited = editedById.get(layer.id);
    if (!edited) {
      overrides._removed = [...(overrides._removed ?? []), layer.id];
      hasOverrides = true;
    } else if (JSON.stringify(layer) !== JSON.stringify(edited)) {
      overrides[layer.id] = edited;
      hasOverrides = true;
    }
  }

  const newLayers = editedLayers.filter((l) => !templateById.has(l.id));
  if (newLayers.length > 0) {
    overrides._new = newLayers;
    hasOverrides = true;
  }

  return hasOverrides ? overrides : null;
}
