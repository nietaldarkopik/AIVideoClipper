"use client";

import { useState } from "react";
import { clsx } from "clsx";
import { ChevronDown, ChevronRight, Pencil, Send, ShieldAlert, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { toolLabel } from "@/lib/assistantTools";
import type { AssistantAction, AssistantActionRisk } from "@/lib/types";

export const RISK_META: Record<
  AssistantActionRisk,
  { label: string; icon: typeof Pencil; box: string; badge: string }
> = {
  write: {
    label: "Mengubah data",
    icon: Pencil,
    box: "border-border-subtle bg-surface-elevated",
    badge: "bg-accent/15 text-accent-2",
  },
  outbound: {
    label: "Akan memposting ke media sosial",
    icon: Send,
    box: "border-warning/40 bg-warning/5",
    badge: "bg-warning/15 text-warning",
  },
  destructive: {
    label: "Menghapus permanen",
    icon: Trash2,
    box: "border-danger/50 bg-danger/5",
    badge: "bg-danger/15 text-danger",
  },
};

function ApprovalCard({
  action,
  busy,
  confirming,
  onDecide,
  onAskConfirm,
  onCancelConfirm,
}: {
  action: AssistantAction;
  busy: boolean;
  confirming: boolean;
  onDecide: (decision: "approve" | "reject") => void;
  onAskConfirm: () => void;
  onCancelConfirm: () => void;
}) {
  const [showArgs, setShowArgs] = useState(false);
  const meta = RISK_META[action.risk];
  const Icon = meta.icon;
  const destructive = action.risk === "destructive";

  return (
    <div className={clsx("rounded-xl border p-3.5", meta.box)}>
      <div className="mb-2 flex flex-wrap items-center gap-2">
        <span className={clsx("inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium", meta.badge)}>
          <Icon className="size-3.5" />
          {meta.label}
        </span>
        <span className="text-xs font-medium text-muted">{toolLabel(action.tool)}</span>
      </div>

      <p className="break-words text-sm font-medium">{action.summary}</p>

      <button
        type="button"
        onClick={() => setShowArgs((v) => !v)}
        aria-expanded={showArgs}
        className="mt-2 inline-flex cursor-pointer items-center gap-1 text-xs text-muted hover:text-foreground"
      >
        {showArgs ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
        Detail parameter
      </button>
      {showArgs && (
        <pre className="mt-1.5 max-h-48 overflow-auto rounded-lg bg-black/40 p-2.5 text-[11px] leading-relaxed">
          {JSON.stringify(action.arguments, null, 2)}
        </pre>
      )}

      <div className="mt-3 flex flex-wrap items-center gap-2">
        {destructive && confirming ? (
          <>
            <Button size="sm" variant="danger" disabled={busy} onClick={() => onDecide("approve")}>
              <ShieldAlert className="size-3.5" />
              Ya, hapus permanen
            </Button>
            <Button size="sm" variant="ghost" disabled={busy} onClick={onCancelConfirm}>
              Batal
            </Button>
          </>
        ) : (
          <>
            <Button
              size="sm"
              variant={destructive ? "danger" : "primary"}
              disabled={busy}
              onClick={() => (destructive ? onAskConfirm() : onDecide("approve"))}
            >
              Setujui
            </Button>
            <Button size="sm" variant="outline" disabled={busy} onClick={() => onDecide("reject")}>
              Tolak
            </Button>
          </>
        )}
      </div>
    </div>
  );
}

/**
 * Everything the assistant is waiting on the user for. The backend only resumes once every
 * pending action has been decided, so decisions are sent together via `onDecide` — a map of
 * action id -> decision — whether it's one card or "Setujui semua".
 */
export function ApprovalPanel({
  actions,
  busy,
  onDecide,
}: {
  actions: AssistantAction[];
  busy: boolean;
  onDecide: (decisions: Record<number, "approve" | "reject">) => void;
}) {
  const [confirmId, setConfirmId] = useState<number | "all" | null>(null);

  if (actions.length === 0) return null;

  const hasDestructive = actions.some((a) => a.risk === "destructive");
  // Card-level decisions leave the remaining cards pending; the backend resumes only once all are decided.
  const decideOne = (id: number, decision: "approve" | "reject") => {
    setConfirmId(null);
    onDecide({ [id]: decision });
  };
  const decideAll = (decision: "approve" | "reject") => {
    setConfirmId(null);
    onDecide(Object.fromEntries(actions.map((a) => [a.id, decision])));
  };

  return (
    <div className="space-y-2.5 rounded-2xl border border-warning/30 bg-warning/5 p-3" role="region" aria-label="Menunggu persetujuan">
      <p className="flex items-center gap-2 text-sm font-semibold">
        <ShieldAlert className="size-4 text-warning" />
        Menunggu persetujuan Anda ({actions.length})
      </p>
      <p className="text-xs text-muted">Asisten tidak akan menjalankan tindakan ini sebelum Anda menyetujuinya.</p>

      {actions.map((a) => (
        <ApprovalCard
          key={a.id}
          action={a}
          busy={busy}
          confirming={confirmId === a.id}
          onDecide={(d) => decideOne(a.id, d)}
          onAskConfirm={() => setConfirmId(a.id)}
          onCancelConfirm={() => setConfirmId(null)}
        />
      ))}

      {actions.length > 1 && (
        <div className="flex flex-wrap items-center gap-2 border-t border-border-subtle pt-2.5">
          {hasDestructive && confirmId === "all" ? (
            <>
              <Button size="sm" variant="danger" disabled={busy} onClick={() => decideAll("approve")}>
                Ya, setujui semua termasuk penghapusan
              </Button>
              <Button size="sm" variant="ghost" disabled={busy} onClick={() => setConfirmId(null)}>
                Batal
              </Button>
            </>
          ) : (
            <>
              <Button
                size="sm"
                variant={hasDestructive ? "danger" : "primary"}
                disabled={busy}
                onClick={() => (hasDestructive ? setConfirmId("all") : decideAll("approve"))}
              >
                Setujui semua
              </Button>
              <Button size="sm" variant="outline" disabled={busy} onClick={() => decideAll("reject")}>
                Tolak semua
              </Button>
            </>
          )}
        </div>
      )}
    </div>
  );
}
