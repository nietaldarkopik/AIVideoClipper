"use client";

import { useState } from "react";
import { mutate } from "swr";
import { Clock, Zap } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { PLATFORM_LABELS } from "@/components/social/platforms";
import type { Paginated, SocialPost } from "@/lib/types";

/**
 * Auto-publish schedules clips 30-60 min apart instead of all at once (see
 * AutoPublishScheduler on the backend) — that gap is otherwise invisible on the
 * project page since it isn't a "processing job" in the ffmpeg/AI sense, just a
 * queue delay. This surfaces it directly, decoupled from the project's own
 * active/inactive status so it stays visible for however long the schedule runs.
 */
export function ScheduledPublishing({ projectId }: { projectId: number }) {
  const key = `/social-posts?project_id=${projectId}&status=scheduled&per_page=50`;
  const { data } = useApi<Paginated<SocialPost>>(key, { refreshInterval: 20000 });
  const [publishingId, setPublishingId] = useState<number | null>(null);

  const posts = data?.data ?? [];
  if (posts.length === 0) return null;

  const sorted = [...posts].sort((a, b) => (a.scheduled_at ?? "").localeCompare(b.scheduled_at ?? ""));

  async function handlePublishNow(postId: number) {
    setPublishingId(postId);
    try {
      await api.post(`/social-posts/${postId}/publish-now`);
      await mutate(key);
      toast("Publishing now.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to publish now.", "danger");
    } finally {
      setPublishingId(null);
    }
  }

  return (
    <Card className="p-5">
      <div className="flex items-center gap-2 text-sm font-medium text-accent-2">
        <Clock className="size-4" />
        {sorted.length} post{sorted.length === 1 ? "" : "s"} scheduled to publish
      </div>
      <p className="mt-1 text-xs text-muted">
        Staggered 30-60 min apart so clips don&apos;t all post at once — jump the queue with Publish Now.
      </p>
      <div className="mt-3 space-y-2">
        {sorted.map((post) => (
          <div
            key={post.id}
            className="flex items-center justify-between gap-2 rounded-xl bg-surface-elevated px-3 py-2 text-xs"
          >
            <div className="min-w-0">
              <p className="font-medium">
                {PLATFORM_LABELS[post.platform] ?? post.platform} — {post.social_account?.account_name}
              </p>
              <p className="text-muted">
                {post.scheduled_at
                  ? new Date(post.scheduled_at).toLocaleString([], { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" })
                  : "Pending"}
              </p>
            </div>
            <Button
              size="sm"
              variant="outline"
              onClick={() => handlePublishNow(post.id)}
              loading={publishingId === post.id}
            >
              <Zap className="size-3.5" />
              Publish Now
            </Button>
          </div>
        ))}
      </div>
    </Card>
  );
}
