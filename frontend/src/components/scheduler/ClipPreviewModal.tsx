"use client";

import { Modal } from "@/components/ui/Modal";
import { StatusBadge } from "@/components/ui/Badge";
import { formatDuration } from "@/lib/format";
import { PLATFORM_LABELS } from "@/components/social/platforms";
import type { SocialPost } from "@/lib/types";

export function ClipPreviewModal({ post, onClose }: { post: SocialPost | null; onClose: () => void }) {
  const clip = post?.clip;

  return (
    <Modal open={!!post} onClose={onClose} title={clip?.title ?? "Clip preview"} className="max-w-xl">
      {post && clip && (
        <div className="space-y-3">
          {clip.url ? (
            <video
              src={clip.url}
              controls
              autoPlay
              poster={clip.thumbnail_url ?? undefined}
              className="w-full rounded-xl bg-black"
            />
          ) : (
            <div className="flex aspect-video items-center justify-center rounded-xl bg-surface-elevated text-sm text-muted">
              Video not available.
            </div>
          )}
          <div className="flex flex-wrap items-center gap-2 text-xs text-muted">
            <StatusBadge status={post.status} />
            <span>{formatDuration(clip.duration)}</span>
            <span>·</span>
            <span>
              {PLATFORM_LABELS[post.platform] ?? post.platform}
              {post.social_account && ` — ${post.social_account.account_name}`}
            </span>
          </div>
        </div>
      )}
    </Modal>
  );
}
