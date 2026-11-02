"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input, Label } from "@/components/ui/Input";
import { PLATFORM_LABELS } from "@/components/social/platforms";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import type { SocialAccount } from "@/lib/types";

/**
 * Sets an explicit publish schedule for the selected clips across one or more
 * channels at once — see ClipController::bulkSchedule. Distinct from "Move to
 * Channel" (retargets existing schedules, no time control) and the Scheduler
 * page's "Reschedule All" (redistributes from now, no explicit start time):
 * this is the one place a start time is caller-chosen. Each channel gets its
 * own cursor starting at that time, staggered forward by the gap below per
 * clip — same independent-per-channel reasoning as bulk reschedule.
 */
function ScheduleForm({
  clipIds,
  accounts,
  onClose,
  onScheduled,
}: {
  clipIds: number[];
  accounts: SocialAccount[];
  onClose: () => void;
  onScheduled: () => void;
}) {
  const [accountIds, setAccountIds] = useState<number[]>([]);
  const [scheduledAt, setScheduledAt] = useState("");
  const [gapMin, setGapMin] = useState(30);
  const [gapMax, setGapMax] = useState(90);
  const [submitting, setSubmitting] = useState(false);
  const [minDateTime] = useState(() => new Date(Date.now() - (Date.now() % 60000)).toISOString().slice(0, 16));

  const gapInvalid = gapMin > gapMax;

  function toggleAccount(id: number) {
    setAccountIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  }

  async function handleSubmit() {
    if (accountIds.length === 0 || !scheduledAt || gapInvalid) return;
    setSubmitting(true);
    try {
      const res = await api.post<{ message: string; scheduled_count: number }>("/clips/bulk-schedule", {
        clip_ids: clipIds,
        social_account_ids: accountIds,
        scheduled_at: new Date(scheduledAt).toISOString(),
        gap_min_minutes: gapMin,
        gap_max_minutes: gapMax,
      });
      onScheduled();
      toast(res.message, "success");
      onClose();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to schedule clips.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-4">
      <p className="text-xs text-muted">
        Schedules {clipIds.length} clip{clipIds.length === 1 ? "" : "s"} to publish, starting at the time below. A
        clip already published or in-flight on a channel is left alone; one already scheduled there gets moved to
        the new time instead of duplicated.
      </p>

      <div>
        <Label>Channels</Label>
        {accounts.length === 0 ? (
          <p className="rounded-xl bg-surface-elevated p-3 text-xs text-muted">
            No connected social accounts.{" "}
            <a href="/social-accounts" className="text-accent-2 hover:underline">
              Connect one
            </a>
            .
          </p>
        ) : (
          <div className="space-y-1.5">
            {accounts.map((account) => (
              <label
                key={account.id}
                className="flex cursor-pointer items-center gap-3 rounded-xl border border-border-subtle px-3 py-2.5 hover:bg-white/5"
              >
                <input
                  type="checkbox"
                  checked={accountIds.includes(account.id)}
                  onChange={() => toggleAccount(account.id)}
                  className="size-4 rounded accent-accent"
                />
                <span className="truncate text-sm">
                  {PLATFORM_LABELS[account.platform] ?? account.platform} — {account.account_name}
                </span>
              </label>
            ))}
          </div>
        )}
      </div>

      <div>
        <Label>Start publishing at</Label>
        <Input
          type="datetime-local"
          value={scheduledAt}
          min={minDateTime}
          onChange={(e) => setScheduledAt(e.target.value)}
        />
      </div>

      <div>
        <Label>Random gap between clips, per channel</Label>
        <div className="flex items-center gap-2">
          <Input type="number" min={0} value={gapMin} onChange={(e) => setGapMin(Math.max(0, Number(e.target.value)))} />
          <span className="text-xs text-muted">to</span>
          <Input type="number" min={0} value={gapMax} onChange={(e) => setGapMax(Math.max(0, Number(e.target.value)))} />
          <span className="whitespace-nowrap text-xs text-muted">minutes</span>
        </div>
        {gapInvalid && <p className="mt-1 text-[11px] text-danger">Min must be less than or equal to max.</p>}
      </div>

      <Button
        className="w-full"
        onClick={handleSubmit}
        loading={submitting}
        disabled={accountIds.length === 0 || !scheduledAt || gapInvalid}
      >
        Schedule {clipIds.length} Clip{clipIds.length === 1 ? "" : "s"}
      </Button>
    </div>
  );
}

export function ClipScheduleModal({
  open,
  clipIds,
  accounts,
  onClose,
  onScheduled,
}: {
  open: boolean;
  clipIds: number[];
  accounts: SocialAccount[];
  onClose: () => void;
  onScheduled: () => void;
}) {
  return (
    <Modal open={open} onClose={onClose} title="Set Schedule">
      {open && <ScheduleForm clipIds={clipIds} accounts={accounts} onClose={onClose} onScheduled={onScheduled} />}
    </Modal>
  );
}
