"use client";

import { use, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { mutate } from "swr";
import {
  ArrowLeft,
  Save,
  RefreshCw,
  Trash2,
  Copy,
  Download,
  Video as VideoIcon,
  Undo2,
  Redo2,
  Scissors,
  Upload,
  X,
  Play,
  Pause,
} from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Input, Label, Select, Textarea } from "@/components/ui/Input";
import { StatusBadge } from "@/components/ui/Badge";
import { ProgressBar } from "@/components/ui/ProgressBar";
import { Skeleton } from "@/components/ui/Skeleton";
import { PublishPanel } from "@/components/clips/PublishPanel";
import { ReactionIntroPanel } from "@/components/clips/ReactionIntroPanel";
import { ReactionRecorderModal } from "@/components/reactions/ReactionRecorderModal";
import { TimelineTrack } from "@/components/clips/timeline/TimelineTrack";
import { ClipVideoPreview } from "@/components/clips/timeline/ClipVideoPreview";
import { ManualCropEditor } from "@/components/clips/timeline/ManualCropEditor";
import { useClipEditorHistory, type EditorSnapshot } from "@/components/clips/timeline/useClipEditorHistory";
import { LayerEditor } from "@/components/layers/LayerEditor";
import { diffLayers } from "@/lib/layerOverrides";
import { formatDuration } from "@/lib/format";
import type { Clip, CropKeyframe, Template, TemplateLayer, Video } from "@/lib/types";

const ACTIVE = ["queued", "rendering"];
const ASPECT_RATIOS: Record<string, { width: number; height: number }> = {
  "9:16": { width: 1080, height: 1920 },
  "1:1": { width: 1080, height: 1080 },
  "16:9": { width: 1920, height: 1080 },
};

type TabKey = "trim" | "layers" | "audio" | "captions" | "details" | "reaction" | "publish";
const TABS: { key: TabKey; label: string }[] = [
  { key: "trim", label: "Trim & Crop" },
  { key: "layers", label: "Text & Layers" },
  { key: "audio", label: "Audio" },
  { key: "captions", label: "Captions" },
  { key: "details", label: "Details" },
  { key: "reaction", label: "Reaction" },
  { key: "publish", label: "Publish" },
];

interface PreviewConfig {
  resolution: { width: number; height: number };
  caption: Record<string, unknown>;
  branding: Record<string, unknown>;
  layers: TemplateLayer[];
  template_layers: TemplateLayer[];
}

function defaultCropKeyframe(sourceWidth: number, sourceHeight: number, targetAspect: { width: number; height: number }): CropKeyframe {
  let width = Math.min(sourceWidth, sourceHeight * (targetAspect.width / targetAspect.height));
  let height = width * (targetAspect.height / targetAspect.width);
  if (height > sourceHeight) {
    height = sourceHeight;
    width = height * (targetAspect.width / targetAspect.height);
  }
  return { time: 0, x: (sourceWidth - width) / 2, y: (sourceHeight - height) / 2, width, height };
}

export default function ClipEditorPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const clipId = Number(id);
  const clipKey = `/clips/${clipId}`;

  const { data: clipRes, isLoading } = useApi<{ data: Clip }>(clipKey, {
    refreshInterval: (latest) => (latest && ACTIVE.includes(latest.data.status) ? 1500 : 0),
  });
  const clip = clipRes?.data;
  const { data: templatesRes } = useApi<{ data: Template[] }>("/templates");
  const { data: videoRes } = useApi<{ data: Video }>(clip ? `/videos/${clip.video_id}` : null);
  const { data: previewRes } = useApi<{ data: PreviewConfig }>(clip ? `${clipKey}/preview-config` : null);
  const preview = previewRes?.data;
  const [reacting, setReacting] = useState(false);

  const [form, setForm] = useState<{
    title: string;
    caption: string;
    hashtags: string;
    aspect_ratio: string;
    template_id: string;
    subtitles_enabled: boolean;
    subtitle_language: string;
    reaction_script: string;
    intro_enabled: boolean;
    outro_enabled: boolean;
    intro_voice: string;
    reference_url: string | null;
    speed: number;
    volume: number;
  } | null>(null);
  const [saving, setSaving] = useState(false);
  const [splitting, setSplitting] = useState(false);
  const [currentTime, setCurrentTime] = useState(0);
  const [selectedLayerId, setSelectedLayerId] = useState<string | null>(null);
  const [uploadingSubtitle, setUploadingSubtitle] = useState(false);
  const [activeTab, setActiveTab] = useState<TabKey>("trim");
  const [isPlaying, setIsPlaying] = useState(false);
  const videoRef = useRef<HTMLVideoElement>(null);
  const subtitleInputRef = useRef<HTMLInputElement>(null);

  const history = useClipEditorHistory({ segments: [], layers: [], cropMode: "smart", cropKeyframe: null });
  const [historyReady, setHistoryReady] = useState(false);

  useEffect(() => {
    if (clip && !form) {
      setForm({
        title: clip.title ?? "",
        caption: clip.caption ?? "",
        hashtags: (clip.hashtags ?? []).join(", "),
        aspect_ratio: clip.aspect_ratio,
        template_id: clip.template?.id ? String(clip.template.id) : "",
        subtitles_enabled: clip.subtitles_enabled,
        subtitle_language: clip.subtitle_language,
        reaction_script: clip.reaction_script ?? "",
        intro_enabled: clip.intro_enabled,
        outro_enabled: clip.outro_enabled,
        intro_voice: clip.intro_voice ?? "alloy",
        reference_url: clip.reference_url ?? null,
        speed: clip.speed ?? 1,
        volume: clip.volume ?? 1,
      });
    }
  }, [clip, form]);

  // preview-config only resolves once the clip has loaded, so seed the undo/redo
  // history's initial snapshot as soon as both the clip's trim and its merged
  // layers are available — resetting (not pushing) so this doesn't itself become
  // an undo step.
  useEffect(() => {
    if (clip && preview && !historyReady) {
      const segments =
        clip.segments && clip.segments.length > 0 ? clip.segments : [{ start: clip.start_time, end: clip.end_time }];
      const cropMode = clip.crop_config?.mode === "manual" ? "manual" : "smart";
      const cropKeyframe = cropMode === "manual" ? (clip.crop_config?.keyframes?.[0] ?? null) : null;
      history.reset({ segments, layers: preview.layers, cropMode, cropKeyframe });
      setHistoryReady(true);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [clip, preview, historyReady]);

  useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
      if (!(e.ctrlKey || e.metaKey)) return;
      if (e.key.toLowerCase() !== "z") return;
      e.preventDefault();
      if (e.shiftKey) history.redo();
      else history.undo();
    }
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Native <video> play/pause state isn't reactive on its own — mirror it so
  // the floating toolbar's button can show the right icon. Depends on
  // historyReady (not just the url) because that's the actual gate on
  // whether ClipVideoPreview — and therefore the <video ref={videoRef}> — is
  // mounted yet; keying only on the url risks binding before the element
  // exists and then never re-running once it does.
  useEffect(() => {
    const el = videoRef.current;
    if (!el) return;
    const onPlay = () => setIsPlaying(true);
    const onPause = () => setIsPlaying(false);
    el.addEventListener("play", onPlay);
    el.addEventListener("pause", onPause);
    return () => {
      el.removeEventListener("play", onPlay);
      el.removeEventListener("pause", onPause);
    };
  }, [videoRes?.data?.url, historyReady]);

  function togglePlay() {
    const el = videoRef.current;
    if (!el) return;
    if (el.paused) void el.play();
    else el.pause();
  }

  const envelopeStart = history.current.segments[0]?.start ?? 0;
  const envelopeEnd = history.current.segments[history.current.segments.length - 1]?.end ?? 0;

  async function persist(snapshot: EditorSnapshot, extra: Record<string, unknown> = {}) {
    if (!form) return;
    const layerOverrides = preview ? diffLayers(preview.template_layers, snapshot.layers) : undefined;
    const cropConfig = snapshot.cropMode === "manual" && snapshot.cropKeyframe ? { mode: "manual", keyframes: [snapshot.cropKeyframe] } : null;
    await api.patch(clipKey, {
      title: form.title,
      caption: form.caption,
      hashtags: form.hashtags
        .split(",")
        .map((h) => h.trim())
        .filter(Boolean),
      segments: snapshot.segments,
      aspect_ratio: form.aspect_ratio,
      template_id: form.template_id ? Number(form.template_id) : null,
      subtitles_enabled: form.subtitles_enabled,
      subtitle_language: form.subtitle_language,
      layer_overrides: layerOverrides,
      crop_config: cropConfig,
      reaction_script: form.reaction_script || null,
      intro_enabled: form.intro_enabled,
      outro_enabled: form.outro_enabled,
      intro_voice: form.intro_voice || null,
      reference_url: form.reference_url || null,
      speed: form.speed,
      volume: form.volume,
      ...extra,
    });
  }

  async function handleSave() {
    if (!clip || !form) return;
    setSaving(true);
    try {
      await persist(history.current);
      await mutate(clipKey);
      await mutate(`${clipKey}/preview-config`);
      toast("Clip updated — re-rendering.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to update clip.", "danger");
    } finally {
      setSaving(false);
    }
  }

  // Regenerate re-renders with whatever's already saved on the clip — it doesn't
  // know about the settings form below, so if it called a bare "regenerate"
  // endpoint, a pending template/aspect-ratio/etc. change picked in the form would
  // silently be discarded (the render wouldn't change, and the form would appear
  // to "revert" next time it reloaded from the server's still-unchanged value).
  // Routing it through the same save flow as the form's own button means either
  // button always applies whatever's currently selected.
  async function handleRegenerate() {
    await handleSave();
  }

  async function handleUploadSubtitle(file: File) {
    setUploadingSubtitle(true);
    try {
      const form = new FormData();
      form.append("file", file);
      await api.post(`${clipKey}/subtitle`, form);
      await mutate(clipKey);
      toast("Custom captions attached — re-rendering.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to upload captions.", "danger");
    } finally {
      setUploadingSubtitle(false);
      if (subtitleInputRef.current) subtitleInputRef.current.value = "";
    }
  }

  async function handleRemoveSubtitle() {
    if (!confirm("Remove the custom captions? The clip will go back to auto-generated captions on the next render.")) return;
    setUploadingSubtitle(true);
    try {
      await api.del(`${clipKey}/subtitle`);
      await mutate(clipKey);
      toast("Custom captions removed — re-rendering.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to remove captions.", "danger");
    } finally {
      setUploadingSubtitle(false);
    }
  }

  async function handleDuplicate() {
    try {
      const res = await api.post<{ data: Clip }>(`/clips/${clipId}/duplicate`);
      toast("Clip duplicated.", "success");
      window.location.href = `/clips/${res.data.id}`;
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to duplicate.", "danger");
    }
  }

  // Splitting a clip has no meaningful "uncommitted" representation — clips
  // render server-side purely from segments/start_time/end_time (+ overrides),
  // one row per render, so the natural unit of a split is two real Clip rows
  // meeting at the playhead. Only offered for a plain single-segment trim — a
  // multi-segment (jump-cut) selection has no single well-defined "playhead
  // position" to split two disjoint ranges around, so the button is hidden then.
  async function handleSplit() {
    if (!clip || !videoRes?.data) return;
    const splitAt = Number(currentTime.toFixed(2));
    if (splitAt <= envelopeStart + 0.2 || splitAt >= envelopeEnd - 0.2) {
      toast("Move the playhead inside the trimmed range to split.", "danger");
      return;
    }
    setSplitting(true);
    try {
      await persist(history.current);
      const dup = await api.post<{ data: Clip }>(`/clips/${clipId}/duplicate`);
      await Promise.all([
        api.patch(clipKey, { segments: [{ start: envelopeStart, end: splitAt }] }),
        api.patch(`/clips/${dup.data.id}`, { segments: [{ start: splitAt, end: envelopeEnd }] }),
      ]);
      toast("Clip split into two — opening the second half.", "success");
      window.location.href = `/clips/${dup.data.id}`;
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to split.", "danger");
      setSplitting(false);
    }
  }

  async function handleDelete() {
    if (!clip) return;
    if (!confirm("Delete this clip? This cannot be undone.")) return;
    try {
      await api.del(clipKey);
      toast("Clip deleted.", "success");
      window.location.href = `/projects/${clip.project_id}`;
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to delete.", "danger");
    }
  }

  function setCropMode(mode: "smart" | "manual") {
    if (mode === "manual" && !history.current.cropKeyframe && videoRes?.data?.width && videoRes?.data?.height) {
      const targetAspect = preview?.resolution ?? ASPECT_RATIOS[form?.aspect_ratio ?? "9:16"];
      history.push({
        ...history.current,
        cropMode: mode,
        cropKeyframe: defaultCropKeyframe(videoRes.data.width, videoRes.data.height, targetAspect),
      });
    } else {
      history.push({ ...history.current, cropMode: mode });
    }
  }

  if (isLoading || !clip || !form) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-64" />
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <Skeleton className="h-[500px] lg:col-span-1" />
          <Skeleton className="h-[500px] lg:col-span-2" />
        </div>
      </div>
    );
  }

  const isActive = ACTIVE.includes(clip.status);
  const overriddenIds = preview
    ? new Set(
        Object.keys(diffLayers(preview.template_layers, history.current.layers) ?? {}).filter(
          (k) => k !== "_new" && k !== "_removed"
        )
      )
    : undefined;
  const canReframe = !clip.reaction_layout; // reaction clips always render a single continuous window — see resolveSegments()

  return (
    <div className="space-y-6">
      <div>
        <Link
          href={`/projects/${clip.project_id}`}
          className="mb-3 flex items-center gap-1.5 text-xs text-muted hover:text-foreground"
        >
          <ArrowLeft className="size-3.5" />
          Back to Project
        </Link>
        <div className="flex items-start justify-between gap-4">
          <div className="flex items-center gap-2.5">
            <h1 className="text-xl font-semibold">{clip.title || `Clip #${clip.id}`}</h1>
            <StatusBadge status={clip.status} />
          </div>
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => setReacting(true)}>
              <VideoIcon className="size-3.5" />
              React
            </Button>
            <Button variant="outline" size="sm" onClick={handleRegenerate} loading={saving}>
              <RefreshCw className="size-3.5" />
              Regenerate
            </Button>
            <Button variant="outline" size="sm" onClick={handleDuplicate}>
              <Copy className="size-3.5" />
              Duplicate
            </Button>
            {clip.url && (
              <a href={`${clip.url}?download=1`}>
                <Button variant="outline" size="sm">
                  <Download className="size-3.5" />
                  Download
                </Button>
              </a>
            )}
            <button
              onClick={handleDelete}
              className="rounded-xl p-2.5 text-muted hover:bg-danger/10 hover:text-danger cursor-pointer"
            >
              <Trash2 className="size-4" />
            </button>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_400px]">
        {/* Editing canvas — the interactive preview + timeline are the page's visual
            anchor, always visible regardless of which tab is open on the right, since
            scrubbing/trimming is something you reach for no matter what you're editing. */}
        <div className="rounded-2xl border border-border-subtle bg-surface p-4 xl:p-6">
          <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-1 rounded-xl bg-surface-elevated p-1">
              <Button variant="ghost" size="sm" onClick={togglePlay} title={isPlaying ? "Pause" : "Play"}>
                {isPlaying ? <Pause className="size-3.5" /> : <Play className="size-3.5" />}
              </Button>
              <Button variant="ghost" size="sm" disabled={!history.canUndo} onClick={history.undo} title="Undo (Ctrl+Z)">
                <Undo2 className="size-3.5" />
              </Button>
              <Button
                variant="ghost"
                size="sm"
                disabled={!history.canRedo}
                onClick={history.redo}
                title="Redo (Ctrl+Shift+Z)"
              >
                <Redo2 className="size-3.5" />
              </Button>
            </div>
            {history.current.segments.length <= 1 && (
              <Button variant="outline" size="sm" onClick={handleSplit} loading={splitting}>
                <Scissors className="size-3.5" />
                Split at playhead
              </Button>
            )}
          </div>

          {videoRes?.data && preview && historyReady ? (
            <div className="space-y-4">
              <div className="mx-auto w-full max-w-[420px]">
                <ClipVideoPreview
                  videoRef={videoRef}
                  videoUrl={videoRes.data.url}
                  posterUrl={videoRes.data.thumbnail_url}
                  resolution={preview.resolution}
                  layers={history.current.layers}
                  currentTime={currentTime}
                  duration={videoRes.data.duration_seconds ?? 0}
                  onTimeUpdate={setCurrentTime}
                  interactive
                  selectedLayerId={selectedLayerId}
                  onSelectLayer={setSelectedLayerId}
                  onLayersChange={(layers) => history.push({ ...history.current, layers })}
                />
              </div>

              <TimelineTrack
                duration={videoRes.data.duration_seconds ?? 0}
                segments={history.current.segments}
                currentTime={currentTime}
                onChange={(segments) => history.push({ ...history.current, segments })}
                onSeek={(t) => {
                  setCurrentTime(t);
                  if (videoRef.current) videoRef.current.currentTime = t;
                }}
                thumbnailStripUrl={videoRes.data.thumbnail_strip_url}
                waveformUrl={videoRes.data.waveform_url}
              />
              <p className="text-[11px] text-muted">
                Multiple segments are cut and stitched together into one clip (jump cuts) — drag the handles to
                trim, or add another segment to pull in a second part of the source video.
              </p>
            </div>
          ) : (
            <Skeleton className="h-96" />
          )}
        </div>

        {/* Rendered output + tabbed settings panel */}
        <div className="space-y-4">
          <Card className="p-4">
            <p className="mb-2 text-center text-xs text-muted">Rendered output</p>
            <div className="mx-auto aspect-[9/16] w-36 overflow-hidden rounded-xl bg-black">
              {clip.status === "completed" && clip.url ? (
                <video src={clip.url} controls poster={clip.thumbnail_url ?? undefined} className="h-full w-full" />
              ) : (
                <div className="flex h-full flex-col items-center justify-center gap-2 p-3 text-center">
                  {isActive ? (
                    <>
                      <p className="text-[11px] text-muted">Rendering...</p>
                      <ProgressBar value={clip.progress ?? 0} className="w-full" />
                      <p className="text-[11px] text-muted">{clip.progress ?? 0}%</p>
                    </>
                  ) : clip.status === "failed" ? (
                    <p className="text-[11px] text-danger">{clip.failure_reason ?? "Render failed"}</p>
                  ) : (
                    <p className="text-[11px] text-muted">Not rendered yet</p>
                  )}
                </div>
              )}
            </div>
          </Card>

          <Card className="overflow-hidden p-0">
            <div className="flex flex-wrap gap-0.5 border-b border-border-subtle p-1.5">
              {TABS.map((tab) => (
                <button
                  key={tab.key}
                  type="button"
                  onClick={() => setActiveTab(tab.key)}
                  className={
                    "cursor-pointer whitespace-nowrap rounded-lg px-2.5 py-1.5 text-xs font-medium " +
                    (activeTab === tab.key ? "bg-accent text-white" : "text-muted hover:bg-white/5 hover:text-foreground")
                  }
                >
                  {tab.label}
                </button>
              ))}
            </div>

            <div className="space-y-4 p-5">
              {activeTab === "trim" && (
                <div>
                  <div className="mb-3 flex items-center justify-between">
                    <h4 className="text-xs font-semibold text-muted">Reframe / Crop</h4>
                    {canReframe && (
                      <div className="flex items-center gap-1 rounded-lg bg-surface-elevated p-0.5">
                        {(["smart", "manual"] as const).map((mode) => (
                          <button
                            key={mode}
                            type="button"
                            onClick={() => setCropMode(mode)}
                            className={
                              "rounded-md px-2.5 py-1 text-xs capitalize cursor-pointer " +
                              (history.current.cropMode === mode ? "bg-accent text-white" : "text-muted hover:text-foreground")
                            }
                          >
                            {mode === "smart" ? "Auto (AI)" : "Manual"}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                  {!canReframe ? (
                    <p className="text-xs text-muted">
                      This clip has a reaction recording, which already defines its own picture-in-picture layout.
                    </p>
                  ) : history.current.cropMode === "smart" ? (
                    <p className="text-xs text-muted">
                      AI picks and pans the crop window automatically (face/speaker tracking). Switch to Manual to
                      position it yourself.
                    </p>
                  ) : videoRes?.data.width && videoRes?.data.height && history.current.cropKeyframe ? (
                    <ManualCropEditor
                      videoUrl={videoRes.data.url}
                      posterUrl={videoRes.data.thumbnail_url}
                      sourceWidth={videoRes.data.width}
                      sourceHeight={videoRes.data.height}
                      targetAspect={preview?.resolution ?? ASPECT_RATIOS[form.aspect_ratio]}
                      keyframe={history.current.cropKeyframe}
                      onChange={(cropKeyframe) => history.push({ ...history.current, cropKeyframe })}
                    />
                  ) : (
                    <p className="text-xs text-muted">Source video dimensions aren&apos;t known yet.</p>
                  )}
                  <div className="mt-4 rounded-xl bg-surface-elevated px-3.5 py-3 text-xs text-muted">
                    Trim: {formatDuration(envelopeStart)} – {formatDuration(envelopeEnd)}
                    {history.current.segments.length > 1 && ` across ${history.current.segments.length} segments`} —
                    drag the timeline above the preview to adjust.
                  </div>
                </div>
              )}

              {activeTab === "layers" && (
                <LayerEditor
                  layers={history.current.layers}
                  onChange={(layers) => history.push({ ...history.current, layers })}
                  overriddenIds={overriddenIds}
                  selectedId={selectedLayerId}
                  onSelectChange={setSelectedLayerId}
                />
              )}

              {activeTab === "audio" && (
                <div className="space-y-4">
                  <div className="rounded-xl bg-surface-elevated px-3.5 py-3">
                    <Label>Speed</Label>
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                      {[0.5, 0.75, 1, 1.25, 1.5, 2].map((s) => (
                        <button
                          key={s}
                          type="button"
                          onClick={() => setForm({ ...form, speed: s })}
                          className={
                            "rounded-lg px-2.5 py-1 text-xs cursor-pointer " +
                            (form.speed === s ? "bg-accent text-white" : "bg-surface text-muted hover:text-foreground")
                          }
                        >
                          {s}x
                        </button>
                      ))}
                    </div>
                    <p className="mt-2 text-[11px] text-muted">
                      Applied last, after crop/captions/layers — everything else keeps its own real timing, only the
                      final result speeds up or slows down.
                    </p>
                  </div>

                  <div className="rounded-xl bg-surface-elevated px-3.5 py-3">
                    <Label>Volume ({Math.round(form.volume * 100)}%)</Label>
                    <input
                      type="range"
                      min={0}
                      max={2}
                      step={0.05}
                      value={form.volume}
                      onChange={(e) => setForm({ ...form, volume: Number(e.target.value) })}
                      className="mt-2.5 w-full accent-accent"
                    />
                    <p className="mt-1 text-[11px] text-muted">
                      Only this clip&apos;s own audio — background-audio layers keep their own separate volume.
                    </p>
                  </div>

                  <p className="text-[11px] text-muted">
                    Need background music or a separate audio track? Add an &quot;Background audio&quot; layer under
                    the <span className="text-foreground">Text &amp; Layers</span> tab — it has its own volume/fade.
                  </p>
                </div>
              )}

              {activeTab === "captions" && (
                <div className="space-y-4">
                  <div className="flex items-center justify-between rounded-xl bg-surface-elevated px-3.5 py-3">
                    <div>
                      <p className="text-sm font-medium">Auto Captions</p>
                      <p className="text-xs text-muted">Burn in AI-generated subtitles</p>
                    </div>
                    <div className="flex items-center gap-3">
                      <Select
                        value={form.subtitle_language}
                        onChange={(e) => setForm({ ...form, subtitle_language: e.target.value })}
                        className="w-auto"
                      >
                        {["en", "id", "ms", "zh", "ja", "ko", "es", "pt"].map((l) => (
                          <option key={l} value={l}>
                            {l.toUpperCase()}
                          </option>
                        ))}
                      </Select>
                      <input
                        type="checkbox"
                        checked={form.subtitles_enabled}
                        onChange={(e) => setForm({ ...form, subtitles_enabled: e.target.checked })}
                        className="size-4 rounded accent-accent"
                      />
                    </div>
                  </div>

                  <div className="rounded-xl bg-surface-elevated px-3.5 py-3">
                    <div className="flex items-center justify-between">
                      <div>
                        <p className="text-sm font-medium">Custom Captions</p>
                        <p className="text-xs text-muted">
                          {clip.custom_subtitle_format
                            ? clip.custom_subtitle_format === "ass"
                              ? "Using your .ass file's own style"
                              : "Using your .srt, styled by the template above"
                            : "Upload a .srt or .ass to override auto-generated captions"}
                        </p>
                      </div>
                      {clip.custom_subtitle_format ? (
                        <Button variant="outline" size="sm" onClick={handleRemoveSubtitle} loading={uploadingSubtitle}>
                          <X className="size-3.5" />
                          Remove
                        </Button>
                      ) : (
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => subtitleInputRef.current?.click()}
                          loading={uploadingSubtitle}
                        >
                          <Upload className="size-3.5" />
                          Upload
                        </Button>
                      )}
                    </div>
                    <input
                      ref={subtitleInputRef}
                      type="file"
                      accept=".srt,.ass"
                      className="hidden"
                      onChange={(e) => {
                        const file = e.target.files?.[0];
                        if (file) void handleUploadSubtitle(file);
                      }}
                    />
                  </div>
                </div>
              )}

              {activeTab === "details" && (
                <div className="space-y-4">
                  <div>
                    <Label htmlFor="title">Title</Label>
                    <Input
                      id="title"
                      value={form.title}
                      onChange={(e) => setForm({ ...form, title: e.target.value })}
                    />
                  </div>
                  <div>
                    <Label htmlFor="caption">Caption</Label>
                    <Textarea
                      id="caption"
                      rows={3}
                      value={form.caption}
                      onChange={(e) => setForm({ ...form, caption: e.target.value })}
                    />
                  </div>
                  <div>
                    <Label htmlFor="hashtags">Hashtags (comma separated)</Label>
                    <Input
                      id="hashtags"
                      value={form.hashtags}
                      onChange={(e) => setForm({ ...form, hashtags: e.target.value })}
                    />
                  </div>

                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <Label htmlFor="aspect">Aspect Ratio</Label>
                      <Select
                        id="aspect"
                        value={form.aspect_ratio}
                        onChange={(e) => setForm({ ...form, aspect_ratio: e.target.value })}
                      >
                        <option value="9:16">9:16 — Shorts/Reels/TikTok</option>
                        <option value="1:1">1:1 — Square</option>
                        <option value="16:9">16:9 — Landscape</option>
                      </Select>
                    </div>
                    <div>
                      <Label htmlFor="template">Template</Label>
                      <Select
                        id="template"
                        value={form.template_id}
                        onChange={(e) => setForm({ ...form, template_id: e.target.value })}
                      >
                        <option value="">No template</option>
                        {templatesRes?.data.map((t) => (
                          <option key={t.id} value={t.id}>
                            {t.name}
                          </option>
                        ))}
                      </Select>
                    </div>
                  </div>
                </div>
              )}

              {activeTab === "reaction" && (
                <ReactionIntroPanel
                  clipId={clipId}
                  reactionScript={form.reaction_script}
                  reactionTone={clip.reaction_tone}
                  introEnabled={form.intro_enabled}
                  outroEnabled={form.outro_enabled}
                  introVoice={form.intro_voice}
                  referenceUrl={form.reference_url}
                  onChange={(patch) => setForm((f) => (f ? { ...f, ...patch } : f))}
                />
              )}

              {activeTab === "publish" && (
                <PublishPanel
                  clipId={clipId}
                  clipReady={clip.status === "completed"}
                  onUseCaption={(text) => setForm((f) => (f ? { ...f, caption: text } : f))}
                />
              )}
            </div>
          </Card>

          <Button className="w-full" onClick={handleSave} loading={saving}>
            <Save className="size-4" />
            Save & Re-render
          </Button>
        </div>
      </div>

      <ReactionRecorderModal
        open={reacting}
        onClose={() => setReacting(false)}
        startTime={envelopeStart}
        endTime={envelopeEnd}
        sourceVideoUrl={videoRes?.data.url ?? null}
        submitUrl={`/clips/${clip.id}/reaction`}
        onSuccess={() => mutate(clipKey)}
      />
    </div>
  );
}
