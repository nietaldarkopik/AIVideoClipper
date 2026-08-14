"use client";

import { useToastStore } from "@/store/toast";
import { clsx } from "clsx";
import { CheckCircle2, XCircle, Info, X } from "lucide-react";

export function Toaster() {
  const { toasts, dismiss } = useToastStore();

  return (
    <div className="fixed bottom-4 right-4 z-[100] flex flex-col gap-2">
      {toasts.map((t) => (
        <div
          key={t.id}
          className={clsx(
            "flex items-center gap-2 rounded-xl border border-border-subtle bg-surface-elevated px-4 py-3 text-sm shadow-xl min-w-[260px]",
            t.tone === "success" && "border-success/30",
            t.tone === "danger" && "border-danger/30"
          )}
        >
          {t.tone === "success" && <CheckCircle2 className="size-4 shrink-0 text-success" />}
          {t.tone === "danger" && <XCircle className="size-4 shrink-0 text-danger" />}
          {t.tone === "default" && <Info className="size-4 shrink-0 text-accent-2" />}
          <span className="flex-1">{t.message}</span>
          <button onClick={() => dismiss(t.id)} className="text-muted hover:text-foreground cursor-pointer">
            <X className="size-3.5" />
          </button>
        </div>
      ))}
    </div>
  );
}
