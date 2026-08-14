"use client";

import { useState } from "react";
import { mutate } from "swr";
import { Share2, Sparkles, ExternalLink, RotateCcw, Plus } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { StatusBadge } from "@/components/ui/Badge";
import { formatRelativeTime } from "@/lib/format";
import { PLATFORM_LABELS } from "@/components/social/platforms";
import type { SocialAccount, SocialPost } from "@/lib/types";

type MetadataSuggestion = {
  title?: string;
  caption?: string;
  description?: string;
  hashtags: string[];
  cta?: string;
};

export function PublishPanel({
  clipId,
  clipReady,
  onUseCaption,
}: {
  clipId: number;
  clipReady: boolean;
  onUseCaption: (text: string) => void;
}) {
  const { data: accountsRes } = useApi<{ data: SocialAccount[] }>("/social-accounts");
  const postsKey = `/social-posts?clip_id=${clipId}`;
  const { data: postsRes } = useApi<{ data: SocialPost[] }>(postsKey);

  const [selected, setSelected] = useState<number[]>([]);
  const [publishing, setPublishing] = useState(false);
  const [generating, setGenerating] = useState(false);
  const [suggestions, setSuggestions] = useState<Record<string, MetadataSuggestion> | null>(null);

  const connected = accountsRes?.data.filter((a) => a.status === "connected") ?? [];

  function toggle(id: number) {
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  }

  async function handleGenerateMetadata() {
    setGenerating(true);
    try {
      const res = await api.post<{ metadata: Record<string, MetadataSuggestion> }>(
        `/clips/${clipId}/generate-social-metadata`
      );
      setSuggestions(res.metadata);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to generate metadata.", "danger");
    } finally {
      setGenerating(false);
    }
  }

  async function handlePublish() {
    if (selected.length === 0) {
      toast("Select at least one account.", "danger");
      return;
    }
    setPublishing(true);
    try {
      await api.post("/social-posts", { clip_id: clipId, social_account_ids: selected });
      await mutate(postsKey);
      toast(`Publishing to ${selected.length} account${selected.length > 1 ? "s" : ""}.`, "success");
      setSelected([]);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to publish.", "danger");
    } finally {
      setPublishing(false);
    }
  }

  async function handleRetry(postId: number) {
    try {
      await api.post(`/social-posts/${postId}/retry`);
      await mutate(postsKey);
      toast("Retry queued.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to retry.", "danger");
    }
  }

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <h3 className="flex items-center gap-2 text-sm font-semibold">
          <Share2 className="size-4 text-accent-2" />
          Publish To
        </h3>
        <Button variant="outline" size="sm" onClick={handleGenerateMetadata} loading={generating}>
          <Sparkles className="size-3.5" />
          Generate with AI
        </Button>
      </div>

      {connected.length === 0 ? (
        <p className="rounded-xl bg-surface-elevated p-3 text-xs text-muted">
          No connected social accounts.{" "}
          <a href="/social-accounts" className="text-accent-2 hover:underline">
            Connect one
          </a>{" "}
          to publish directly from here.
        </p>
      ) : (
        <div className="space-y-1.5">
          {connected.map((account) => (
            <label
              key={account.id}
              className="flex cursor-pointer items-center gap-3 rounded-xl border border-border-subtle px-3 py-2.5 hover:bg-white/5"
            >
              <input
                type="checkbox"
                checked={selected.includes(account.id)}
                onChange={() => toggle(account.id)}
                className="size-4 rounded accent-accent"
              />
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm">
                  {PLATFORM_LABELS[account.platform] ?? account.platform} — {account.account_name}
                </p>
              </div>
              {suggestions?.[account.platform] && (
                <button
                  type="button"
                  onClick={(e) => {
                    e.preventDefault();
                    const s = suggestions[account.platform];
                    onUseCaption(s.caption ?? s.description ?? "");
                  }}
                  className="flex items-center gap-1 text-xs text-accent-2 hover:underline"
                >
                  <Plus className="size-3" />
                  Use caption
                </button>
              )}
            </label>
          ))}
        </div>
      )}

      <Button className="w-full" onClick={handlePublish} loading={publishing} disabled={!clipReady}>
        Publish to {selected.length || ""} {selected.length === 1 ? "account" : "accounts"}
      </Button>
      {!clipReady && <p className="text-xs text-warning">Clip must finish rendering before publishing.</p>}

      {(postsRes?.data.length ?? 0) > 0 && (
        <div>
          <h4 className="mb-2 text-xs font-medium text-muted">Publishing History</h4>
          <div className="space-y-2">
            {postsRes!.data.map((post) => (
              <div
                key={post.id}
                className="flex items-center justify-between gap-2 rounded-xl bg-surface-elevated px-3 py-2 text-xs"
              >
                <div className="min-w-0">
                  <p className="font-medium">
                    {PLATFORM_LABELS[post.platform] ?? post.platform} — {post.social_account?.account_name}
                  </p>
                  <p className="text-muted">
                    {post.published_at ? formatRelativeTime(post.published_at) : post.error_message ?? "Pending"}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <StatusBadge status={post.status} />
                  {post.post_url && (
                    <a href={post.post_url} target="_blank" rel="noreferrer" className="text-accent-2">
                      <ExternalLink className="size-3.5" />
                    </a>
                  )}
                  {post.status === "failed" && (
                    <button onClick={() => handleRetry(post.id)} className="text-accent-2 cursor-pointer">
                      <RotateCcw className="size-3.5" />
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
