"use client";

import { ClipboardEvent, forwardRef, KeyboardEvent, useCallback, useEffect, useImperativeHandle, useRef, useState } from "react";
import { clsx } from "clsx";
import { AlertTriangle, FileSpreadsheet, FileText, FileType, Image as ImageIcon, Loader2, Paperclip, Send, X } from "lucide-react";
import { assistantApi, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { formatBytes } from "@/lib/format";
import type { AssistantAttachment, AssistantConfig } from "@/lib/types";

export interface PendingAttachment {
  localId: string;
  name: string;
  size: number;
  status: "uploading" | "ready" | "error";
  attachment?: AssistantAttachment;
  error?: string;
}

let localCounter = 0;

/**
 * Owns the files attached to the message being written. Files upload the moment they are
 * added (so unreadable ones are flagged before sending), and the chips stay until the
 * message is sent. Lives outside Composer so the whole chat pane can accept a drop.
 */
export function useAttachmentUploader(config: AssistantConfig | undefined) {
  const [items, setItems] = useState<PendingAttachment[]>([]);
  const itemsRef = useRef<PendingAttachment[]>([]);
  // Mirrors the latest items for addFiles(), which must read the current count without
  // being re-created on every change.
  useEffect(() => {
    itemsRef.current = items;
  }, [items]);

  const addFiles = useCallback(
    async (incoming: File[]) => {
      if (incoming.length === 0) return;

      const maxFiles = config?.max_files ?? 8;
      const maxBytes = (config?.max_file_mb ?? 20) * 1024 * 1024;
      const accepted = config?.accepted_formats ?? [];

      const room = maxFiles - itemsRef.current.length;
      if (room <= 0) {
        toast(`Maksimal ${maxFiles} lampiran per pesan.`, "danger");
        return;
      }

      const valid: File[] = [];
      for (const file of incoming) {
        const ext = file.name.includes(".") ? file.name.split(".").pop()!.toLowerCase() : "";
        if (file.size > maxBytes) {
          toast(`"${file.name}" melebihi ${config?.max_file_mb ?? 20} MB.`, "danger");
        } else if (accepted.length > 0 && !accepted.includes(ext)) {
          toast(`Format "${ext || "?"}" belum didukung (${file.name}).`, "danger");
        } else if (valid.length >= room) {
          toast(`Maksimal ${maxFiles} lampiran per pesan.`, "danger");
          break;
        } else {
          valid.push(file);
        }
      }
      if (valid.length === 0) return;

      const pending: PendingAttachment[] = valid.map((f) => ({
        localId: `l${++localCounter}`,
        name: f.name,
        size: f.size,
        status: "uploading",
      }));
      setItems((cur) => [...cur, ...pending]);

      try {
        const res = await assistantApi.uploadAttachments(valid);
        setItems((cur) =>
          cur.map((it) => {
            const idx = pending.findIndex((p) => p.localId === it.localId);
            return idx === -1 ? it : { ...it, status: "ready", attachment: res.data[idx] };
          })
        );
      } catch (e) {
        const message = e instanceof ApiError ? e.message : "Upload gagal.";
        setItems((cur) =>
          cur.map((it) => (pending.some((p) => p.localId === it.localId) ? { ...it, status: "error", error: message } : it))
        );
        toast(message, "danger");
      }
    },
    [config]
  );

  const remove = useCallback((localId: string) => setItems((cur) => cur.filter((it) => it.localId !== localId)), []);
  const clear = useCallback(() => setItems([]), []);

  return {
    items,
    addFiles,
    remove,
    clear,
    uploading: items.some((i) => i.status === "uploading"),
    readyIds: items.filter((i) => i.status === "ready" && i.attachment).map((i) => i.attachment!.id),
  };
}

export type AttachmentUploader = ReturnType<typeof useAttachmentUploader>;

function KindIcon({ kind }: { kind?: AssistantAttachment["kind"] }) {
  const cls = "size-4 shrink-0";
  if (kind === "image") return <ImageIcon className={cls} />;
  if (kind === "spreadsheet") return <FileSpreadsheet className={cls} />;
  if (kind === "document") return <FileType className={cls} />;
  return <FileText className={cls} />;
}

export interface ComposerHandle {
  setText: (text: string) => void;
  openPicker: () => void;
}

interface ComposerProps {
  uploader: AttachmentUploader;
  config: AssistantConfig | undefined;
  /** Why sending is blocked right now; null when the composer is usable. */
  blockedReason: string | null;
  onSend: (content: string, attachmentIds: number[]) => Promise<boolean>;
}

export const Composer = forwardRef<ComposerHandle, ComposerProps>(function Composer(
  { uploader, config, blockedReason, onSend },
  ref
) {
  const [text, setText] = useState("");
  const [sending, setSending] = useState(false);
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const grow = useCallback(() => {
    const el = textareaRef.current;
    if (!el) return;
    el.style.height = "auto";
    el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
  }, []);

  useImperativeHandle(
    ref,
    () => ({
      setText: (value: string) => {
        setText(value);
        // Wait for the value to land before measuring.
        requestAnimationFrame(() => {
          grow();
          textareaRef.current?.focus();
        });
      },
      openPicker: () => fileInputRef.current?.click(),
    }),
    [grow]
  );

  const hasContent = text.trim() !== "" || uploader.readyIds.length > 0;
  const canSend = hasContent && !uploader.uploading && !sending && blockedReason === null;

  async function submit() {
    if (!canSend) return;
    setSending(true);
    try {
      const ok = await onSend(text.trim(), uploader.readyIds);
      if (ok) {
        setText("");
        uploader.clear();
        requestAnimationFrame(grow);
      }
    } finally {
      setSending(false);
    }
  }

  function onKeyDown(e: KeyboardEvent<HTMLTextAreaElement>) {
    // isComposing: don't send while an IME (e.g. predictive/Asian input) is mid-word.
    if (e.key === "Enter" && !e.shiftKey && !e.nativeEvent.isComposing) {
      e.preventDefault();
      void submit();
    }
  }

  function onPaste(e: ClipboardEvent<HTMLTextAreaElement>) {
    const files = Array.from(e.clipboardData.files);
    if (files.length > 0) {
      e.preventDefault();
      void uploader.addFiles(files);
    }
  }

  const accept = (config?.accepted_formats ?? []).map((f) => `.${f}`).join(",");

  return (
    <div className="border-t border-border-subtle bg-surface p-3">
      {uploader.items.length > 0 && (
        <div className="mb-2 flex flex-wrap gap-2">
          {uploader.items.map((it) => {
            const warn = it.status === "ready" && it.attachment && !it.attachment.readable;
            return (
              <div
                key={it.localId}
                title={it.error ?? it.attachment?.note ?? it.name}
                className={clsx(
                  "flex max-w-[16rem] items-center gap-2 rounded-lg border px-2.5 py-1.5 text-xs",
                  it.status === "error" || warn ? "border-warning/40 bg-warning/10" : "border-border-subtle bg-surface-elevated"
                )}
              >
                {it.status === "uploading" ? (
                  <Loader2 className="size-4 shrink-0 animate-spin text-muted" />
                ) : it.status === "error" || warn ? (
                  <AlertTriangle className="size-4 shrink-0 text-warning" />
                ) : it.attachment?.kind === "image" && it.attachment.url ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={it.attachment.url} alt="" className="size-6 shrink-0 rounded object-cover" />
                ) : (
                  <KindIcon kind={it.attachment?.kind} />
                )}
                <div className="min-w-0">
                  <p className="truncate font-medium">{it.name}</p>
                  <p className={clsx("truncate", it.status === "error" || warn ? "text-warning" : "text-muted")}>
                    {it.status === "uploading"
                      ? "Mengunggah…"
                      : it.status === "error"
                        ? it.error
                        : warn
                          ? (it.attachment?.note ?? "Isi file tidak bisa dibaca")
                          : formatBytes(it.size)}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => uploader.remove(it.localId)}
                  aria-label={`Hapus lampiran ${it.name}`}
                  className="shrink-0 cursor-pointer rounded p-0.5 text-muted hover:bg-white/10 hover:text-foreground"
                >
                  <X className="size-3.5" />
                </button>
              </div>
            );
          })}
        </div>
      )}

      <div className={clsx("flex items-end gap-2", blockedReason && "opacity-60")}>
        <input
          ref={fileInputRef}
          type="file"
          multiple
          accept={accept || undefined}
          className="hidden"
          onChange={(e) => {
            void uploader.addFiles(Array.from(e.target.files ?? []));
            e.target.value = "";
          }}
        />
        <button
          type="button"
          onClick={() => fileInputRef.current?.click()}
          disabled={blockedReason !== null}
          aria-label="Lampirkan file"
          title="Lampirkan file (atau seret & lepas, atau tempel gambar)"
          className="shrink-0 cursor-pointer rounded-xl border border-border-subtle p-2.5 text-muted transition-colors hover:bg-white/5 hover:text-foreground disabled:cursor-not-allowed"
        >
          <Paperclip className="size-4" />
        </button>

        <textarea
          ref={textareaRef}
          value={text}
          rows={1}
          disabled={blockedReason !== null}
          placeholder={blockedReason ?? "Tanyakan sesuatu, atau minta asisten menganalisis & mengelola konten Anda…"}
          onChange={(e) => {
            setText(e.target.value);
            grow();
          }}
          onKeyDown={onKeyDown}
          onPaste={onPaste}
          className="max-h-[200px] min-h-[42px] flex-1 resize-none rounded-xl border border-border-subtle bg-background px-3.5 py-2.5 text-sm text-foreground placeholder:text-muted focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent disabled:cursor-not-allowed"
        />

        <button
          type="button"
          onClick={() => void submit()}
          disabled={!canSend}
          aria-label="Kirim pesan"
          className="shrink-0 cursor-pointer rounded-xl bg-accent p-2.5 text-accent-foreground transition-all hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-40"
        >
          {sending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
        </button>
      </div>

      <p className="mt-1.5 px-1 text-[11px] text-muted">
        {blockedReason ??
          `Enter kirim · Shift+Enter baris baru · maks ${config?.max_files ?? 8} file, ${config?.max_file_mb ?? 20} MB per file (gambar, PDF, DOCX, XLSX, CSV, teks)`}
      </p>
    </div>
  );
});
