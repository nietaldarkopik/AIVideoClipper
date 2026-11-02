"use client";

import { clsx } from "clsx";
import { AlertTriangle, FileSpreadsheet, FileText, FileType, Image as ImageIcon, Loader2, Square } from "lucide-react";
import { Markdown } from "@/components/assistant/Markdown";
import { ToolCallChip } from "@/components/assistant/ToolActivity";
import { formatBytes, formatRelativeTime } from "@/lib/format";
import type { AssistantAttachment, AssistantConversation, AssistantMessage } from "@/lib/types";

function AttachmentChip({ a }: { a: AssistantAttachment }) {
  const Icon = a.kind === "image" ? ImageIcon : a.kind === "spreadsheet" ? FileSpreadsheet : a.kind === "document" ? FileType : FileText;

  if (a.kind === "image" && a.url) {
    return (
      <a href={a.url} target="_blank" rel="noopener noreferrer" title={a.name} className="block overflow-hidden rounded-lg border border-white/15">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={a.url} alt={a.name} className="max-h-40 max-w-[12rem] object-cover" />
      </a>
    );
  }

  return (
    <div
      title={a.note ?? a.name}
      className={clsx(
        "flex max-w-[14rem] items-center gap-2 rounded-lg border px-2.5 py-1.5 text-xs",
        a.readable ? "border-white/15 bg-black/20" : "border-warning/50 bg-warning/10"
      )}
    >
      {a.readable ? <Icon className="size-4 shrink-0" /> : <AlertTriangle className="size-4 shrink-0 text-warning" />}
      <div className="min-w-0">
        <p className="truncate font-medium">{a.name}</p>
        <p className="truncate opacity-70">{a.readable ? formatBytes(a.size_bytes) : (a.note ?? "Tidak bisa dibaca")}</p>
      </div>
    </div>
  );
}

function UserBubble({ message }: { message: AssistantMessage }) {
  return (
    <div className="flex justify-end">
      <div className="max-w-[85%] space-y-2 rounded-2xl rounded-br-md bg-accent px-3.5 py-2.5 text-accent-foreground">
        {message.attachments.length > 0 && (
          <div className="flex flex-wrap gap-2">
            {message.attachments.map((a) => (
              <AttachmentChip key={a.id} a={a} />
            ))}
          </div>
        )}
        {message.content && <p className="whitespace-pre-wrap break-words text-sm">{message.content}</p>}
        <p className="text-right text-[10px] opacity-60">{formatRelativeTime(message.created_at)}</p>
      </div>
    </div>
  );
}

/**
 * The conversation as a reader sees it. Tool messages are never drawn on their own: each
 * is attached to the assistant tool call that produced it (matched by tool_call_id), so
 * "what was asked" and "what came back" sit together in one chip.
 */
export function Thread({ conversation, onStop }: { conversation: AssistantConversation; onStop: () => void }) {
  const running = conversation.status === "running";
  const results = new Map(conversation.messages.filter((m) => m.role === "tool").map((m) => [m.tool_call_id, m]));
  const actions = new Map(conversation.actions.map((a) => [a.tool_call_id, a]));

  return (
    <div className="space-y-3">
      {conversation.messages.map((m) => {
        if (m.role === "tool") return null;
        if (m.role === "user") return <UserBubble key={m.id} message={m} />;

        const hasText = !!m.content && m.content.trim() !== "";
        const calls = m.tool_calls ?? [];

        return (
          <div key={m.id} className="space-y-2">
            {hasText && (
              <div className="max-w-[92%] rounded-2xl rounded-bl-md border border-border-subtle bg-surface-elevated px-3.5 py-2.5">
                <Markdown text={m.content!} />
              </div>
            )}
            {calls.length > 0 && (
              <div className="max-w-[92%] space-y-1.5">
                {calls.map((c) => (
                  <ToolCallChip key={c.id} call={c} result={results.get(c.id)} action={actions.get(c.id)} running={running} />
                ))}
              </div>
            )}
          </div>
        );
      })}

      {running && (
        <div className="flex items-center gap-2.5 text-xs text-muted" role="status" aria-live="polite">
          <Loader2 className="size-4 animate-spin" />
          <span>Asisten sedang bekerja…</span>
          <button
            type="button"
            onClick={onStop}
            className="inline-flex cursor-pointer items-center gap-1 rounded-lg border border-border-subtle px-2 py-1 font-medium text-foreground hover:bg-white/5"
          >
            <Square className="size-3" />
            Hentikan
          </button>
        </div>
      )}

      {conversation.status === "failed" && (
        <div className="rounded-xl border border-danger/40 bg-danger/10 p-3 text-sm" role="alert">
          <p className="flex items-center gap-2 font-semibold text-danger">
            <AlertTriangle className="size-4" />
            Asisten gagal menyelesaikan permintaan
          </p>
          {conversation.failure_reason && <p className="mt-1 break-words text-xs text-danger/90">{conversation.failure_reason}</p>}
          <p className="mt-1.5 text-xs text-muted">Coba kirim pesan lagi. Jika terus gagal, periksa konfigurasi provider AI (AI_ASSISTANT_PROVIDER) dan koneksi 9Router.</p>
        </div>
      )}
    </div>
  );
}
