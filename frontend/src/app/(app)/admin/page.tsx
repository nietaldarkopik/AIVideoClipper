"use client";

import { Users, FolderKanban, Film, Scissors, CheckCircle2, XCircle, Loader2, Timer } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { Card } from "@/components/ui/Card";
import { Skeleton } from "@/components/ui/Skeleton";

interface AdminStats {
  total_users: number;
  total_projects: number;
  total_videos: number;
  total_clips: number;
  clips_completed: number;
  clips_failed: number;
  jobs_active: number;
  jobs_failed: number;
  avg_render_seconds: number;
}

function Tile({ icon: Icon, label, value, tone }: { icon: typeof Users; label: string; value: number | string; tone?: string }) {
  return (
    <Card className="p-5">
      <div className="flex items-center justify-between">
        <span className="text-xs font-medium text-muted">{label}</span>
        <Icon className={`size-4 ${tone ?? "text-accent-2"}`} />
      </div>
      <p className="mt-2 text-2xl font-semibold">{value}</p>
    </Card>
  );
}

export default function AdminOverviewPage() {
  const { data, isLoading } = useApi<AdminStats>("/admin/stats");

  if (isLoading || !data) {
    return (
      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        {Array.from({ length: 8 }).map((_, i) => (
          <Skeleton key={i} className="h-24" />
        ))}
      </div>
    );
  }

  return (
    <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
      <Tile icon={Users} label="Total Users" value={data.total_users} />
      <Tile icon={FolderKanban} label="Total Projects" value={data.total_projects} />
      <Tile icon={Film} label="Total Videos" value={data.total_videos} />
      <Tile icon={Scissors} label="Total Clips" value={data.total_clips} />
      <Tile icon={CheckCircle2} label="Clips Completed" value={data.clips_completed} tone="text-success" />
      <Tile icon={XCircle} label="Clips Failed" value={data.clips_failed} tone="text-danger" />
      <Tile icon={Loader2} label="Active Jobs" value={data.jobs_active} />
      <Tile icon={Timer} label="Avg Render Time" value={`${data.avg_render_seconds ?? 0}s`} />
    </div>
  );
}
