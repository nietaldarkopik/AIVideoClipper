"use client";

import Link from "next/link";
import { FolderKanban, Scissors, Loader2, AlertTriangle, Plus, Sparkles } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { ProjectCard } from "@/components/projects/ProjectCard";
import type { DashboardStats, Project } from "@/lib/types";

function StatTile({
  icon: Icon,
  label,
  value,
  tone,
}: {
  icon: typeof FolderKanban;
  label: string;
  value: number;
  tone?: "accent" | "danger" | "success";
}) {
  return (
    <Card className="p-5">
      <div className="flex items-center justify-between">
        <span className="text-xs font-medium text-muted">{label}</span>
        <Icon
          className={
            "size-4 " +
            (tone === "danger" ? "text-danger" : tone === "success" ? "text-success" : "text-accent-2")
          }
        />
      </div>
      <p className="mt-2 text-2xl font-semibold">{value}</p>
    </Card>
  );
}

export default function DashboardPage() {
  const { data, isLoading } = useApi<{ stats: DashboardStats; recent_projects: { data: Project[] } }>(
    "/dashboard"
  );

  return (
    <div className="space-y-8">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Dashboard</h1>
          <p className="mt-1 text-sm text-muted">Your clipping activity at a glance.</p>
        </div>
        <Link href="/projects?new=1">
          <Button>
            <Plus className="size-4" />
            New Project
          </Button>
        </Link>
      </div>

      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        {isLoading || !data ? (
          Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-24" />)
        ) : (
          <>
            <StatTile icon={FolderKanban} label="Total Projects" value={data.stats.total_projects} />
            <StatTile icon={Scissors} label="Clips Generated" value={data.stats.total_clips_generated} />
            <StatTile
              icon={Loader2}
              label="Processing"
              value={data.stats.clips_processing + data.stats.jobs_active}
              tone="accent"
            />
            <StatTile icon={AlertTriangle} label="Failed Jobs" value={data.stats.clips_failed + data.stats.jobs_failed} tone="danger" />
          </>
        )}
      </div>

      <div>
        <div className="mb-3 flex items-center gap-2">
          <Sparkles className="size-4 text-accent-2" />
          <h2 className="text-sm font-semibold">Recent Projects</h2>
        </div>

        {isLoading || !data ? (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 3 }).map((_, i) => (
              <Skeleton key={i} className="h-56" />
            ))}
          </div>
        ) : data.recent_projects.data.length === 0 ? (
          <EmptyState
            icon={<FolderKanban className="size-6" />}
            title="No projects yet"
            description="Upload a video or paste a URL to generate your first clips."
            action={
              <Link href="/projects?new=1">
                <Button size="sm">
                  <Plus className="size-4" />
                  New Project
                </Button>
              </Link>
            }
          />
        ) : (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {data.recent_projects.data.map((project) => (
              <ProjectCard key={project.id} project={project} />
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
