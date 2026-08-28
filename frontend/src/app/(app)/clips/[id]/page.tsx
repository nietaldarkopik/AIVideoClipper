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
  } | null>(null);
  const [saving, setSaving] = useState(false);
  const [splitting, setSplitting] = useState(false);
  const [currentTime, setCurrentTime] = useState(0);
  const videoRef = useRef<HTMLVideoElement>(null);

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

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="lg:col-span-1">
          <div className="sticky top-6 mx-auto max-w-xs">
            <p className="mb-2 text-center text-xs text-muted">Rendered output</p>
            <div className="aspect-[9/16] w-full overflow-hidden rounded-2xl bg-black">
              {clip.status === "completed" && clip.url ? (
                <video src={clip.url} controls poster={clip.thumbnail_url ?? undefined} className="h-full w-full" />
              ) : (
                <div className="flex h-full flex-col items-center justify-center gap-3 p-6 text-center">
                  {isActive ? (
                    <>
                      <p className="text-sm text-muted">Rendering clip...</p>
                      <ProgressBar value={clip.progress ?? 0} className="w-full" />
                      <p className="text-xs text-muted">{clip.progress ?? 0}%</p>
                    </>
                  ) : clip.status === "failed" ? (
                    <p className="text-sm text-danger">{clip.failure_reason ?? "Render failed"}</p>
                  ) : (
                    <p className="text-sm text-muted">Not rendered yet</p>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>

        <div className="space-y-6 lg:col-span-2">
          <Card className="p-5">
            <div className="mb-4 flex items-center justify-between">
              <h3 className="text-sm font-semibold">Trim & Layers</h3>
              <div className="flex items-center gap-1.5">
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
                {history.current.segments.length <= 1 && (
                  <Button variant="outline" size="sm" onClick={handleSplit} loading={splitting}>
                    <Scissors className="size-3.5" />
                    Split at playhead
                  </Button>
                )}
              </div>
            </div>

            {videoRes?.data && preview && historyReady ? (
              <div className="space-y-4">
                <div className="mx-auto max-w-xs">
                  <ClipVideoPreview
                    videoRef={videoRef}
                    videoUrl={videoRes.data.url}
                    posterUrl={videoRes.data.thumbnail_url}
                    resolution={preview.resolution}
                    layers={history.current.layers}
                    currentTime={currentTime}
                    duration={videoRes.data.duration_seconds ?? 0}
                    onTimeUpdate={setCurrentTime}
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
                />
                <p className="text-[11px] text-muted">
                  Multiple segments are cut and stitched together into one clip (jump cuts) — drag the handles to
                  trim, or add another segment to pull in a second part of the source video.
                </p>

                <div className="border-t border-border-subtle pt-4">
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
                  ) : videoRes.data.width && videoRes.data.height && history.current.cropKeyframe ? (
                    <ManualCropEditor
                      videoUrl={videoRes.data.url}
                      posterUrl={videoRes.data.thumbnail_url}
                      sourceWidth={videoRes.data.width}
                      sourceHeight={videoRes.data.height}
                      targetAspect={preview.resolution}
                      keyframe={history.current.cropKeyframe}
                      onChange={(cropKeyframe) => history.push({ ...history.current, cropKeyframe })}
                    />
                  ) : (
                    <p className="text-xs text-muted">Source video dimensions aren&apos;t known yet.</p>
                  )}
                </div>

                <div className="border-t border-border-subtle pt-4">
                  <h4 className="mb-3 text-xs font-semibold text-muted">Layers</h4>
                  <LayerEditor
                    layers={history.current.layers}
                    onChange={(layers) => history.push({ ...history.current, layers })}
                    overriddenIds={overriddenIds}
                  />
                </div>
              </div>
            ) : (
              <Skeleton className="h-48" />
            )}
          </Card>

          <Card className="p-5">
            <h3 className="mb-4 text-sm font-semibold">Clip Details</h3>
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

              <div className="rounded-xl bg-surface-elevated px-3.5 py-3 text-xs text-muted">
                Trim: {formatDuration(envelopeStart)} – {formatDuration(envelopeEnd)}
                {history.current.segments.length > 1 && ` across ${history.current.segments.length} segments`} — drag
                the timeline above to adjust.
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

              <Button className="w-full" onClick={handleSave} loading={saving}>
                <Save className="size-4" />
                Save & Re-render
              </Button>
            </div>
          </Card>

          <Card className="p-5">
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
          </Card>

          <Card className="p-5">
            <PublishPanel
              clipId={clipId}
              clipReady={clip.status === "completed"}
              onUseCaption={(text) => setForm((f) => (f ? { ...f, caption: text } : f))}
            />
          </Card>
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
