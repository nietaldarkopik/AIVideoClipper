"use client";

import { useState } from "react";
import { clsx } from "clsx";
import { AlertCircle, Check, ChevronDown, ChevronRight, Clock, Loader2, Wrench, X } from "lucide-react";
import { RISK_META } from "@/components/assistant/ApprovalPanel";
import { parseToolResult, toolLabel } from "@/lib/assistantTools";
import type { AssistantAction, AssistantMessage, AssistantToolCall } from "@/lib/types";

const ACTION_PILL: Record<
  AssistantAction["status"],
  { label: string; cls: string; icon: typeof Check }
> = {
  pending: { label: "Menunggu persetujuan", cls: "bg-warning/15 text-warning", icon: Clock },
  approved: { label: "Disetujui", cls: "bg-accent/15 text-accent-2", icon: Loader2 },
  rejected: { label: "Ditolak", cls: "bg-white/10 text-muted", icon: X },
  executed: { label: "Dijalankan", cls: "bg-success/15 text-success", icon: Check },
  failed: { label: "Gagal", cls: "bg-danger/15 text-danger", icon: AlertCircle },
};

/**
 * One tool call as a compact chip. Read-only calls are collapsed by default; calls that
 * needed approval also carry their decision/outcome pill so the thread stays an honest
 * record of what actually ran.
 */
export function ToolCallChip({
  call,
  result,
  action,
  running,
}: {
  call: AssistantToolCall;
  result: AssistantMessage | undefined;
  action: AssistantAction | undefined;
  running: boolean;
}) {
  const [open, setOpen] = useState(false);
  const parsed = parseToolResult(result?.content);
  const waiting = !result && !action && running;
  const failed = parsed.error || action?.status === "failed";

  const pill = action ? ACTION_PILL[action.status] : null;
  const PillIcon = pill?.icon;
  const risk = action ? RISK_META[action.risk] : null;

  return (
    <div
      className={clsx(
        "rounded-lg border text-xs",
        failed ? "border-danger/40 bg-danger/5" : risk && action?.risk !== "write" ? risk.box : "border-border-subtle bg-white/[0.03]"
      )}
    >
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        className="flex w-full cursor-pointer items-center gap-2 px-2.5 py-1.5 text-left"
      >
        {waiting ? (
          <Loader2 className="size-3.5 shrink-0 animate-spin text-muted" />
        ) : failed ? (
          <AlertCircle className="size-3.5 shrink-0 text-danger" />
        ) : (
          <Wrench className="size-3.5 shrink-0 text-muted" />
        )}
        <span className="min-w-0 flex-1 truncate font-medium">
          {action ? action.summary : `${toolLabel(call.name)}${waiting ? "…" : ""}`}
        </span>
        {pill && PillIcon && (
          <span className={clsx("inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium", pill.cls)}>
            <PillIcon className={clsx("size-3", action?.status === "approved" && "animate-spin")} />
            {pill.label}
          </span>
        )}
        {open ? <ChevronDown className="size-3.5 shrink-0 text-muted" /> : <ChevronRight className="size-3.5 shrink-0 text-muted" />}
      </button>

      {open && (
        <div className="space-y-2 border-t border-border-subtle px-2.5 py-2">
          <p className="text-muted">{toolLabel(call.name)}</p>
          <div>
            <p className="mb-1 text-[11px] font-medium text-muted">Parameter</p>
            <pre className="max-h-40 overflow-auto rounded bg-black/40 p-2 text-[11px] leading-relaxed">
              {JSON.stringify(call.arguments, null, 2)}
            </pre>
          </div>
          {(result || action?.result) && (
            <div>
              <p className="mb-1 text-[11px] font-medium text-muted">Hasil</p>
              <pre
                className={clsx(
                  "max-h-40 overflow-auto whitespace-pre-wrap break-words rounded p-2 text-[11px] leading-relaxed",
                  failed ? "bg-danger/10 text-danger" : "bg-black/40"
                )}
              >
                {(parsed.message ?? result?.content ?? action?.result?.content ?? action?.result?.message ?? "").slice(0, 700)}
              </pre>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
