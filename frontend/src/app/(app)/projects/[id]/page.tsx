"use client";

import { use, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
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
  RefreshCw,
  FileText,
  Download,
} from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, apiOrigin, ApiError } from "@/lib/api";
import { useAuthStore } from "@/store/auth";
import { toast } from "@/store/toast";
import { formatBytes, formatDuration } from "@/lib/format";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { StatusBadge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { VideoPlayer } from "@/components/projects/VideoPlayer";
import { TranscriptPanel } from "@/components/clips/TranscriptPanel";
import { ProcessingStatus } from "@/components/projects/ProcessingStatus";
import { ScheduledPublishing } from "@/components/projects/ScheduledPublishing";
import { CandidateCard } from "@/components/projects/CandidateCard";
import { GenerateClipsModal } from "@/components/projects/GenerateClipsModal";
import { ReactionRecorderModal } from "@/components/reactions/ReactionRecorderModal";
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
  const router = useRouter();
  const videoRef = useRef<HTMLVideoElement>(null);
  const [generateOpen, setGenerateOpen] = useState(false);
  const [selectedCandidate, setSelectedCandidate] = useState<number | null>(null);
  const [generatingId, setGeneratingId] = useState<number | null>(null);
  const [reprocessing, setReprocessing] = useState(false);
  const [reactingCandidate, setReactingCandidate] = useState<ClipCandidate | null>(null);
  const [showTranscript, setShowTranscript] = useState(false);
  const [videoTime, setVideoTime] = useState(0);
  const [downloadingTranscript, setDownloadingTranscript] = useState(false);

  const { data: projectRes, isLoading } = useApi<{ data: Project }>(`/projects/${projectId}`, {
    refreshInterval: (latest) =>
      latest && ACTIVE_STATUSES.includes(latest.data.status) ? 2000 : 0,
  });
  const project = projectRes?.data;
  const active = !!project && ACTIVE_STATUSES.includes(project.status);

  const canAnalyze = project?.video?.status === "ready" && (project?.clip_candidates_count ?? 0) === 0;
  const hasCandidates = (project?.clip_candidates_count ?? 0) > 0;
  // The complement of canAnalyze: once candidates already exist, "Analyze Video"
  // never reappears on its own — this is the only way back to it short of the
  // "Reprocess" recovery action, which only runs for a project that's FAILED.
  const canReanalyze = project?.video?.status === "ready" && hasCandidates && !active;

  const videoId = project?.video?.id;
  const { data: transcriptRes } = useApi<{ language: string; segments: { start: number; end: number; text: string }[] }>(
    showTranscript && videoId ? `/videos/${videoId}/transcript` : null
  );

  async function handleDownloadTranscript() {
    if (!videoId) return;
    setDownloadingTranscript(true);
    try {
      const token = useAuthStore.getState().token;
      const res = await fetch(`${apiOrigin()}/api/videos/${videoId}/transcript/download`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      if (!res.ok) throw new Error("Download failed");
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${project?.title ?? "transcript"}.srt`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast("Failed to download transcript.", "danger");
    } finally {
      setDownloadingTranscript(false);
    }
  }

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

  /**
   * Hits the same /analyze endpoint as a first-time analysis — the backend
   * doesn't distinguish "first pass" from "redo": AnalyzeVideoJob deletes this
   * video's existing ClipCandidate rows before creating fresh ones. Already
   * generated clips are unaffected (clips.clip_candidate_id is nullOnDelete,
   * not cascadeOnDelete), so this only replaces the AI's suggestions, never
   * anything already rendered.
   */
  async function handleReanalyze() {
    if (
      !confirm(
        "Re-analyze this video? Current clip suggestions will be replaced with a fresh set. Clips you've already generated are not affected."
      )
    ) {
      return;
    }

    try {
      await api.post(`/projects/${projectId}/analyze`);
      await mutate(`/projects/${projectId}`);
      await mutate(`/projects/${projectId}/clip-candidates`);
      toast("Re-analysis started.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to start re-analysis.", "danger");
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
            {canReanalyze && (
              <Button variant="secondary" onClick={handleReanalyze}>
                <RefreshCw className="size-4" />
                Re-analyze
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
        <div className="lg:col-span-3 space-y-3">
          <div className="aspect-video">
            {project.video?.url ? (
              <VideoPlayer
                ref={videoRef}
                src={project.video.url}
                poster={project.video.thumbnail_url}
                onTimeUpdate={setVideoTime}
              />
            ) : (
              <div className="flex h-full items-center justify-center rounded-2xl bg-surface-elevated">
                <Skeleton className="h-full w-full" />
              </div>
            )}
          </div>

          {project.video?.has_transcript && (
            <>
              <Button variant={showTranscript ? "secondary" : "outline"} size="sm" onClick={() => setShowTranscript((v) => !v)}>
                <FileText className="size-3.5" />
                Transcript
              </Button>

              {showTranscript && (
                <TranscriptPanel
                  lines={transcriptRes?.segments ?? []}
                  time={videoTime}
                  emptyLabel={transcriptRes ? "No transcript segments available." : "Loading transcript..."}
                  onSeek={(t) => {
                    setVideoTime(t);
                    if (videoRef.current) {
                      videoRef.current.currentTime = t;
                      void videoRef.current.play();
                    }
                  }}
                  downloadAction={
                    <button
                      type="button"
                      onClick={handleDownloadTranscript}
                      disabled={downloadingTranscript}
                      title="Download full transcript (.srt)"
                      className="flex items-center gap-1 rounded-lg px-1.5 py-1 text-xs text-muted hover:bg-white/5 hover:text-foreground disabled:opacity-50 cursor-pointer"
                    >
                      <Download className="size-3.5" />
                      .srt
                    </button>
                  }
                />
              )}
            </>
          )}
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
                onReact={() => setReactingCandidate(candidate)}
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

      {reactingCandidate && (
        <ReactionRecorderModal
          open={!!reactingCandidate}
          onClose={() => setReactingCandidate(null)}
          startTime={reactingCandidate.start_time}
          endTime={reactingCandidate.end_time}
          sourceVideoUrl={project.video?.url ?? null}
          submitUrl={`/clip-candidates/${reactingCandidate.id}/reaction`}
          onSuccess={(clip) => router.push(`/clips/${clip.id}`)}
        />
      )}
    </div>
  );
}
