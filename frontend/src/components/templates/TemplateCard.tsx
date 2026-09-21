"use client";

import { useState } from "react";
import Link from "next/link";
import { LayoutTemplate, Maximize2 } from "lucide-react";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { MediaPreviewModal } from "@/components/ui/MediaPreviewModal";
import type { Template } from "@/lib/types";

export function TemplateCard({ template }: { template: Template }) {
  const [previewOpen, setPreviewOpen] = useState(false);
  const hasMedia = !!(template.preview_url || template.thumbnail_url);

  return (
    <>
      <Link href={`/templates/${template.id}`}>
        <Card className="group overflow-hidden transition-colors hover:border-accent/40">
          <div
            className="relative flex aspect-[9/16] max-h-56 w-full items-center justify-center overflow-hidden bg-gradient-to-br from-surface-elevated to-surface"
          >
            {template.preview_url ? (
              // Real footage with this template's caption/layer/crop config
              // already burned in (see TemplatePreviewService) — autoplays
              // muted/looped so the card itself shows the style working,
              // instead of a static cover image.
              <video
                src={template.preview_url}
                poster={template.thumbnail_url ?? undefined}
                autoPlay
                muted
                loop
                playsInline
                className="h-full w-full object-cover"
              />
            ) : template.thumbnail_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={template.thumbnail_url} alt={template.name} className="h-full w-full object-cover" />
            ) : (
              <LayoutTemplate className="size-8 text-muted" />
            )}
            <div className="absolute right-2 top-2">
              <Badge tone="muted">{template.aspect_ratio}</Badge>
            </div>
            {hasMedia && (
              <button
                type="button"
                title="View full preview"
                onClick={(e) => {
                  e.preventDefault();
                  e.stopPropagation();
                  setPreviewOpen(true);
                }}
                className="absolute bottom-2 left-2 flex items-center gap-1 rounded-lg bg-black/60 px-2 py-1 text-[11px] font-medium text-white opacity-0 backdrop-blur-sm transition-opacity group-hover:opacity-100 cursor-pointer"
              >
                <Maximize2 className="size-3" />
                View
              </button>
            )}
          </div>
          <div className="p-4">
            <h3 className="truncate text-sm font-semibold">{template.name}</h3>
            <p className="mt-1 line-clamp-2 text-xs text-muted">{template.description}</p>
            {template.category && (
              <Badge tone="accent" className="mt-2">
                {template.category.name}
              </Badge>
            )}
          </div>
        </Card>
      </Link>

      <MediaPreviewModal
        open={previewOpen}
        onClose={() => setPreviewOpen(false)}
        title={template.name}
        type={template.preview_url ? "video" : "image"}
        src={template.preview_url ?? template.thumbnail_url}
      />
    </>
  );
}
