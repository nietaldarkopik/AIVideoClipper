"use client";

import { use, useRef, useState } from "react";
import Link from "next/link";
import { mutate } from "swr";
import {
  Sparkles,
  Clock,
  Monitor,
  HardDrive,
  Globe,
  Trash2,
  ArrowLeft,
  Scissors,
  Wand2,
  RotateCcw,
} from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { formatBytes, formatDuration } from "@/lib/format";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { StatusBadge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { VideoPlayer } from "@/components/projects/VideoPlayer";
import { ProcessingStatus } from "@/components/projects/ProcessingStatus";
import { ScheduledPublishing } from "@/components/projects/ScheduledPublishing";
import { CandidateCard } from "@/components/projects/CandidateCard";
import { GenerateClipsModal } from "@/components/projects/GenerateClipsModal";
import { ClipCard } from "@/components/clips/ClipCard";
import type { Clip, ClipCandidate, Paginated, Project } from "@/lib/types";

const ACTIVE_STATUSES = [
  "uploading",
  "processing",
  "transcribing",
  "analyzing",
  "generating_clips",
  "rendering",
];

export default function ProjectDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const projectId = Number(id);
  const videoRef = useRef<HTMLVideoElement>(null);
  const [generateOpen, setGenerateOpen] = useState(false);
  const [selectedCandidate, setSelectedCandidate] = useState<number | null>(null);
  const [generatingId, setGeneratingId] = useState<number | null>(null);
  const [reprocessing, setReprocessing] = useState(false);

  const { data: projectRes, isLoading } = useApi<{ data: Project }>(`/projects/${projectId}`, {
    refreshInterval: (latest) =>
      latest && ACTIVE_STATUSES.includes(latest.data.status) ? 2000 : 0,
  });
  const project = projectRes?.data;
  const active = !!project && ACTIVE_STATUSES.includes(project.status);

  const canAnalyze = project?.video?.status === "ready" && (project?.clip_candidates_count ?? 0) === 0;
  const hasCandidates = (project?.clip_candidates_count ?? 0) > 0;

  const { data: candidatesRes } = useApi<{ data: ClipCandidate[] }>(
    hasCandidates ? `/projects/${projectId}/clip-candidates` : null
  );

  const { data: clipsRes } = useApi<Paginated<Clip>>(
    project ? `/clips?project_id=${projectId}&per_page=50` : null,
    {
      refreshInterval: (latest?: Paginated<Clip>) =>
        latest?.data.some((c: Clip) => c.status === "queued" || c.status === "rendering") ? 2000 : 0,
    }
  );

  async function handleAnalyze() {
    try {
      await api.post(`/projects/${projectId}/analyze`);
      await mutate(`/projects/${projectId}`);
      toast("Analysis started.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to start analysis.", "danger");
    }
  }

  async function handleReprocess() {
    setReprocessing(true);
    try {
      const res = await api.post<{ data: Project; message?: string }>(`/projects/${projectId}/reprocess`);
      await mutate(`/projects/${projectId}`);
      await mutate(`/clips?project_id=${projectId}&per_page=50`);
      toast(res.message ?? "Reprocessing started.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to reprocess.", "danger");
    } finally {
      setReprocessing(false);
    }
  }

  async function handleGenerateOne(candidateId: number) {
    setGeneratingId(candidateId);
    try {
      await api.post(`/projects/${projectId}/generate-clips`, { candidate_ids: [candidateId] });
      await mutate(`/projects/${projectId}/clip-candidates`);
      await mutate(`/clips?project_id=${projectId}&per_page=50`);
      await mutate(`/projects/${projectId}`);
      toast("Clip queued for rendering.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to generate clip.", "danger");
    } finally {
      setGeneratingId(null);
    }
  }

  function handlePreview(candidate: ClipCandidate) {
    if (videoRef.current) {
      videoRef.current.currentTime = candidate.start_time;
      videoRef.current.play();
    }
  }

  async function handleDeleteProject() {
    if (!confirm("Delete this project and all its clips? This cannot be undone.")) return;
    try {
      await api.del(`/projects/${projectId}`);
      toast("Project deleted.", "success");
      window.location.href = "/projects";
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to delete.", "danger");
    }
  }

  if (isLoading || !project) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-80" />
      </div>
    );
  }

  return (
    <div className="space-y-8">
      <div>
        <Link href="/projects" className="mb-3 flex items-center gap-1.5 text-xs text-muted hover:text-foreground">
          <ArrowLeft className="size-3.5" />
          Back to Projects
        </Link>
        <div className="flex items-start justify-between gap-4">
          <div>
            <div className="flex items-center gap-2.5">
              <h1 className="text-xl font-semibold">{project.title}</h1>
              <StatusBadge status={project.status} />
            </div>
            {project.description && <p className="mt-1 text-sm text-muted">{project.description}</p>}
          </div>
          <div className="flex items-center gap-2">
            {canAnalyze && (
              <Button onClick={handleAnalyze}>
                <Wand2 className="size-4" />
                Analyze Video
              </Button>
            )}
            {hasCandidates && (
              <Button
                variant="secondary"
                onClick={() => {
                  setSelectedCandidate(null);
                  setGenerateOpen(true);
                }}
              >
                <Sparkles className="size-4" />
                Generate Clips
              </Button>
            )}
            <button
              onClick={handleDeleteProject}
              title="Delete project"
              className="rounded-xl p-2.5 text-muted hover:bg-danger/10 hover:text-danger cursor-pointer"
            >
              <Trash2 className="size-4" />
            </button>
          </div>
        </div>
      </div>

      {active && <ProcessingStatus projectId={projectId} active={active} />}

      <ScheduledPublishing projectId={projectId} />

      {project.status === "failed" && (
        <div className="flex items-center justify-between gap-4 rounded-2xl border border-danger/30 bg-danger/5 p-4 text-sm text-danger">
          <span>{project.failure_reason || "This project failed."}</span>
          <Button variant="danger" size="sm" onClick={handleReprocess} loading={reprocessing} className="shrink-0">
            <RotateCcw className="size-3.5" />
            Reprocess
          </Button>
        </div>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-5">
        <div className="lg:col-span-3">
          <div className="aspect-video">
            {project.video?.url ? (
              <VideoPlayer ref={videoRef} src={project.video.url} poster={project.video.thumbnail_url} />
            ) : (
              <div className="flex h-full items-center justify-center rounded-2xl bg-surface-elevated">
                <Skeleton className="h-full w-full" />
              </div>
            )}
          </div>
        </div>

        <Card className="lg:col-span-2 p-5">
          <h3 className="text-sm font-semibold">Video Details</h3>
          <div className="mt-4 space-y-3 text-sm">
            <div className="flex items-center gap-2.5 text-muted">
              <Clock className="size-4" />
              Duration
              <span className="ml-auto text-foreground">{formatDuration(project.video?.duration_seconds)}</span>
            </div>
            <div className="flex items-center gap-2.5 text-muted">
              <Monitor className="size-4" />
              Resolution
              <span className="ml-auto text-foreground">{project.video?.resolution ?? "--"}</span>
            </div>
            <div className="flex items-center gap-2.5 text-muted">
              <HardDrive className="size-4" />
              File Size
              <span className="ml-auto text-foreground">{formatBytes(project.video?.file_size_bytes)}</span>
            </div>
            <div className="flex items-center gap-2.5 text-muted">
              <Globe className="size-4" />
              Source
              <span className="ml-auto capitalize text-foreground">{project.video?.source_type ?? "--"}</span>
            </div>
          </div>
        </Card>
      </div>

      <div>
        <div className="mb-3 flex items-center gap-2">
          <Sparkles className="size-4 text-accent-2" />
          <h2 className="text-sm font-semibold">AI Recommendations</h2>
        </div>

        {!hasCandidates ? (
          <EmptyState
            icon={<Wand2 className="size-6" />}
            title={canAnalyze ? "Ready to analyze" : "No recommendations yet"}
            description={
              canAnalyze
                ? "Run AI analysis to detect the most engaging moments in this video."
                : "Waiting for the video to finish processing."
            }
            action={
              canAnalyze ? (
                <Button size="sm" onClick={handleAnalyze}>
                  <Wand2 className="size-4" />
                  Analyze Video
                </Button>
              ) : undefined
            }
          />
        ) : !candidatesRes ? (
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-72" />
            ))}
          </div>
        ) : (
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            {candidatesRes.data.map((candidate) => (
              <CandidateCard
                key={candidate.id}
                candidate={candidate}
                onPreview={() => handlePreview(candidate)}
                onGenerate={() => handleGenerateOne(candidate.id)}
                generating={generatingId === candidate.id}
              />
            ))}
          </div>
        )}
      </div>

      <div>
        <div className="mb-3 flex items-center gap-2">
          <Scissors className="size-4 text-accent-2" />
          <h2 className="text-sm font-semibold">Generated Clips</h2>
        </div>

        {!clipsRes || clipsRes.data.length === 0 ? (
          <EmptyState title="No clips generated yet" description="Generate clips from an AI recommendation above." />
        ) : (
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            {clipsRes.data.map((clip) => (
              <ClipCard key={clip.id} clip={clip} mutateKey={`/clips?project_id=${projectId}&per_page=50`} />
            ))}
          </div>
        )}
      </div>

      <GenerateClipsModal
        open={generateOpen}
        onClose={() => setGenerateOpen(false)}
        projectId={projectId}
        candidateIds={selectedCandidate ? [selectedCandidate] : undefined}
      />
    </div>
  );
}
