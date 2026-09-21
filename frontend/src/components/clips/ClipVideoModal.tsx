"use client";

import { Modal } from "@/components/ui/Modal";
import { StatusBadge } from "@/components/ui/Badge";
import { formatDuration } from "@/lib/format";
import type { Clip } from "@/lib/types";

export function ClipVideoModal({ clip, onClose }: { clip: Clip | null; onClose: () => void }) {
  return (
    <Modal open={!!clip} onClose={onClose} title={clip?.title ?? "Clip preview"} className="max-w-xl">
      {clip && (
        <div className="space-y-3">
          {clip.url ? (
            <video
              src={clip.url}
              controls
              autoPlay
              poster={clip.thumbnail_url ?? undefined}
              className="mx-auto max-h-[75vh] rounded-xl bg-black"
            />
          ) : (
            <div className="flex aspect-video items-center justify-center rounded-xl bg-surface-elevated text-sm text-muted">
              Video not available yet.
            </div>
          )}
          <div className="flex flex-wrap items-center gap-2 text-xs text-muted">
            <StatusBadge status={clip.status} />
            <span>{formatDuration(clip.duration)}</span>
            {clip.template && (
              <>
                <span>·</span>
                <span>{clip.template.name}</span>
              </>
            )}
          </div>
        </div>
      )}
    </Modal>
  );
}
