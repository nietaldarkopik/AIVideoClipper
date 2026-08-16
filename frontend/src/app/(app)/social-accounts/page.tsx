"use client";

import { useEffect, useState, Suspense } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { mutate } from "swr";
import { Plus, Share2, Unlink, RefreshCw } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { StatusBadge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { ConnectAccountModal } from "@/components/social/ConnectAccountModal";
import { PLATFORM_LABELS } from "@/components/social/platforms";
import { formatRelativeTime } from "@/lib/format";
import type { SocialAccount } from "@/lib/types";

function SocialAccountsPageInner() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [modalOpen, setModalOpen] = useState(false);
  const { data, isLoading } = useApi<{ data: SocialAccount[] }>("/social-accounts");

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

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Social Accounts</h1>
          <p className="mt-1 text-sm text-muted">Connect the accounts you publish clips to.</p>
        </div>
        <Button onClick={() => setModalOpen(true)}>
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
            <Button size="sm" onClick={() => setModalOpen(true)}>
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
                    <label className="flex items-center gap-1.5 text-xs text-muted">
                      <input
                        type="checkbox"
                        checked={account.auto_publish_enabled}
                        onChange={() => toggleAutoPublish(account)}
                        className="size-3.5 rounded accent-accent"
                      />
                      Auto-publish
                    </label>
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

      <ConnectAccountModal open={modalOpen} onClose={() => setModalOpen(false)} />
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
