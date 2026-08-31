"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input, Label } from "@/components/ui/Input";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";

const HOURS = Array.from({ length: 25 }, (_, i) => i); // 0..24 (24 = midnight/end of day)

function RescheduleAllForm({
  postIds,
  onClose,
  onRescheduled,
}: {
  postIds: number[];
  onClose: () => void;
  onRescheduled: () => void;
}) {
  const [maxPerDay, setMaxPerDay] = useState(5);
  const [minMinutes, setMinMinutes] = useState(30);
  const [maxMinutes, setMaxMinutes] = useState(120);
  const [windowEnabled, setWindowEnabled] = useState(false);
  const [windowStart, setWindowStart] = useState(9);
  const [windowEnd, setWindowEnd] = useState(21);
  const [submitting, setSubmitting] = useState(false);

  const gapInvalid = minMinutes > maxMinutes;
  const windowInvalid = windowEnabled && windowStart >= windowEnd;

  async function handleSubmit() {
    if (gapInvalid || windowInvalid) return;
    setSubmitting(true);
    try {
      const res = await api.post<{ rescheduled_count: number }>("/social-posts/bulk-reschedule", {
        social_post_ids: postIds,
        max_per_day: maxPerDay,
        min_minutes: minMinutes,
        max_minutes: maxMinutes,
        ...(windowEnabled ? { window_start_hour: windowStart, window_end_hour: windowEnd } : {}),
      });
      onRescheduled();
      toast(`Rescheduled ${res.rescheduled_count} post(s).`, "success");
      onClose();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to reschedule.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-4">
      <p className="text-xs text-muted">
        Redistributes {postIds.length} post{postIds.length === 1 ? "" : "s"} currently in view (already-published or
        in-progress ones are skipped), staggered per channel so posts don&apos;t hit the same account back-to-back.
      </p>

      <div>
        <Label>Max clips per day, per channel</Label>
        <Input
          type="number"
          min={1}
          max={50}
          value={maxPerDay}
          onChange={(e) => setMaxPerDay(Math.max(1, Number(e.target.value)))}
        />
        <p className="mt-1 text-[11px] text-muted">
          Once a channel hits this many posts on a day, the rest roll over to the next day.
        </p>
      </div>

      <div>
        <Label>Random gap between posts on the same channel</Label>
        <div className="flex items-center gap-2">
          <Input
            type="number"
            min={1}
            value={minMinutes}
            onChange={(e) => setMinMinutes(Math.max(1, Number(e.target.value)))}
          />
          <span className="text-xs text-muted">to</span>
          <Input
            type="number"
            min={1}
            value={maxMinutes}
            onChange={(e) => setMaxMinutes(Math.max(1, Number(e.target.value)))}
          />
          <span className="whitespace-nowrap text-xs text-muted">minutes</span>
        </div>
        {gapInvalid && <p className="mt-1 text-[11px] text-danger">Min must be less than or equal to max.</p>}
      </div>

      <div>
        <label className="flex cursor-pointer items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={windowEnabled}
            onChange={(e) => setWindowEnabled(e.target.checked)}
            className="size-4 rounded accent-accent"
          />
          Only post between certain hours
        </label>
        {windowEnabled && (
          <div className="mt-2 flex items-center gap-2">
            <select
              value={windowStart}
              onChange={(e) => setWindowStart(Number(e.target.value))}
              className="w-full rounded-xl border border-border-subtle bg-surface px-3.5 py-2.5 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
            >
              {HOURS.slice(0, 24).map((h) => (
                <option key={h} value={h}>
                  {String(h).padStart(2, "0")}:00
                </option>
              ))}
            </select>
            <span className="text-xs text-muted">to</span>
            <select
              value={windowEnd}
              onChange={(e) => setWindowEnd(Number(e.target.value))}
              className="w-full rounded-xl border border-border-subtle bg-surface px-3.5 py-2.5 text-sm text-foreground focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
            >
              {HOURS.slice(1).map((h) => (
                <option key={h} value={h}>
                  {h === 24 ? "24:00" : `${String(h).padStart(2, "0")}:00`}
                </option>
              ))}
            </select>
          </div>
        )}
        {windowInvalid && <p className="mt-1 text-[11px] text-danger">End hour must be after start hour.</p>}
        {!windowEnabled && (
          <p className="mt-1 text-[11px] text-muted">Off by default — posts can land at any hour, as before.</p>
        )}
      </div>

      <Button className="w-full" onClick={handleSubmit} loading={submitting} disabled={gapInvalid || windowInvalid}>
        Reschedule {postIds.length} Post{postIds.length === 1 ? "" : "s"}
      </Button>
    </div>
  );
}

export function RescheduleAllModal({
  open,
  postIds,
  onClose,
  onRescheduled,
}: {
  open: boolean;
  postIds: number[];
  onClose: () => void;
  onRescheduled: () => void;
}) {
  return (
    <Modal open={open} onClose={onClose} title="Reschedule All">
      {open && <RescheduleAllForm postIds={postIds} onClose={onClose} onRescheduled={onRescheduled} />}
    </Modal>
  );
}
