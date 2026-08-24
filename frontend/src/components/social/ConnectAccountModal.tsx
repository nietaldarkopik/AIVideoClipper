"use client";

import { useState } from "react";
import { mutate } from "swr";
import { ExternalLink, Link2, Lock } from "lucide-react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input, Label, Select } from "@/components/ui/Input";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { PLATFORM_LABELS, PLATFORMS, REAL_OAUTH_PLATFORMS, CREDENTIAL_PLATFORMS } from "@/components/social/platforms";
import type { SocialPlatform } from "@/lib/types";

export function ConnectAccountModal({
  open,
  onClose,
  initialPlatform,
}: {
  open: boolean;
  onClose: () => void;
  // Set when opened from a specific account's "Reconnect" button — pre-selects
  // that platform instead of defaulting to the first one in the list.
  initialPlatform?: SocialPlatform;
}) {
  const [platform, setPlatform] = useState(initialPlatform ?? PLATFORMS[0]);
  const [accountName, setAccountName] = useState("");
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [submitting, setSubmitting] = useState(false);

  // Re-select the platform each time the modal opens for a specific account's
  // "Reconnect" — done during render (not an effect) per React's "adjusting
  // state when a prop changes" pattern, so it takes effect before this render
  // paints instead of one tick later.
  const [wasOpen, setWasOpen] = useState(open);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open && initialPlatform && initialPlatform !== platform) {
      setPlatform(initialPlatform);
    }
  }

  const isRealOAuth = REAL_OAUTH_PLATFORMS.has(platform);
  const isCredential = CREDENTIAL_PLATFORMS.has(platform);
  const isMock = !isRealOAuth && !isCredential;

  function resetFields() {
    setAccountName("");
    setUsername("");
    setPassword("");
  }

  async function handleConnectReal() {
    setSubmitting(true);
    try {
      const { authorization_url } = await api.get<{ authorization_url: string }>(
        `/social-accounts/${platform}/authorize`
      );
      window.location.href = authorization_url;
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to start the connection.", "danger");
      setSubmitting(false);
    }
  }

  async function handleConnectCredential() {
    if (!username.trim() || !password) {
      toast("Username and password are required.", "danger");
      return;
    }
    setSubmitting(true);
    try {
      await api.post("/social-accounts/connect", { platform, username, password });
      await mutate("/social-accounts");
      toast(`${PLATFORM_LABELS[platform]} account connected.`, "success");
      resetFields();
      onClose();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to connect account.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleSubmitMock() {
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
      resetFields();
      onClose();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to connect account.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} onClose={onClose} title="Connect Account">
      {isRealOAuth && (
        <p className="mb-4 text-xs text-muted">
          You&apos;ll be taken to {PLATFORM_LABELS[platform]} to sign in and approve access. Nothing is shared with
          this app until you approve it there.
        </p>
      )}
      {isCredential && (
        <p className="mb-4 text-xs text-muted">
          {PLATFORM_LABELS[platform]} has no official publishing API for personal accounts, so this logs in through a
          self-hosted browser automation service instead of OAuth. Your password is stored encrypted (needed to
          re-login if the session expires) — accounts with two-factor authentication aren&apos;t supported yet.
        </p>
      )}
      {isMock && (
        <p className="mb-4 text-xs text-muted">
          No API keys configured yet for {PLATFORM_LABELS[platform]} — this simulates the OAuth connection so you can
          build and test publishing flows. Swap in real platform credentials later without touching the rest of the
          app.
        </p>
      )}
      <div className="space-y-4">
        <div>
          <Label htmlFor="platform">Platform</Label>
          <Select id="platform" value={platform} onChange={(e) => setPlatform(e.target.value as typeof platform)}>
            {PLATFORMS.map((p) => (
              <option key={p} value={p}>
                {PLATFORM_LABELS[p]}
                {REAL_OAUTH_PLATFORMS.has(p) || CREDENTIAL_PLATFORMS.has(p) ? "" : " (mock)"}
              </option>
            ))}
          </Select>
        </div>
        {isCredential && (
          <>
            <div>
              <Label htmlFor="ig_username">Username</Label>
              <Input
                id="ig_username"
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                placeholder="yourhandle"
                autoComplete="off"
              />
            </div>
            <div>
              <Label htmlFor="ig_password">Password</Label>
              <Input
                id="ig_password"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                autoComplete="off"
              />
            </div>
          </>
        )}
        {isMock && (
          <>
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
              <Input
                id="username"
                value={username}
                onChange={(e) => setUsername(e.target.value)}
                placeholder="mybrand"
              />
            </div>
          </>
        )}
      </div>
      <div className="mt-6 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>
          Cancel
        </Button>
        {isRealOAuth && (
          <Button onClick={handleConnectReal} loading={submitting}>
            <ExternalLink className="size-4" />
            Continue to {PLATFORM_LABELS[platform]}
          </Button>
        )}
        {isCredential && (
          <Button onClick={handleConnectCredential} loading={submitting}>
            <Lock className="size-4" />
            Log In &amp; Connect
          </Button>
        )}
        {isMock && (
          <Button onClick={handleSubmitMock} loading={submitting}>
            <Link2 className="size-4" />
            Connect
          </Button>
        )}
      </div>
    </Modal>
  );
}
