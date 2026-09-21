"use client";

import { useState } from "react";
import Link from "next/link";
import { mutate } from "swr";
import { ArrowLeft, Inbox, Lightbulb, X } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Select } from "@/components/ui/Input";
import { StatusBadge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { Modal } from "@/components/ui/Modal";
import { Pagination } from "@/components/ui/Pagination";
import { formatRelativeTime } from "@/lib/format";
import type { ContentChannel, FacebookMessage, Paginated, SocialAccount } from "@/lib/types";

export default function FacebookMessagesPage() {
  const [status, setStatus] = useState("new");
  const [accountId, setAccountId] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [convertTarget, setConvertTarget] = useState<FacebookMessage | null>(null);
  const [channelId, setChannelId] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const { data: accountsRes } = useApi<{ data: SocialAccount[] }>("/social-accounts");
  const { data: channelsRes } = useApi<{ data: ContentChannel[] }>("/content-channels");

  const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });
  if (status) params.set("status", status);
  if (accountId) params.set("social_account_id", accountId);

  const { data, isLoading } = useApi<Paginated<FacebookMessage>>(`/facebook-messages?${params.toString()}`);

  const facebookAccounts = (accountsRes?.data ?? []).filter((a) => a.platform === "facebook");

  function openConvert(message: FacebookMessage) {
    setConvertTarget(message);
    setChannelId("");
  }

  async function handleConvert() {
    if (!convertTarget || !channelId) {
      toast("Pilih content channel tujuan dulu.", "danger");
      return;
    }
    setSubmitting(true);
    try {
      await api.post(`/facebook-messages/${convertTarget.id}/convert-to-idea`, {
        content_channel_id: Number(channelId),
      });
      toast("Pesan dijadikan content idea.", "success");
      setConvertTarget(null);
      await mutate((key) => typeof key === "string" && key.startsWith("/facebook-messages"), undefined, {
        revalidate: true,
      });
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Gagal membuat content idea.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  async function handleIgnore(message: FacebookMessage) {
    try {
      await api.post(`/facebook-messages/${message.id}/ignore`);
      await mutate((key) => typeof key === "string" && key.startsWith("/facebook-messages"), undefined, {
        revalidate: true,
      });
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Gagal mengabaikan pesan.", "danger");
    }
  }

  async function handleSyncNow(id: string) {
    try {
      const res = await api.post<{ inserted: number }>(`/social-accounts/${id}/sync-messages`);
      toast(`${res.inserted} pesan baru ditarik.`, "success");
      await mutate((key) => typeof key === "string" && key.startsWith("/facebook-messages"), undefined, {
        revalidate: true,
      });
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Gagal sync pesan.", "danger");
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <Link href="/social-accounts" className="mb-1 flex items-center gap-1 text-xs text-muted hover:text-foreground">
            <ArrowLeft className="size-3.5" />
            Social Accounts
          </Link>
          <h1 className="text-xl font-semibold">Facebook Messages</h1>
          <p className="mt-1 text-sm text-muted">
            Pesan Messenger dari Page yang diaktifkan Sync Messages — jadikan content idea untuk klip.
          </p>
        </div>
      </div>

      <Card className="flex flex-wrap items-center gap-3 p-4">
        <Select value={accountId} onChange={(e) => setAccountId(e.target.value)} className="w-auto">
          <option value="">Semua Page</option>
          {facebookAccounts.map((a) => (
            <option key={a.id} value={a.id}>
              {a.account_name}
            </option>
          ))}
        </Select>
        <Select value={status} onChange={(e) => setStatus(e.target.value)} className="w-auto">
          <option value="">Semua Status</option>
          <option value="new">Baru</option>
          <option value="converted">Sudah jadi Idea</option>
          <option value="ignored">Diabaikan</option>
        </Select>
        {accountId && (
          <Button size="sm" variant="outline" onClick={() => handleSyncNow(accountId)}>
            Sync sekarang
          </Button>
        )}
        {facebookAccounts.every((a) => !a.messenger_sync_enabled) && (
          <p className="text-xs text-muted">
            Belum ada Page dengan Sync Messages aktif — nyalakan dulu di halaman{" "}
            <Link href="/social-accounts" className="underline">
              Social Accounts
            </Link>
            .
          </p>
        )}
      </Card>

      {isLoading || !data ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-20" />
          ))}
        </div>
      ) : data.data.length === 0 ? (
        <EmptyState
          icon={<Inbox className="size-6" />}
          title="Belum ada pesan"
          description="Pesan baru akan muncul di sini setelah Page yang di-sync menerima DM, atau tunggu polling otomatis tiap 15 menit."
        />
      ) : (
        <Card className="divide-y divide-border-subtle">
          {data.data.map((message) => (
            <div key={message.id} className="flex items-start justify-between gap-4 px-5 py-4">
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 text-xs text-muted">
                  <span className="font-medium text-foreground">{message.sender_name ?? "Tanpa nama"}</span>
                  <span>→ {message.page_name}</span>
                  <span>· {formatRelativeTime(message.sent_at)}</span>
                  <StatusBadge status={message.status} />
                </div>
                <p className="mt-1.5 whitespace-pre-wrap text-sm">{message.message}</p>
              </div>
              {message.status === "new" && (
                <div className="flex shrink-0 items-center gap-1.5">
                  <Button size="sm" onClick={() => openConvert(message)}>
                    <Lightbulb className="size-3.5" />
                    Jadikan Idea
                  </Button>
                  <button
                    onClick={() => handleIgnore(message)}
                    title="Abaikan"
                    className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
                  >
                    <X className="size-3.5" />
                  </button>
                </div>
              )}
            </div>
          ))}
          {data.meta && (
            <Pagination
              page={data.meta.current_page}
              lastPage={data.meta.last_page}
              total={data.meta.total}
              perPage={perPage}
              onPageChange={setPage}
              onPerPageChange={(n) => {
                setPerPage(n);
                setPage(1);
              }}
              itemLabel="pesan"
            />
          )}
        </Card>
      )}

      <Modal open={!!convertTarget} onClose={() => setConvertTarget(null)} title="Jadikan Content Idea">
        <div className="space-y-4">
          <p className="rounded-xl bg-surface-elevated px-3 py-2 text-sm text-muted">{convertTarget?.message}</p>
          <div>
            <label className="mb-1 block text-xs font-medium text-muted">Content Channel Tujuan</label>
            <Select value={channelId} onChange={(e) => setChannelId(e.target.value)}>
              <option value="">Pilih channel...</option>
              {(channelsRes?.data ?? []).map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
          </div>
        </div>
        <div className="mt-6 flex justify-end gap-2">
          <Button variant="ghost" onClick={() => setConvertTarget(null)} disabled={submitting}>
            Batal
          </Button>
          <Button onClick={handleConvert} loading={submitting}>
            Buat Idea
          </Button>
        </div>
      </Modal>
    </div>
  );
}
