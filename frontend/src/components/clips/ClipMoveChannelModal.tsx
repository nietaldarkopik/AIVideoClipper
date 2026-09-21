"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Label, Select } from "@/components/ui/Input";
import { PLATFORM_LABELS } from "@/components/social/platforms";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import type { SocialAccount } from "@/lib/types";

/**
 * Clip-page counterpart to scheduler's MoveChannelModal — same destination-
 * account picker, but targets clips (this page's checkboxes) via /clips/bulk-
 * move-channel instead of already-scheduled posts. See ClipController::
 * bulkMoveChannel for what happens per clip: a clip with an existing
 * non-terminal post gets it retargeted, one with none gets a fresh scheduled
 * post created, and a clip already covered at the destination is left alone
 * (any other pending post for it is cancelled instead of duplicating).
 */
function MoveChannelForm({
  clipIds,
  accounts,
  onClose,
  onMoved,
}: {
  clipIds: number[];
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
      const res = await api.post<{ message: string; moved_count: number; created_count: number; cancelled_count: number }>(
        "/clips/bulk-move-channel",
        { clip_ids: clipIds, target_social_account_id: Number(targetId) }
      );
      onMoved();
      toast(res.message, "success");
      onClose();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to move clips.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-4">
      <p className="text-xs text-muted">
        Retargets {clipIds.length} clip{clipIds.length === 1 ? "" : "s"} onto a single channel. A clip already
        scheduled or published elsewhere gets moved there; one never assigned to any account gets freshly
        scheduled. A clip already covered at the destination is skipped rather than duplicated.
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
        Move {clipIds.length} Clip{clipIds.length === 1 ? "" : "s"}
      </Button>
    </div>
  );
}

export function ClipMoveChannelModal({
  open,
  clipIds,
  accounts,
  onClose,
  onMoved,
}: {
  open: boolean;
  clipIds: number[];
  accounts: SocialAccount[];
  onClose: () => void;
  onMoved: () => void;
}) {
  return (
    <Modal open={open} onClose={onClose} title="Move to Channel">
      {open && <MoveChannelForm clipIds={clipIds} accounts={accounts} onClose={onClose} onMoved={onMoved} />}
    </Modal>
  );
}
