"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Label, Select } from "@/components/ui/Input";
import { PLATFORM_LABELS } from "@/components/social/platforms";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import type { SocialAccount } from "@/lib/types";

function MoveChannelForm({
  postIds,
  usingSelection,
  accounts,
  onClose,
  onMoved,
}: {
  postIds: number[];
  usingSelection: boolean;
  accounts: SocialAccount[];
  onClose: () => void;
  onMoved: () => void;
}) {
  const [targetId, setTargetId] = useState(accounts[0] ? String(accounts[0].id) : "");
  const [submitting, setSubmitting] = useState(false);

  const accountsByPlatform = accounts.reduce<Record<string, SocialAccount[]>>((acc, a) => {
    (acc[a.platform] ??= []).push(a);
    return acc;
  }, {});

  async function handleSubmit() {
    if (!targetId) return;
    setSubmitting(true);
    try {
      const res = await api.post<{ moved_count: number }>("/social-posts/bulk-move-channel", {
        social_post_ids: postIds,
        target_social_account_id: Number(targetId),
      });
      onMoved();
      toast(`Moved ${res.moved_count} post(s).`, "success");
      onClose();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to move posts.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-4">
      <p className="text-xs text-muted">
        Retargets {postIds.length} post{postIds.length === 1 ? "" : "s"}{" "}
        {usingSelection ? "you selected" : "currently in view"} (already-published or in-progress ones are skipped)
        onto a single channel, then restaggers just the moved ones onto that channel&apos;s own schedule — their old
        time slot was picked for the wrong account&apos;s queue, so it wouldn&apos;t make sense to keep it.
      </p>

      <div>
        <Label>Move to channel</Label>
        <Select value={targetId} onChange={(e) => setTargetId(e.target.value)}>
          {accounts.length === 0 && <option value="">No connected channels</option>}
          {Object.entries(accountsByPlatform).map(([platform, group]) => (
            <optgroup key={platform} label={PLATFORM_LABELS[platform] ?? platform}>
              {group.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.account_name}
                </option>
              ))}
            </optgroup>
          ))}
        </Select>
      </div>

      <Button className="w-full" onClick={handleSubmit} loading={submitting} disabled={!targetId}>
        Move {postIds.length} Post{postIds.length === 1 ? "" : "s"}
      </Button>
    </div>
  );
}

export function MoveChannelModal({
  open,
  postIds,
  usingSelection,
  accounts,
  onClose,
  onMoved,
}: {
  open: boolean;
  postIds: number[];
  usingSelection: boolean;
  accounts: SocialAccount[];
  onClose: () => void;
  onMoved: () => void;
}) {
  return (
    <Modal open={open} onClose={onClose} title="Move to Channel">
      {open && (
        <MoveChannelForm
          postIds={postIds}
          usingSelection={usingSelection}
          accounts={accounts}
          onClose={onClose}
          onMoved={onMoved}
        />
      )}
    </Modal>
  );
}
