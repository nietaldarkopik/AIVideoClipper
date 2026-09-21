<?php

namespace App\Services\Video;

/**
 * Merges a clip's layer_overrides over a template's config['layers'] — the layer
 * equivalent of how RenderClipJob already merges clip.subtitle_config over
 * config['caption']. Pure/stateless so it's testable without FFmpeg or a DB.
 *
 * $overrides shape (keyed by layer id):
 *   {
 *     "<layer-id>": { ...partial layer fields to array_replace_recursive over the template layer... },
 *     "_new": [ { ...full clip-only layer objects, no matching template layer... } ],
 *     "_removed": ["<layer-id>", ...]   // hide these template layers for this clip only
 *   }
 */
class LayerOverrideMerger
{
    /**
     * @param  array<int, array<string, mixed>>  $templateLayers
     * @param  array<string, mixed>|null  $overrides
     * @return array<int, array<string, mixed>>
     */
    public static function merge(array $templateLayers, ?array $overrides): array
    {
        if (empty($overrides)) {
            return $templateLayers;
        }

        $removed = array_flip($overrides['_removed'] ?? []);

        $merged = [];
        foreach ($templateLayers as $layer) {
            $id = $layer['id'] ?? null;
            if ($id !== null && isset($removed[$id])) {
                continue;
            }
            if ($id !== null && isset($overrides[$id]) && is_array($overrides[$id])) {
                $layer = array_replace_recursive($layer, $overrides[$id]);
            }
            $merged[] = $layer;
        }

        foreach ($overrides['_new'] ?? [] as $extra) {
            if (is_array($extra)) {
                $merged[] = $extra;
            }
        }

        return $merged;
    }
}
