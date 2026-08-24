"use client";

import { useState } from "react";
import { mutate } from "swr";
import { Plus, Star, Trash2, Pencil } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Input, Label } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { PLATFORM_LABELS } from "@/components/social/platforms";
import type { PublishingProfile, SocialAccount } from "@/lib/types";

export function PublishingProfiles() {
  const { data: profilesRes } = useApi<{ data: PublishingProfile[] }>("/publishing-profiles");
  const { data: accountsRes } = useApi<{ data: SocialAccount[] }>("/social-accounts");
  const [modalOpen, setModalOpen] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [name, setName] = useState("");
  const [selected, setSelected] = useState<number[]>([]);
  const [isDefault, setIsDefault] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  function toggle(id: number) {
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  }

  function openCreate() {
    setEditingId(null);
    setName("");
    setSelected([]);
    setIsDefault(false);
    setModalOpen(true);
  }

  function openEdit(profile: PublishingProfile) {
    setEditingId(profile.id);
    setName(profile.name);
    setSelected(profile.social_accounts.map((a) => a.id));
    setIsDefault(profile.is_default);
    setModalOpen(true);
  }

  function closeModal() {
    setModalOpen(false);
  }

  async function handleSubmit() {
    if (!name.trim()) {
      toast("Profile name is required.", "danger");
      return;
    }
    setSubmitting(true);
    try {
      const payload = { name, social_account_ids: selected, is_default: isDefault };
      if (editingId) {
        await api.patch(`/publishing-profiles/${editingId}`, payload);
        toast("Publishing profile updated.", "success");
      } else {
        await api.post("/publishing-profiles", payload);
        toast("Publishing profile created.", "success");
      }
      await mutate("/publishing-profiles");
      closeModal();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to save profile.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete(id: number) {
    if (!confirm("Delete this publishing profile?")) return;
    try {
      await api.del(`/publishing-profiles/${id}`);
      await mutate("/publishing-profiles");
      toast("Profile deleted.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to delete.", "danger");
    }
  }

  return (
    <Card className="p-5">
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-sm font-semibold">Publishing Profiles</h3>
          <p className="mt-1 text-xs text-muted">
            Group destination accounts so you can publish to all of them in one click.
          </p>
        </div>
        <Button size="sm" variant="outline" onClick={openCreate}>
          <Plus className="size-3.5" />
          New Profile
        </Button>
      </div>

      <div className="mt-4 space-y-2">
        {(profilesRes?.data ?? []).map((profile) => (
          <div key={profile.id} className="flex items-center justify-between rounded-xl bg-surface-elevated px-4 py-3">
            <div>
              <p className="flex items-center gap-1.5 text-sm font-medium">
                {profile.is_default && <Star className="size-3.5 text-warning" />}
                {profile.name}
              </p>
              <p className="mt-0.5 text-xs text-muted">
                {profile.social_accounts.length === 0
                  ? "No accounts"
                  : profile.social_accounts
                      .map((a) => `${PLATFORM_LABELS[a.platform] ?? a.platform} → ${a.account_name}`)
                      .join(", ")}
              </p>
            </div>
            <div className="flex items-center gap-1">
              <button
                onClick={() => openEdit(profile)}
                title="Edit profile"
                className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
              >
                <Pencil className="size-3.5" />
              </button>
              <button
                onClick={() => handleDelete(profile.id)}
                title="Delete profile"
                className="rounded-lg p-1.5 text-muted hover:bg-danger/10 hover:text-danger cursor-pointer"
              >
                <Trash2 className="size-3.5" />
              </button>
            </div>
          </div>
        ))}
        {(profilesRes?.data.length ?? 0) === 0 && (
          <p className="rounded-xl bg-surface-elevated px-4 py-4 text-center text-xs text-muted">
            No publishing profiles yet.
          </p>
        )}
      </div>

      <Modal open={modalOpen} onClose={closeModal} title={editingId ? "Edit Publishing Profile" : "New Publishing Profile"}>
        <div className="space-y-4">
          <div>
            <Label htmlFor="profile_name">Name</Label>
            <Input id="profile_name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Main Brand" />
          </div>
          <div>
            <Label>Accounts</Label>
            <div className="max-h-48 space-y-1.5 overflow-y-auto">
              {(accountsRes?.data ?? []).map((account) => (
                <label
                  key={account.id}
                  className="flex cursor-pointer items-center gap-2 rounded-lg border border-border-subtle px-3 py-2 text-sm hover:bg-white/5"
                >
                  <input
                    type="checkbox"
                    checked={selected.includes(account.id)}
                    onChange={() => toggle(account.id)}
                    className="size-4 rounded accent-accent"
                  />
                  {PLATFORM_LABELS[account.platform] ?? account.platform} — {account.account_name}
                </label>
              ))}
            </div>
          </div>
          <label className="flex cursor-pointer items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={isDefault}
              onChange={(e) => setIsDefault(e.target.checked)}
              className="size-4 rounded accent-accent"
            />
            Set as default profile
          </label>
        </div>
        <div className="mt-6 flex justify-end gap-2">
          <Button variant="ghost" onClick={closeModal} disabled={submitting}>
            Cancel
          </Button>
          <Button onClick={handleSubmit} loading={submitting}>
            {editingId ? "Save Changes" : "Create"}
          </Button>
        </div>
      </Modal>
    </Card>
  );
}
