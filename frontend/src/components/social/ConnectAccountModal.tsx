"use client";

import { useState } from "react";
import { mutate } from "swr";
import { Link2 } from "lucide-react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input, Label, Select } from "@/components/ui/Input";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { PLATFORM_LABELS, PLATFORMS } from "@/components/social/platforms";

export function ConnectAccountModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [platform, setPlatform] = useState(PLATFORMS[0]);
  const [accountName, setAccountName] = useState("");
  const [username, setUsername] = useState("");
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    if (!accountName.trim()) {
      toast("Account name is required.", "danger");
      return;
    }
    setSubmitting(true);
    try {
      await api.post("/social-accounts/connect", {
        platform,
        account_name: accountName,
        username: username || undefined,
      });
      await mutate("/social-accounts");
      toast(`${PLATFORM_LABELS[platform]} account connected.`, "success");
      setAccountName("");
      setUsername("");
      onClose();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to connect account.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} onClose={onClose} title="Connect Account">
      <p className="mb-4 text-xs text-muted">
        No API keys configured yet — this simulates the OAuth connection so you can build and test publishing flows.
        Swap in real platform credentials later without touching the rest of the app.
      </p>
      <div className="space-y-4">
        <div>
          <Label htmlFor="platform">Platform</Label>
          <Select id="platform" value={platform} onChange={(e) => setPlatform(e.target.value as typeof platform)}>
            {PLATFORMS.map((p) => (
              <option key={p} value={p}>
                {PLATFORM_LABELS[p]}
              </option>
            ))}
          </Select>
        </div>
        <div>
          <Label htmlFor="account_name">Account / Page Name</Label>
          <Input
            id="account_name"
            value={accountName}
            onChange={(e) => setAccountName(e.target.value)}
            placeholder="My Brand"
          />
        </div>
        <div>
          <Label htmlFor="username">Handle (optional)</Label>
          <Input id="username" value={username} onChange={(e) => setUsername(e.target.value)} placeholder="mybrand" />
        </div>
      </div>
      <div className="mt-6 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>
          Cancel
        </Button>
        <Button onClick={handleSubmit} loading={submitting}>
          <Link2 className="size-4" />
          Connect
        </Button>
      </div>
    </Modal>
  );
}
