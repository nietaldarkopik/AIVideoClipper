"use client";

import { useState } from "react";
import Link from "next/link";
import { Image as ImageIcon, Maximize2 } from "lucide-react";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { MediaPreviewModal } from "@/components/ui/MediaPreviewModal";
import type { CoverTemplate } from "@/lib/types";

export function CoverTemplateCard({ coverTemplate }: { coverTemplate: CoverTemplate }) {
  const [previewOpen, setPreviewOpen] = useState(false);
  const aspectClass =
    coverTemplate.aspect_ratio === "16:9"
      ? "aspect-video"
      : coverTemplate.aspect_ratio === "1:1"
        ? "aspect-square"
        : "aspect-[9/16]";

  return (
    <>
      <Link href={`/cover-templates/${coverTemplate.id}`}>
        <Card className="group overflow-hidden transition-colors hover:border-accent/40">
          <div
            className={`relative flex ${aspectClass} max-h-56 w-full items-center justify-center overflow-hidden bg-gradient-to-br from-surface-elevated to-surface`}
          >
            {coverTemplate.thumbnail_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={coverTemplate.thumbnail_url} alt={coverTemplate.name} className="h-full w-full object-cover" />
            ) : (
              <ImageIcon className="size-8 text-muted" />
            )}
            <div className="absolute right-2 top-2">
              <Badge tone="muted">{coverTemplate.aspect_ratio}</Badge>
            </div>
            {coverTemplate.thumbnail_url && (
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
            <h3 className="truncate text-sm font-semibold">{coverTemplate.name}</h3>
            <p className="mt-1 line-clamp-2 text-xs text-muted">{coverTemplate.description}</p>
          </div>
        </Card>
      </Link>

      <MediaPreviewModal
        open={previewOpen}
        onClose={() => setPreviewOpen(false)}
        title={coverTemplate.name}
        type="image"
        src={coverTemplate.thumbnail_url}
      />
    </>
  );
}
