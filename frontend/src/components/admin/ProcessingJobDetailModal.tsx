"use client";

import { Modal } from "@/components/ui/Modal";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { formatRelativeTime, statusLabel } from "@/lib/format";
import type { ProcessingJob } from "@/lib/types";

export function ProcessingJobDetailModal({ job, onClose }: { job: ProcessingJob | null; onClose: () => void }) {
  return (
    <Modal open={!!job} onClose={onClose} title="Processing Job Detail" className="max-w-3xl">
      {job && (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone="accent">{statusLabel(job.type)}</Badge>
            <StatusBadge status={job.status} />
            <span className="text-xs text-muted">{job.progress}%</span>
            {job.started_at && <span className="text-xs text-muted">{formatRelativeTime(job.started_at)}</span>}
          </div>

          <p className="text-xs text-muted">
            Project #{job.project_id}
            {job.video_id && <> · Video #{job.video_id}</>}
            {job.clip_id && <> · Clip #{job.clip_id}</>}
          </p>

          {job.message && (
            <div>
              <p className="mb-1 text-xs font-medium text-muted">Message</p>
              <p className="rounded-xl bg-surface-elevated p-3 text-xs">{job.message}</p>
            </div>
          )}

          {job.error && (
            <div>
              <p className="mb-1 text-xs font-medium text-muted">Error</p>
              <pre className="max-h-40 overflow-auto whitespace-pre-wrap rounded-xl bg-danger/10 p-3 text-xs text-danger">
                {job.error}
              </pre>
            </div>
          )}

          <div>
            <p className="mb-1 text-xs font-medium text-muted">Request</p>
            <pre className="max-h-64 overflow-auto whitespace-pre-wrap rounded-xl bg-surface-elevated p-3 text-xs">
              {job.request_payload || "(no request recorded)"}
            </pre>
          </div>

          <div>
            <p className="mb-1 text-xs font-medium text-muted">Response</p>
            <pre className="max-h-64 overflow-auto whitespace-pre-wrap rounded-xl bg-surface-elevated p-3 text-xs">
              {job.response_payload || "(no response recorded)"}
            </pre>
          </div>
        </div>
      )}
    </Modal>
  );
}
