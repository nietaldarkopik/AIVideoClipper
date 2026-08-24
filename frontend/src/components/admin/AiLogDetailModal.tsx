"use client";

import { Modal } from "@/components/ui/Modal";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { formatRelativeTime } from "@/lib/format";
import type { AiRequestLog } from "@/lib/types";

export function AiLogDetailModal({ log, onClose }: { log: AiRequestLog | null; onClose: () => void }) {
  return (
    <Modal open={!!log} onClose={onClose} title="AI Request Detail" className="max-w-3xl">
      {log && (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone="accent">{log.capability.replace(/_/g, " ")}</Badge>
            <Badge>{log.provider}</Badge>
            {log.model && <Badge tone="muted">{log.model}</Badge>}
            <StatusBadge status={log.status} />
            {log.duration_ms !== null && <Badge tone="muted">{(log.duration_ms / 1000).toFixed(2)}s</Badge>}
            <span className="text-xs text-muted">{formatRelativeTime(log.created_at)}</span>
          </div>

          {(log.project || log.video || log.clip) && (
            <p className="text-xs text-muted">
              {log.project && <>Project #{log.project.id} — {log.project.title} </>}
              {log.video && <>· Video #{log.video.id} </>}
              {log.clip && <>· Clip #{log.clip.id}</>}
            </p>
          )}

          {log.error_message && (
            <div>
              <p className="mb-1 text-xs font-medium text-muted">Error</p>
              <pre className="max-h-40 overflow-auto whitespace-pre-wrap rounded-xl bg-danger/10 p-3 text-xs text-danger">
                {log.error_message}
              </pre>
            </div>
          )}

          <div>
            <p className="mb-1 text-xs font-medium text-muted">Prompt</p>
            <pre className="max-h-64 overflow-auto whitespace-pre-wrap rounded-xl bg-surface-elevated p-3 text-xs">
              {log.prompt || "(empty)"}
            </pre>
          </div>

          <div>
            <p className="mb-1 text-xs font-medium text-muted">Response</p>
            <pre className="max-h-64 overflow-auto whitespace-pre-wrap rounded-xl bg-surface-elevated p-3 text-xs">
              {log.response || "(empty)"}
            </pre>
          </div>
        </div>
      )}
    </Modal>
  );
}
