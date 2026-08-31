"use client";

import { useState } from "react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input, Label, Select } from "@/components/ui/Input";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { PLATFORM_LABELS } from "@/components/social/platforms";
import type { Clip, Paginated, Project, SocialAccount } from "@/lib/types";

// Mounted only while the modal is open (see NewScheduleModal below), so every
// open gets fresh useState defaults instead of needing an effect to reset them.
function NewScheduleForm({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
  const [projectId, setProjectId] = useState<string>("");
  const [clipId, setClipId] = useState<string>("");
  const [accountIds, setAccountIds] = useState<number[]>([]);
  const [scheduledAt, setScheduledAt] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [minDateTime] = useState(() => new Date(Date.now() - (Date.now() % 60000)).toISOString().slice(0, 16));

  const { data: projectsRes } = useApi<Paginated<Project>>("/projects?per_page=100");
  const { data: clipsRes } = useApi<Paginated<Clip>>(
    projectId ? `/clips?project_id=${projectId}&status=completed&per_page=100` : null
  );
  const { data: accountsRes } = useApi<{ data: SocialAccount[] }>("/social-accounts");

  const connected = (accountsRes?.data ?? []).filter((a) => a.status === "connected");

  function handleProjectChange(value: string) {
    setProjectId(value);
    setClipId("");
  }

  function toggleAccount(id: number) {
    setAccountIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  }

  async function handleSubmit() {
    if (!clipId || accountIds.length === 0 || !scheduledAt) {
      toast("Pick a clip, at least one channel, and a time.", "danger");
      return;
    }
    setSubmitting(true);
    try {
      await api.post("/social-posts", {
        clip_id: Number(clipId),
        social_account_ids: accountIds,
        scheduled_at: new Date(scheduledAt).toISOString(),
      });
      toast("Schedule created.", "success");
      onCreated();
      onClose();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to create schedule.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-4">
      <div>
        <Label>Project</Label>
        <Select value={projectId} onChange={(e) => handleProjectChange(e.target.value)}>
          <option value="">Select a project…</option>
          {(projectsRes?.data ?? []).map((p) => (
            <option key={p.id} value={p.id}>
              {p.title}
            </option>
          ))}
        </Select>
      </div>

      <div>
        <Label>Clip</Label>
        <Select value={clipId} onChange={(e) => setClipId(e.target.value)} disabled={!projectId}>
          <option value="">{projectId ? "Select a clip…" : "Pick a project first"}</option>
          {(clipsRes?.data ?? []).map((c) => (
            <option key={c.id} value={c.id}>
              {c.title ?? `Clip #${c.id}`}
            </option>
          ))}
        </Select>
        {projectId && (clipsRes?.data.length ?? 0) === 0 && (
          <p className="mt-1 text-[11px] text-muted">No finished clips in this project yet.</p>
        )}
      </div>

      <div>
        <Label>Channels</Label>
        {connected.length === 0 ? (
          <p className="rounded-xl bg-surface-elevated p-3 text-xs text-muted">
            No connected social accounts.{" "}
            <a href="/social-accounts" className="text-accent-2 hover:underline">
              Connect one
            </a>
            .
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
        <Label>Publish at</Label>
        <Input
          type="datetime-local"
          value={scheduledAt}
          min={minDateTime}
          onChange={(e) => setScheduledAt(e.target.value)}
        />
      </div>

      <Button className="w-full" onClick={handleSubmit} loading={submitting}>
        Create Schedule
      </Button>
    </div>
  );
}

export function NewScheduleModal({
  open,
  onClose,
  onCreated,
}: {
  open: boolean;
  onClose: () => void;
  onCreated: () => void;
}) {
  return (
    <Modal open={open} onClose={onClose} title="New Schedule">
      {open && <NewScheduleForm onClose={onClose} onCreated={onCreated} />}
    </Modal>
  );
}
