"use client";

import { useEffect, useState, Suspense } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { mutate } from "swr";
import { Plus, Share2, Unlink, RefreshCw, LogIn } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { StatusBadge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { ConnectAccountModal } from "@/components/social/ConnectAccountModal";
import { PLATFORM_LABELS, REAL_OAUTH_PLATFORMS } from "@/components/social/platforms";
import { formatRelativeTime } from "@/lib/format";
import { Select } from "@/components/ui/Input";
import type { CoverTemplate, SocialAccount, SocialPlatform } from "@/lib/types";

function SocialAccountsPageInner() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [modalOpen, setModalOpen] = useState(false);
  const [reconnectPlatform, setReconnectPlatform] = useState<SocialPlatform | undefined>(undefined);
  const [reconnectingId, setReconnectingId] = useState<number | null>(null);
  const { data, isLoading } = useApi<{ data: SocialAccount[] }>("/social-accounts");
  const { data: coverTemplatesRes } = useApi<{ data: CoverTemplate[] }>("/cover-templates");

  // Lands here after a real OAuth round trip (SocialAccountController::callback
  // always redirects back with ?connected=<platform> or ?error=<message>).
  useEffect(() => {
    const connected = searchParams.get("connected");
    const error = searchParams.get("error");
    if (!connected && !error) return;

    if (connected) {
      toast(`${PLATFORM_LABELS[connected] ?? connected} account connected.`, "success");
      mutate("/social-accounts");
    } else if (error) {
      toast(error, "danger");
    }

    router.replace("/social-accounts");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams]);

  const grouped = (data?.data ?? []).reduce<Record<string, SocialAccount[]>>((acc, a) => {
    (acc[a.platform] ??= []).push(a);
    return acc;
  }, {});

  async function toggleAutoPublish(account: SocialAccount) {
    try {
      await api.patch(`/social-accounts/${account.id}`, { auto_publish_enabled: !account.auto_publish_enabled });
      await mutate("/social-accounts");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to update.", "danger");
    }
  }

  async function setDefaultCoverTemplate(account: SocialAccount, coverTemplateId: string) {
    try {
      await api.patch(`/social-accounts/${account.id}`, {
        default_cover_template_id: coverTemplateId ? Number(coverTemplateId) : null,
      });
      await mutate("/social-accounts");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to update.", "danger");
    }
  }

  async function handleDisconnect(account: SocialAccount) {
    if (!confirm(`Disconnect ${account.account_name}?`)) return;
    try {
      await api.post(`/social-accounts/${account.id}/disconnect`);
      await mutate("/social-accounts");
      toast("Account disconnected.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to disconnect.", "danger");
    }
  }

  async function handleRefresh(account: SocialAccount) {
    try {
      await api.post(`/social-accounts/${account.id}/refresh`);
      await mutate("/social-accounts");
      toast("Token refreshed.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to refresh.", "danger");
    }
  }

  // A plain token refresh (handleRefresh above) only works if the stored refresh
  // token itself is still valid — once the platform has revoked that too (the
  // common case once status has actually gone to "expired"/"revoked"/"error"),
  // the only way back is a full login. OAuth platforms can jump straight to the
  // consent screen for this account's platform; credential/mock platforms need
  // their form, so those open the modal pre-selected instead.
  async function handleReconnect(account: SocialAccount) {
    if (!REAL_OAUTH_PLATFORMS.has(account.platform)) {
      setReconnectPlatform(account.platform);
      setModalOpen(true);
      return;
    }

    setReconnectingId(account.id);
    try {
      const { authorization_url } = await api.get<{ authorization_url: string }>(
        `/social-accounts/${account.platform}/authorize`
      );
      window.location.href = authorization_url;
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to start reconnecting.", "danger");
      setReconnectingId(null);
    }
  }

  function closeModal() {
    setModalOpen(false);
    setReconnectPlatform(undefined);
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Social Accounts</h1>
          <p className="mt-1 text-sm text-muted">Connect the accounts you publish clips to.</p>
        </div>
        <Button
          onClick={() => {
            setReconnectPlatform(undefined);
            setModalOpen(true);
          }}
        >
          <Plus className="size-4" />
          Connect Account
        </Button>
      </div>

      {isLoading || !data ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-16" />
          ))}
        </div>
      ) : data.data.length === 0 ? (
        <EmptyState
          icon={<Share2 className="size-6" />}
          title="No accounts connected"
          description="Connect TikTok, Instagram, YouTube, Facebook, X, or LinkedIn to publish clips directly."
          action={
            <Button
              size="sm"
              onClick={() => {
                setReconnectPlatform(undefined);
                setModalOpen(true);
              }}
            >
              <Plus className="size-4" />
              Connect Account
            </Button>
          }
        />
      ) : (
        <div className="space-y-6">
          {Object.entries(grouped).map(([platform, accounts]) => (
            <div key={platform}>
              <h2 className="mb-2 text-sm font-semibold">{PLATFORM_LABELS[platform] ?? platform}</h2>
              <Card className="divide-y divide-border-subtle">
                {accounts.map((account) => (
                  <div key={account.id} className="flex items-center gap-4 px-5 py-4">
                    {account.avatar_url ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={account.avatar_url} alt="" className="size-9 rounded-full" />
                    ) : (
                      <div className="flex size-9 items-center justify-center rounded-full bg-surface-elevated text-xs font-semibold">
                        {account.account_name.slice(0, 2).toUpperCase()}
                      </div>
                    )}
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium">{account.account_name}</p>
                      <p className="truncate text-xs text-muted">
                        {account.username && `@${account.username.replace(/^@/, "")} · `}
                        Synced {formatRelativeTime(account.last_synced_at)}
                      </p>
                    </div>
                    <StatusBadge status={account.status} />
                    <Select
                      value={account.default_cover_template_id ?? ""}
                      onChange={(e) => setDefaultCoverTemplate(account, e.target.value)}
                      className="w-auto text-xs"
                      title="Default cover/thumbnail template auto-applied when publishing this clip here"
                    >
                      <option value="">No cover template</option>
                      {coverTemplatesRes?.data.map((t) => (
                        <option key={t.id} value={t.id}>
                          {t.name}
                        </option>
                      ))}
                    </Select>
                    <label className="flex items-center gap-1.5 text-xs text-muted">
                      <input
                        type="checkbox"
                        checked={account.auto_publish_enabled}
                        onChange={() => toggleAutoPublish(account)}
                        className="size-3.5 rounded accent-accent"
                      />
                      Auto-publish
                    </label>
                    {account.status !== "connected" && (
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => handleReconnect(account)}
                        loading={reconnectingId === account.id}
                      >
                        <LogIn className="size-3.5" />
                        Reconnect
                      </Button>
                    )}
                    <button
                      onClick={() => handleRefresh(account)}
                      title="Refresh token"
                      className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
                    >
                      <RefreshCw className="size-3.5" />
                    </button>
                    <button
                      onClick={() => handleDisconnect(account)}
                      title="Disconnect"
                      className="rounded-lg p-1.5 text-muted hover:bg-danger/10 hover:text-danger cursor-pointer"
                    >
                      <Unlink className="size-3.5" />
                    </button>
                  </div>
                ))}
              </Card>
            </div>
          ))}
        </div>
      )}

      <ConnectAccountModal open={modalOpen} onClose={closeModal} initialPlatform={reconnectPlatform} />
    </div>
  );
}

export default function SocialAccountsPage() {
  return (
    <Suspense fallback={null}>
      <SocialAccountsPageInner />
    </Suspense>
  );
}
