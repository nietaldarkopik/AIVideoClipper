"use client";

import { Suspense, useCallback, useEffect, useRef, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { mutate } from "swr";
import { clsx } from "clsx";
import { AlertTriangle, BarChart3, CalendarClock, FileSpreadsheet, ListChecks, Lightbulb, Menu, ShieldCheck, Sparkles, Upload, X } from "lucide-react";
import { useAssistantConfig, useAssistantConversation, useAssistantConversations } from "@/lib/hooks";
import { assistantApi, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Skeleton } from "@/components/ui/Skeleton";
import { ConversationList } from "@/components/assistant/ConversationList";
import { Thread } from "@/components/assistant/Thread";
import { ApprovalPanel } from "@/components/assistant/ApprovalPanel";
import { Composer, ComposerHandle, useAttachmentUploader } from "@/components/assistant/Composer";
import type { AssistantConversation } from "@/lib/types";

const LIST_KEY = "/assistant/conversations?per_page=50";

const EXAMPLES = [
  { icon: BarChart3, text: "Analisis performa posting 30 hari terakhir" },
  { icon: ListChecks, text: "Clip mana yang belum dipublish?" },
  { icon: CalendarClock, text: "Jadwalkan 3 clip terbaru ke akun YouTube besok pagi" },
  { icon: Lightbulb, text: "Buat ide konten dari tren hari ini" },
  { icon: ShieldCheck, text: "Cek akun sosmed yang tokennya bermasalah" },
  { icon: FileSpreadsheet, text: "Analisis file CSV ini", attach: true },
];

function EmptyState({ configured, onPick }: { configured: boolean; onPick: (text: string, attach?: boolean) => void }) {
  return (
    <div className="mx-auto flex max-w-2xl flex-col items-center px-2 py-8 text-center">
      <div className="mb-4 flex size-12 items-center justify-center rounded-2xl bg-accent/15 text-accent-2">
        <Sparkles className="size-6" />
      </div>
      <h2 className="text-lg font-semibold">Asisten AI</h2>
      <p className="mt-1 max-w-md text-sm text-muted">
        Tanyakan, analisis, atau minta tindakan: performa posting, clip, jadwal, ide konten, akun sosmed. Lampirkan gambar, PDF, spreadsheet, atau teks untuk dianalisis.
        Setiap perubahan, posting, atau penghapusan selalu menunggu persetujuan Anda.
      </p>

      {!configured && (
        <div className="mt-4 flex items-start gap-2 rounded-xl border border-warning/40 bg-warning/10 p-3 text-left text-xs" role="alert">
          <AlertTriangle className="mt-0.5 size-4 shrink-0 text-warning" />
          <p>
            Provider AI asisten masih <strong>mock</strong>, jadi jawabannya hanya placeholder. Set <code className="rounded bg-white/10 px-1">AI_ASSISTANT_PROVIDER=nine_router</code> di
            file <code className="rounded bg-white/10 px-1">.env</code> backend lalu restart queue worker.
          </p>
        </div>
      )}

      <div className="mt-6 grid w-full gap-2 sm:grid-cols-2">
        {EXAMPLES.map((ex) => (
          <button
            key={ex.text}
            type="button"
            onClick={() => onPick(ex.text, ex.attach)}
            className="flex cursor-pointer items-center gap-2.5 rounded-xl border border-border-subtle bg-surface-elevated px-3.5 py-3 text-left text-sm transition-colors hover:border-accent/50 hover:bg-white/5"
          >
            <ex.icon className="size-4 shrink-0 text-accent-2" />
            <span>{ex.text}</span>
          </button>
        ))}
      </div>
    </div>
  );
}

function AssistantView() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const selectedId = searchParams.get("c") ? Number(searchParams.get("c")) : null;

  const { data: config } = useAssistantConfig();
  const { data: listRes, isLoading: listLoading } = useAssistantConversations();
  const { data: convRes, isLoading: convLoading, error: convError } = useAssistantConversation(selectedId);
  const conversation = convRes?.data;

  const uploader = useAttachmentUploader(config);
  const composerRef = useRef<ComposerHandle>(null);
  const scrollRef = useRef<HTMLDivElement>(null);
  const stickToBottom = useRef(true);

  const [drawerOpen, setDrawerOpen] = useState(false);
  const [deciding, setDeciding] = useState(false);
  const [dragging, setDragging] = useState(false);
  const dragDepth = useRef(0);

  const status = conversation?.status;
  const pending = status === "awaiting_approval" ? (conversation?.actions ?? []).filter((a) => a.status === "pending") : [];

  const blockedReason =
    status === "running"
      ? "Asisten sedang bekerja… tunggu atau tekan Hentikan."
      : status === "awaiting_approval"
        ? "Setujui atau tolak tindakan di atas untuk melanjutkan."
        : null;

  const writeConversation = useCallback((conv: AssistantConversation) => {
    // Seed the cache with the response so the UI reflects it now instead of after the next poll.
    void mutate(`/assistant/conversations/${conv.id}`, { data: conv }, { revalidate: false });
    void mutate(LIST_KEY);
  }, []);

  // A finished run changes the list (ordering, status dots); refresh it on status changes.
  useEffect(() => {
    if (status) void mutate(LIST_KEY);
  }, [status]);

  // Keep the newest content in view while the user is at the bottom; never yank them
  // back down if they scrolled up to read earlier messages.
  const messageCount = conversation?.messages.length ?? 0;
  useEffect(() => {
    const el = scrollRef.current;
    if (el && stickToBottom.current) el.scrollTop = el.scrollHeight;
  }, [messageCount, status, pending.length]);

  useEffect(() => {
    stickToBottom.current = true;
    const el = scrollRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [selectedId]);

  function select(id: number | null) {
    setDrawerOpen(false);
    router.push(id ? `/assistant?c=${id}` : "/assistant");
  }

  async function send(content: string, attachmentIds: number[]): Promise<boolean> {
    try {
      const res = await assistantApi.sendMessage({ conversation_id: selectedId, content, attachment_ids: attachmentIds });
      stickToBottom.current = true;
      writeConversation(res.data);
      if (res.data.id !== selectedId) router.replace(`/assistant?c=${res.data.id}`);
      return true;
    } catch (e) {
      toast(e instanceof ApiError ? e.message : "Gagal mengirim pesan.", "danger");
      // The conversation may have changed state under us (e.g. a run just started elsewhere).
      if (selectedId) void mutate(`/assistant/conversations/${selectedId}`);
      return false;
    }
  }

  async function decide(decisions: Record<number, "approve" | "reject">) {
    if (!selectedId) return;
    setDeciding(true);
    try {
      const res = await assistantApi.decide(selectedId, decisions);
      writeConversation(res.data);
    } catch (e) {
      toast(e instanceof ApiError ? e.message : "Gagal menyimpan keputusan.", "danger");
      void mutate(`/assistant/conversations/${selectedId}`);
    } finally {
      setDeciding(false);
    }
  }

  async function stop() {
    if (!selectedId) return;
    try {
      const res = await assistantApi.stop(selectedId);
      writeConversation(res.data);
    } catch (e) {
      toast(e instanceof ApiError ? e.message : "Gagal menghentikan.", "danger");
    }
  }

  async function rename(id: number, title: string) {
    try {
      await assistantApi.rename(id, title);
      void mutate(LIST_KEY);
      void mutate(`/assistant/conversations/${id}`);
    } catch (e) {
      toast(e instanceof ApiError ? e.message : "Gagal mengganti nama.", "danger");
    }
  }

  async function remove(id: number) {
    try {
      await assistantApi.remove(id);
      toast("Percakapan dihapus.", "success");
      void mutate(LIST_KEY);
      if (id === selectedId) router.replace("/assistant");
    } catch (e) {
      toast(e instanceof ApiError ? e.message : "Gagal menghapus.", "danger");
    }
  }

  const conversations = listRes?.data ?? [];
  const list = (
    <ConversationList
      conversations={conversations}
      loading={listLoading}
      activeId={selectedId}
      onSelect={select}
      onNew={() => select(null)}
      onRename={rename}
      onDelete={remove}
    />
  );

  const showEmpty = !selectedId || (conversation && conversation.messages.length === 0);

  return (
    <div className="flex h-[calc(100dvh-7.5rem)] min-h-[520px] overflow-hidden rounded-2xl border border-border-subtle bg-surface md:h-[calc(100dvh-6rem)]">
      <aside className="hidden w-64 shrink-0 border-r border-border-subtle md:block">{list}</aside>

      {drawerOpen && (
        <div className="fixed inset-0 z-50 md:hidden">
          <div className="absolute inset-0 bg-black/60" onClick={() => setDrawerOpen(false)} aria-hidden="true" />
          <div role="dialog" aria-modal="true" aria-label="Percakapan" className="absolute inset-y-0 left-0 flex w-72 max-w-[85%] flex-col border-r border-border-subtle bg-surface">
            <div className="flex items-center justify-between px-4 pt-4">
              <p className="text-sm font-semibold">Percakapan</p>
              <button type="button" onClick={() => setDrawerOpen(false)} aria-label="Tutup" className="cursor-pointer rounded-lg p-1.5 text-muted hover:bg-white/5">
                <X className="size-4" />
              </button>
            </div>
            <div className="min-h-0 flex-1">{list}</div>
          </div>
        </div>
      )}

      <section
        className="relative flex min-w-0 flex-1 flex-col"
        onDragEnter={(e) => {
          if (blockedReason || !e.dataTransfer.types.includes("Files")) return;
          dragDepth.current++;
          setDragging(true);
        }}
        onDragOver={(e) => {
          if (e.dataTransfer.types.includes("Files")) e.preventDefault();
        }}
        onDragLeave={() => {
          dragDepth.current = Math.max(0, dragDepth.current - 1);
          if (dragDepth.current === 0) setDragging(false);
        }}
        onDrop={(e) => {
          e.preventDefault();
          dragDepth.current = 0;
          setDragging(false);
          if (!blockedReason) void uploader.addFiles(Array.from(e.dataTransfer.files));
        }}
      >
        <header className="flex items-center gap-2 border-b border-border-subtle px-3 py-2.5 md:px-4">
          <button
            type="button"
            onClick={() => setDrawerOpen(true)}
            aria-label="Buka daftar percakapan"
            className="cursor-pointer rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground md:hidden"
          >
            <Menu className="size-5" />
          </button>
          <Sparkles className="hidden size-4 text-accent-2 sm:block" />
          <h1 className="min-w-0 flex-1 truncate text-sm font-semibold">{conversation?.title || "Asisten AI"}</h1>
          {config && (
            <span className="hidden shrink-0 text-[11px] text-muted sm:inline">
              {config.tool_count} tools · {config.provider}
            </span>
          )}
        </header>

        <div
          ref={scrollRef}
          onScroll={(e) => {
            const el = e.currentTarget;
            stickToBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
          }}
          className="min-h-0 flex-1 space-y-3 overflow-y-auto p-3 md:p-4"
        >
          {selectedId && convLoading && !conversation && (
            <div className="space-y-3">
              <Skeleton className="ml-auto h-12 w-2/3" />
              <Skeleton className="h-20 w-3/4" />
            </div>
          )}

          {selectedId && convError && !conversation && (
            <p className="rounded-xl border border-danger/40 bg-danger/10 p-3 text-sm text-danger">
              Percakapan tidak ditemukan atau gagal dimuat.
            </p>
          )}

          {showEmpty && (
            <EmptyState
              configured={config?.configured ?? true}
              onPick={(text, attach) => {
                composerRef.current?.setText(text);
                if (attach) composerRef.current?.openPicker();
              }}
            />
          )}

          {conversation && conversation.messages.length > 0 && <Thread conversation={conversation} onStop={stop} />}

          <ApprovalPanel actions={pending} busy={deciding} onDecide={decide} />
        </div>

        {dragging && (
          <div className="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-2xl border-2 border-dashed border-accent bg-background/80 backdrop-blur-sm">
            <p className={clsx("flex items-center gap-2 text-sm font-medium text-accent-2")}>
              <Upload className="size-5" />
              Lepas file di sini untuk dilampirkan
            </p>
          </div>
        )}

        <Composer ref={composerRef} uploader={uploader} config={config} blockedReason={blockedReason} onSend={send} />
      </section>
    </div>
  );
}

export default function AssistantPage() {
  // useSearchParams needs a Suspense boundary under the app router's static rendering.
  return (
    <Suspense fallback={<Skeleton className="h-[60vh] w-full" />}>
      <AssistantView />
    </Suspense>
  );
}
