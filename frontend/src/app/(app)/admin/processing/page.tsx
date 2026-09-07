"use client";

import { useState } from "react";
import { useApi } from "@/lib/hooks";
import { Card } from "@/components/ui/Card";
import { StatusBadge } from "@/components/ui/Badge";
import { ProgressBar } from "@/components/ui/ProgressBar";
import { Skeleton } from "@/components/ui/Skeleton";
import { Button } from "@/components/ui/Button";
import { formatRelativeTime, statusLabel } from "@/lib/format";
import { ProcessingJobDetailModal } from "@/components/admin/ProcessingJobDetailModal";
import type { Paginated, ProcessingJob } from "@/lib/types";

export default function AdminProcessingPage() {
  const { data, isLoading } = useApi<Paginated<ProcessingJob>>("/admin/processing-jobs?per_page=50", {
    refreshInterval: 3000,
  });
  const [selected, setSelected] = useState<ProcessingJob | null>(null);

  if (isLoading || !data) return <Skeleton className="h-80" />;

  return (
    <>
      <Card className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border-subtle text-left text-xs text-muted">
              <th className="px-5 py-3 font-medium">Type</th>
              <th className="px-5 py-3 font-medium">Status</th>
              <th className="px-5 py-3 font-medium">Progress</th>
              <th className="px-5 py-3 font-medium">Message</th>
              <th className="px-5 py-3 font-medium">Started</th>
              <th className="px-5 py-3 font-medium"></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border-subtle">
            {data.data.map((job) => (
              <tr key={job.id}>
                <td className="px-5 py-3 font-medium">{statusLabel(job.type)}</td>
                <td className="px-5 py-3">
                  <StatusBadge status={job.status} />
                </td>
                <td className="px-5 py-3 w-40">
                  <ProgressBar
                    value={job.progress}
                    tone={job.status === "failed" ? "danger" : job.status === "completed" ? "success" : "accent"}
                  />
                </td>
                <td className="max-w-xs truncate px-5 py-3 text-muted">{job.error ?? job.message ?? "--"}</td>
                <td className="px-5 py-3 text-muted">{formatRelativeTime(job.started_at)}</td>
                <td className="px-5 py-3 text-right">
                  <Button size="sm" variant="outline" onClick={() => setSelected(job)}>
                    View
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>

      <ProcessingJobDetailModal job={selected} onClose={() => setSelected(null)} />
    </>
  );
}
