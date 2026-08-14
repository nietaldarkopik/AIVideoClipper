"use client";

import { Loader2 } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { ProgressBar } from "@/components/ui/ProgressBar";
import type { ProcessingJob } from "@/lib/types";

const STEP_LABELS: Record<string, string> = {
  import_video: "Importing video",
  analyze: "Analyzing video",
  render_clip: "Rendering clip",
};

export function ProcessingStatus({ projectId, active }: { projectId: number; active: boolean }) {
  const { data } = useApi<{ data: ProcessingJob[] }>(
    `/projects/${projectId}/processing-jobs`,
    { refreshInterval: active ? 1500 : 0 }
  );

  const job = data?.data.find((j) => j.status === "running" || j.status === "queued");
  if (!job) return null;

  return (
    <div className="rounded-2xl border border-accent/30 bg-accent/5 p-5">
      <div className="flex items-center gap-2 text-sm font-medium text-accent-2">
        <Loader2 className="size-4 animate-spin" />
        {STEP_LABELS[job.type] ?? "Processing"}...
      </div>
      <ProgressBar value={job.progress} className="mt-3" />
      <div className="mt-2 flex items-center justify-between text-xs text-muted">
        <span>{job.message ?? "Working..."}</span>
        <span>{job.progress}%</span>
      </div>
    </div>
  );
}
