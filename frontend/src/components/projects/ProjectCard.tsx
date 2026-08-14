import Link from "next/link";
import { Film, Scissors, ArrowRight } from "lucide-react";
import { Card } from "@/components/ui/Card";
import { StatusBadge } from "@/components/ui/Badge";
import { formatDuration, formatRelativeTime } from "@/lib/format";
import type { Project } from "@/lib/types";

export function ProjectCard({ project }: { project: Project }) {
  const thumbnail = project.video?.thumbnail_url;

  return (
    <Card className="group overflow-hidden transition-colors hover:border-accent/40">
      <div className="relative aspect-video w-full overflow-hidden bg-surface-elevated">
        {thumbnail ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={thumbnail} alt={project.title} className="h-full w-full object-cover" />
        ) : (
          <div className="flex h-full w-full items-center justify-center">
            <Film className="size-8 text-muted" />
          </div>
        )}
        <div className="absolute right-2 top-2">
          <StatusBadge status={project.status} />
        </div>
      </div>
      <div className="p-4">
        <h3 className="truncate text-sm font-semibold">{project.title}</h3>
        <div className="mt-1.5 flex items-center gap-3 text-xs text-muted">
          <span>{formatDuration(project.video?.duration_seconds)}</span>
          <span className="flex items-center gap-1">
            <Scissors className="size-3" />
            {project.clips_count ?? 0} clips
          </span>
        </div>
        <p className="mt-1 text-xs text-muted">
          Last edited {formatRelativeTime(project.last_edited_at ?? project.updated_at)}
        </p>
        <Link
          href={`/projects/${project.id}`}
          className="mt-3 flex items-center justify-center gap-1.5 rounded-lg border border-border-subtle bg-surface-elevated py-2 text-xs font-medium transition-colors group-hover:border-accent/40 group-hover:text-accent-2"
        >
          Open Project
          <ArrowRight className="size-3.5" />
        </Link>
      </div>
    </Card>
  );
}
