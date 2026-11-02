"use client";

import { useState } from "react";
import { clsx } from "clsx";
import { Check, MessageSquarePlus, Pencil, Trash2, X } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { formatRelativeTime } from "@/lib/format";
import type { AssistantConversationSummary } from "@/lib/types";

export function ConversationList({
  conversations,
  loading,
  activeId,
  onSelect,
  onNew,
  onRename,
  onDelete,
}: {
  conversations: AssistantConversationSummary[];
  loading: boolean;
  activeId: number | null;
  onSelect: (id: number) => void;
  onNew: () => void;
  onRename: (id: number, title: string) => Promise<void>;
  onDelete: (id: number) => Promise<void>;
}) {
  const [editingId, setEditingId] = useState<number | null>(null);
  const [draft, setDraft] = useState("");
  const [confirmDeleteId, setConfirmDeleteId] = useState<number | null>(null);

  async function commitRename(id: number, original: string | null) {
    const title = draft.trim();
    setEditingId(null);
    if (title && title !== original) await onRename(id, title);
  }

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="p-3">
        <Button onClick={onNew} className="w-full" variant="secondary">
          <MessageSquarePlus className="size-4" />
          Percakapan baru
        </Button>
      </div>

      <div className="min-h-0 flex-1 space-y-0.5 overflow-y-auto px-2 pb-3">
        {loading && <p className="px-3 py-2 text-xs text-muted">Memuat…</p>}
        {!loading && conversations.length === 0 && <p className="px-3 py-2 text-xs text-muted">Belum ada percakapan.</p>}

        {conversations.map((c) => {
          const active = c.id === activeId;
          const title = c.title || "Percakapan baru";

          if (editingId === c.id) {
            return (
              <div key={c.id} className="flex items-center gap-1 rounded-xl bg-white/5 p-1.5">
                <input
                  autoFocus
                  value={draft}
                  maxLength={255}
                  onChange={(e) => setDraft(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === "Enter") void commitRename(c.id, c.title);
                    if (e.key === "Escape") setEditingId(null);
                  }}
                  aria-label="Nama percakapan"
                  className="min-w-0 flex-1 rounded-lg border border-border-subtle bg-background px-2 py-1 text-sm focus:border-accent focus:outline-none"
                />
                <button type="button" aria-label="Simpan nama" onClick={() => void commitRename(c.id, c.title)} className="cursor-pointer rounded p-1 text-success hover:bg-white/10">
                  <Check className="size-4" />
                </button>
                <button type="button" aria-label="Batal" onClick={() => setEditingId(null)} className="cursor-pointer rounded p-1 text-muted hover:bg-white/10">
                  <X className="size-4" />
                </button>
              </div>
            );
          }

          if (confirmDeleteId === c.id) {
            return (
              <div key={c.id} className="rounded-xl border border-danger/40 bg-danger/10 p-2.5 text-xs">
                <p className="mb-2 font-medium">Hapus &ldquo;{title}&rdquo;?</p>
                <div className="flex gap-1.5">
                  <Button
                    size="sm"
                    variant="danger"
                    onClick={async () => {
                      setConfirmDeleteId(null);
                      await onDelete(c.id);
                    }}
                  >
                    Hapus
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => setConfirmDeleteId(null)}>
                    Batal
                  </Button>
                </div>
              </div>
            );
          }

          return (
            <div
              key={c.id}
              className={clsx(
                "group flex items-center gap-1 rounded-xl pr-1 transition-colors",
                active ? "bg-accent/15" : "hover:bg-white/5"
              )}
            >
              <button
                type="button"
                onClick={() => onSelect(c.id)}
                aria-current={active ? "true" : undefined}
                className="min-w-0 flex-1 cursor-pointer px-3 py-2 text-left"
              >
                <p className={clsx("flex items-center gap-1.5 truncate text-sm font-medium", active && "text-accent-2")}>
                  {c.status === "running" && <span className="size-2 shrink-0 animate-pulse rounded-full bg-accent" title="Sedang diproses" />}
                  {c.status === "awaiting_approval" && <span className="size-2 shrink-0 rounded-full bg-warning" title="Menunggu persetujuan" />}
                  {c.status === "failed" && <span className="size-2 shrink-0 rounded-full bg-danger" title="Gagal" />}
                  <span className="truncate">{title}</span>
                </p>
                <p className="text-[11px] text-muted">{formatRelativeTime(c.last_message_at ?? c.created_at)}</p>
              </button>
              <div className="flex shrink-0 opacity-100 md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100">
                <button
                  type="button"
                  aria-label={`Ganti nama ${title}`}
                  onClick={() => {
                    setDraft(c.title ?? "");
                    setEditingId(c.id);
                  }}
                  className="cursor-pointer rounded p-1.5 text-muted hover:bg-white/10 hover:text-foreground"
                >
                  <Pencil className="size-3.5" />
                </button>
                <button
                  type="button"
                  aria-label={`Hapus ${title}`}
                  onClick={() => setConfirmDeleteId(c.id)}
                  className="cursor-pointer rounded p-1.5 text-muted hover:bg-white/10 hover:text-danger"
                >
                  <Trash2 className="size-3.5" />
                </button>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
