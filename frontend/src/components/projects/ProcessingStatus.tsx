"use client";

import { useState } from "react";
import { Loader2, Square } from "lucide-react";
import { mutate } from "swr";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { ProgressBar } from "@/components/ui/ProgressBar";
import type { ProcessingJob } from "@/lib/types";

const STEP_LABELS: Record<string, string> = {
  import_video: "Importing video",
  analyze: "Analyzing video",
  render_clip: "Rendering clip",
};

export function ProcessingStatus({ projectId, active }: { projectId: number; active: boolean }) {
  const jobsKey = `/projects/${projectId}/processing-jobs`;
  const { data } = useApi<{ data: ProcessingJob[] }>(jobsKey, { refreshInterval: active ? 1500 : 0 });
  const [stopping, setStopping] = useState(false);

  const job = data?.data.find((j) => j.status === "running" || j.status === "queued");
  if (!job) return null;

  const jobId = job.id;

  async function handleStop() {
    if (!confirm("Stop this job? It can't be resumed from where it left off — you'll need to retry from the start.")) {
      return;
    }
    setStopping(true);
    try {
      await api.post(`/projects/${projectId}/processing-jobs/${jobId}/cancel`);
      await mutate(jobsKey);
      await mutate(`/projects/${projectId}`);
      toast("Job stopped.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to stop job.", "danger");
    } finally {
      setStopping(false);
    }
  }

  return (
    <div className="rounded-2xl border border-accent/30 bg-accent/5 p-5">
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-2 text-sm font-medium text-accent-2">
          <Loader2 className="size-4 animate-spin" />
          {STEP_LABELS[job.type] ?? "Processing"}...
        </div>
        <button
          onClick={handleStop}
          disabled={stopping}
          className="flex items-center gap-1 rounded-lg px-2 py-1 text-xs text-muted hover:bg-danger/10 hover:text-danger disabled:opacity-50 cursor-pointer"
        >
          <Square className="size-3" />
          Stop
        </button>
      </div>
      <ProgressBar value={job.progress} className="mt-3" />
      <div className="mt-2 flex items-center justify-between text-xs text-muted">
        <span>{job.message ?? "Working..."}</span>
        <span>{job.progress}%</span>
      </div>
    </div>
  );
}
