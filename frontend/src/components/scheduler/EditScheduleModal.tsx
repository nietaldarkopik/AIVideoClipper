"use client";

import { useState } from "react";
import { PlayCircle, RefreshCw } from "lucide-react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input, Label, Select, Textarea } from "@/components/ui/Input";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { PLATFORM_LABELS } from "@/components/social/platforms";
import { ClipPreviewModal } from "@/components/scheduler/ClipPreviewModal";
import type { SocialAccount, SocialPost } from "@/lib/types";

// datetime-local wants "YYYY-MM-DDTHH:mm" in LOCAL time — new Date(iso).toISOString()
// would convert to UTC instead, silently shifting the displayed time by the
// viewer's offset every time this modal opens.
function toLocalInputValue(iso: string | null): string {
  if (!iso) return "";
  const d = new Date(iso);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

const LOCKED_STATUSES = new Set(["published", "uploading", "publishing"]);

// Mounted fresh (keyed by post.id, see EditScheduleModal below) each time a
// different post is opened for editing, so useState's lazy initializers read
// straight from `post` with no effect needed to sync them.
function EditScheduleForm({ post, onClose, onSaved }: { post: SocialPost; onClose: () => void; onSaved: () => void }) {
  const [accountId, setAccountId] = useState<string>(post.social_account ? String(post.social_account.id) : "");
  const [scheduledAt, setScheduledAt] = useState(() => toLocalInputValue(post.scheduled_at));
  const [title, setTitle] = useState(post.title ?? "");
  const [caption, setCaption] = useState(post.caption ?? "");
  const [saving, setSaving] = useState(false);
  const [regenerating, setRegenerating] = useState(false);
  const [previewing, setPreviewing] = useState(false);
  // Reflects the latest known status/scheduled_at (regenerate persists
  // immediately, ahead of Save Changes) so the preview modal and "locked"
  // guard stay accurate without waiting for the parent list to refetch.
  const [livePost, setLivePost] = useState(post);

  const { data: accountsRes } = useApi<{ data: SocialAccount[] }>("/social-accounts");
  const connected = (accountsRes?.data ?? []).filter(
    (a) => a.status === "connected" && (a.platform === post.platform || a.id === post.social_account?.id)
  );

  async function handleSubmit() {
    setSaving(true);
    try {
      await api.patch(`/social-posts/${post.id}`, {
        social_account_id: accountId ? Number(accountId) : undefined,
        scheduled_at: scheduledAt ? new Date(scheduledAt).toISOString() : null,
        title,
        caption,
      });
      toast("Schedule updated.", "success");
      onSaved();
      onClose();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to update schedule.", "danger");
    } finally {
      setSaving(false);
    }
  }

  // Recomputes scheduled_at via the same stagger/day-cap logic the auto-
  // scheduler uses, for whichever channel is currently selected in the form —
  // handles both "I just switched channel, give me a sane time for it" and
  // "bump this good clip sooner" in one action. Persists immediately (unlike
  // the other fields, which wait for Save Changes) so a stale delayed job from
  // before this click can't race it.
  async function handleRegenerate() {
    setRegenerating(true);
    try {
      const res = await api.post<{ data: SocialPost }>(`/social-posts/${post.id}/regenerate-schedule`, {
        social_account_id: accountId ? Number(accountId) : undefined,
      });
      setScheduledAt(toLocalInputValue(res.data.scheduled_at));
      setLivePost(res.data);
      onSaved();
      toast("Schedule regenerated.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to regenerate schedule.", "danger");
    } finally {
      setRegenerating(false);
    }
  }

  return (
    <div className="space-y-4">
      <div>
        <Label>Channel</Label>
        <Select value={accountId} onChange={(e) => setAccountId(e.target.value)}>
          {connected.map((a) => (
            <option key={a.id} value={a.id}>
              {PLATFORM_LABELS[a.platform] ?? a.platform} — {a.account_name}
            </option>
          ))}
        </Select>
      </div>

      <div>
        <div className="mb-1.5 flex items-center justify-between">
          <label className="block text-xs font-medium text-muted">Publish at</label>
          <button
            type="button"
            onClick={handleRegenerate}
            disabled={regenerating}
            className="flex items-center gap-1 text-xs text-accent-2 hover:underline disabled:opacity-50 cursor-pointer"
          >
            <RefreshCw className={"size-3 " + (regenerating ? "animate-spin" : "")} />
            Regenerate
          </button>
        </div>
        <Input type="datetime-local" value={scheduledAt} onChange={(e) => setScheduledAt(e.target.value)} />
        <p className="mt-1 text-[11px] text-muted">
          Regenerate finds the next non-spammy slot for the selected channel — same pacing the auto-scheduler uses.
        </p>
      </div>

      <div>
        <Label>Title</Label>
        <Input value={title} onChange={(e) => setTitle(e.target.value)} />
      </div>

      <div>
        <Label>Caption</Label>
        <Textarea rows={3} value={caption} onChange={(e) => setCaption(e.target.value)} />
      </div>

      <div className="flex gap-2">
        <Button type="button" variant="outline" onClick={() => setPreviewing(true)} disabled={!livePost.clip?.url}>
          <PlayCircle className="size-4" />
          Preview clip
        </Button>
        <Button className="flex-1" onClick={handleSubmit} loading={saving}>
          Save Changes
        </Button>
      </div>

      <ClipPreviewModal post={previewing ? livePost : null} onClose={() => setPreviewing(false)} />
    </div>
  );
}

export function EditScheduleModal({
  post,
  onClose,
  onSaved,
}: {
  post: SocialPost | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const locked = post ? LOCKED_STATUSES.has(post.status) : false;

  return (
    <Modal open={!!post} onClose={onClose} title="Edit Schedule">
      {post && locked && (
        <p className="rounded-xl bg-surface-elevated p-3 text-sm text-muted">
          This post is already {post.status} and can no longer be edited.
        </p>
      )}
      {post && !locked && <EditScheduleForm key={post.id} post={post} onClose={onClose} onSaved={onSaved} />}
    </Modal>
  );
}
