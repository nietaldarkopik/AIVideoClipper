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
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { ProgressBar } from "@/components/ui/ProgressBar";
import { Skeleton } from "@/components/ui/Skeleton";
import { PublishPanel } from "@/components/clips/PublishPanel";
import { CoverPanel } from "@/components/clips/CoverPanel";
import { ReactionIntroPanel } from "@/components/clips/ReactionIntroPanel";
import { ReactionRecorderModal } from "@/components/reactions/ReactionRecorderModal";
import { MultiTrackTimeline } from "@/components/clips/timeline/MultiTrackTimeline";
import { EditorIconRail, type EditorTabKey } from "@/components/clips/timeline/EditorIconRail";
import { ClipVideoPreview } from "@/components/clips/timeline/ClipVideoPreview";
import { StickerPanel } from "@/components/clips/timeline/StickerPanel";
import { AdditionalClipsPanel } from "@/components/clips/timeline/AdditionalClipsPanel";
import { ManualCropEditor } from "@/components/clips/timeline/ManualCropEditor";
import { useClipEditorHistory, type EditorSnapshot } from "@/components/clips/timeline/useClipEditorHistory";
import { clipDuration, sourceTimeToClip } from "@/components/clips/timeline/timelineMath";
import { LayerEditor } from "@/components/layers/LayerEditor";
import { LayerPropsFields } from "@/components/layers/LayerPropsFields";
import { diffLayers } from "@/lib/layerOverrides";
import { newLayer } from "@/lib/layers";
import { EFFECTS, FILTERS } from "@/lib/videoFx";
import { formatDuration } from "@/lib/format";
import type {
  Clip,
  CropKeyframe,
  EditorSelection,
  EffectLayerProps,
  FilterLayerProps,
  LayerType,
  Project,
  Segment,
  SubtitleCue,
  Template,
  TemplateLayer,
  TransitionIn,
  TransitionType,
  Video,
} from "@/lib/types";

const ACTIVE = ["queued", "rendering"];
const ASPECT_RATIOS: Record<string, { width: number; height: number }> = {
  "9:16": { width: 1080, height: 1920 },
  "1:1": { width: 1080, height: 1080 },
  "16:9": { width: 1920, height: 1080 },
};

type TabKey = EditorTabKey;

// Which layer types each inspector panel can edit. More than one panel can
// legitimately handle the same type — a sticker and a logo are both image
// layers — which is exactly why select() checks membership before switching.
const TAB_LAYER_TYPES: Partial<Record<TabKey, LayerType[]>> = {
  layers: ["text", "image", "logo", "rect", "progress_bar"],
  stickers: ["image", "logo"],
  audio: ["audio"],
  effects: ["effect"],
  filters: ["filter"],
};

// Where selecting a layer lands when the open panel can't edit it.
const PREFERRED_TAB: Partial<Record<LayerType, TabKey>> = {
  effect: "effects",
  filter: "filters",
  audio: "audio",
};

interface PreviewConfig {
  resolution: { width: number; height: number };
  caption: Record<string, unknown>;
  branding: Record<string, unknown>;
  layers: TemplateLayer[];
  template_layers: TemplateLayer[];
  subtitle_cues: SubtitleCue[];
  caption_cues_edited: boolean;
}

// Selecting a block on the timeline (or a layer on the video preview canvas)
// drops straight into that layer's property fields — an inspector, matching
// how the timeline itself is now the primary way to browse "what's there";
// the full reorderable list (LayerEditor) is the fallback when nothing's
// selected, still useful for z-index reordering and a bulk overview.
function LayersPanel({
  layers,
  onChange,
  overriddenIds,
  selectedId,
  onSelectChange,
}: {
  layers: TemplateLayer[];
  onChange: (layers: TemplateLayer[]) => void;
  overriddenIds?: Set<string>;
  selectedId: string | null;
  onSelectChange: (id: string | null) => void;
}) {
  const selected = layers.find((l) => l.id === selectedId) ?? null;
  if (!selected) {
    // Effects and filters are layers too, but they belong to their own rail
    // sections — listing them here as well would give the same object two
    // different homes.
    const overlays = layers.filter((l) => l.type !== "effect" && l.type !== "filter");
    return (
      <LayerEditor
        layers={overlays}
        onChange={(next) => onChange([...next, ...layers.filter((l) => l.type === "effect" || l.type === "filter")])}
        overriddenIds={overriddenIds}
        selectedId={selectedId}
        onSelectChange={onSelectChange}
      />
    );
  }

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <button
          type="button"
          onClick={() => onSelectChange(null)}
          className="flex items-center gap-1 text-xs text-muted hover:text-foreground cursor-pointer"
        >
          <ArrowLeft className="size-3" />
          All elements
        </button>
        {overriddenIds?.has(selected.id) && <Badge tone="accent">Overridden</Badge>}
      </div>
      <LayerPropsFields
        layer={selected}
        onChange={(patch) => onChange(layers.map((l) => (l.id === selected.id ? { ...l, ...patch } : l)))}
      />
    </div>
  );
}

// Effects and filters get their own rail sections because that's how a user
// looks for them ("what can I do to this shot?"), but they're ordinary layers
// underneath — the same add/select/edit flow as everything else, and the same
// LayerPropsFields inspector.
function FxPanel({
  kind,
  layers,
  onChange,
  selectedId,
  onSelect,
  addAt,
  outputDuration,
}: {
  kind: "effect" | "filter";
  layers: TemplateLayer[];
  onChange: (layers: TemplateLayer[]) => void;
  selectedId: string | null;
  onSelect: (id: string | null) => void;
  // Playhead position on the output's timeline — where a new effect starts.
  addAt: number;
  outputDuration: number;
}) {
  const mine = layers.filter((l) => l.type === kind);
  const selected = mine.find((l) => l.id === selectedId) ?? null;
  const presets = kind === "filter" ? FILTERS.map((f) => ({ value: f.value, label: f.label })) : EFFECTS;

  function add(value: string) {
    const maxZ = layers.reduce((m, l) => Math.max(m, l.z_index), 0);
    const layer = newLayer(
      kind,
      maxZ + 1,
      kind === "filter" ? 0 : Number(addAt.toFixed(2)),
      // A grade covers the whole clip by default; a spot effect is a moment.
      kind === "filter" ? null : Number(Math.min(outputDuration, addAt + 3).toFixed(2))
    );
    layer.props = kind === "filter" ? { preset: value, intensity: 1 } : { effect: value, intensity: 0.6 };
    onChange([...layers, layer]);
    onSelect(layer.id);
  }

  if (selected) {
    return (
      <div className="space-y-3">
        <button
          type="button"
          onClick={() => onSelect(null)}
          className="flex cursor-pointer items-center gap-1 text-xs text-muted hover:text-foreground"
        >
          <ArrowLeft className="size-3" />
          All {kind === "filter" ? "filters" : "effects"}
        </button>
        <LayerPropsFields
          layer={selected}
          onChange={(patch) => onChange(layers.map((l) => (l.id === selected.id ? { ...l, ...patch } : l)))}
        />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div>
        <h4 className="mb-2 text-xs font-semibold text-muted">
          Add {kind === "filter" ? "a filter" : "an effect"}
        </h4>
        <div className="grid grid-cols-2 gap-2">
          {presets.map((preset) => (
            <button
              key={preset.value}
              type="button"
              onClick={() => add(preset.value)}
              className="cursor-pointer rounded-xl border border-border-subtle bg-surface-elevated px-3 py-2.5 text-left text-xs hover:border-accent"
            >
              {preset.label}
            </button>
          ))}
        </div>
      </div>

      {mine.length > 0 && (
        <div>
          <h4 className="mb-2 text-xs font-semibold text-muted">On this clip</h4>
          <div className="space-y-1.5">
            {mine.map((layer) => (
              <button
                key={layer.id}
                type="button"
                onClick={() => onSelect(layer.id)}
                className="flex w-full cursor-pointer items-center justify-between rounded-xl bg-surface-elevated px-3 py-2 text-left text-xs hover:bg-white/5"
              >
                <span>
                  {kind === "filter"
                    ? FILTERS.find((f) => f.value === ((layer.props as FilterLayerProps)?.preset ?? "normal"))?.label
                    : EFFECTS.find((f) => f.value === ((layer.props as EffectLayerProps)?.effect ?? "blur"))?.label}
                </span>
                <span className="text-muted">
                  {formatDuration(layer.timing?.start ?? 0)} – {formatDuration(layer.timing?.end ?? outputDuration)}
                </span>
              </button>
            ))}
          </div>
        </div>
      )}

      <p className="text-[11px] text-muted">
        {kind === "filter"
          ? "A filter grades the whole clip by default — shorten its block on the timeline to grade only part of it."
          : "Effects are timed: drag the block on the timeline to move it, or grab its edges to change how long it runs."}{" "}
        Both are burned into the exported video, not just the preview.
      </p>
    </div>
  );
}

// The caption cue selected on the timeline, edited in place. Cue timings are
// clip-relative (0 = the start of the rendered output), matching what the
// backend burns in — the timeline handles the conversion to source-video time.
function CaptionCueFields({
  cue,
  onChange,
  outputDuration,
}: {
  cue: SubtitleCue;
  onChange: (cue: SubtitleCue) => void;
  outputDuration: number;
}) {
  return (
    <div className="space-y-3 rounded-xl bg-surface-elevated px-3.5 py-3">
      <div>
        <Label htmlFor="cue-text">Caption text</Label>
        <Textarea
          id="cue-text"
          rows={2}
          value={cue.text}
          onChange={(e) =>
            onChange({
              ...cue,
              text: e.target.value,
              // Per-word timings describe the ORIGINAL words; once the line has
              // been rewritten they'd highlight the wrong things, so they're
              // dropped and the cue renders as a plain styled line instead
              // (SubtitleService::toAss() already handles a wordless cue).
              words: e.target.value === cue.text ? cue.words : [],
            })
          }
        />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label>Start (s)</Label>
          <Input
            type="number"
            min={0}
            step={0.05}
            value={cue.start}
            onChange={(e) => onChange({ ...cue, start: Math.max(0, Number(e.target.value)) })}
          />
        </div>
        <div>
          <Label>End (s)</Label>
          <Input
            type="number"
            min={0}
            step={0.05}
            value={cue.end}
            onChange={(e) => onChange({ ...cue, end: Math.min(outputDuration, Number(e.target.value)) })}
          />
        </div>
      </div>
      {(cue.words?.length ?? 0) > 0 && (
        <p className="text-[11px] text-muted">
          Word-level timing preserved ({cue.words.length} words) — per-word highlighting still works on this line.
        </p>
      )}
    </div>
  );
}

const TRANSITION_TYPES: { value: TransitionType; label: string; hint: string }[] = [
  { value: "fade", label: "Fade", hint: "A smooth linear cross-blend between the two cuts." },
  { value: "dissolve", label: "Dissolve", hint: "A grainy, randomized pixel dissolve — a little more textured than Fade." },
];

// The crossfade at one boundary between two of the clip's own segments — see
// FFmpegService::extractWithoutSilence()'s $transitions param for how this is
// actually rendered, and the video track's Shuffle-icon marker for how it's
// selected. `segment` is segments[index] (the one being transitioned INTO);
// `previousSegment` is only used to show what the transition connects.
function TransitionFields({
  segment,
  onChange,
}: {
  segment: Segment;
  onChange: (transition: TransitionIn | null) => void;
}) {
  const active = segment.transition_in;
  const type = active?.type ?? "none";

  return (
    <div className="space-y-4">
      <div>
        <Label>Type</Label>
        <div className="mt-1.5 grid grid-cols-3 gap-1.5">
          {(["none", ...TRANSITION_TYPES.map((t) => t.value)] as const).map((value) => (
            <button
              key={value}
              type="button"
              onClick={() => onChange(value === "none" ? null : { type: value, duration: active?.duration ?? 0.4 })}
              className={
                "rounded-lg px-2.5 py-1.5 text-xs capitalize cursor-pointer " +
                (type === value ? "bg-accent text-white" : "bg-surface-elevated text-muted hover:text-foreground")
              }
            >
              {value === "none" ? "None" : TRANSITION_TYPES.find((t) => t.value === value)?.label}
            </button>
          ))}
        </div>
        {active && (
          <p className="mt-1.5 text-[11px] text-muted">{TRANSITION_TYPES.find((t) => t.value === active.type)?.hint}</p>
        )}
      </div>

      {active && (
        <div>
          <Label>Duration ({active.duration.toFixed(2)}s)</Label>
          <input
            type="range"
            min={0.1}
            max={2}
            step={0.05}
            value={active.duration}
            onChange={(e) => onChange({ type: active.type, duration: Number(e.target.value) })}
            className="mt-2.5 w-full accent-accent"
          />
          <p className="mt-1.5 text-[11px] text-muted">
            The two cuts overlap for this long — automatically shortened if either segment is too short to fit it.
          </p>
        </div>
      )}

      {!active && (
        <p className="text-[11px] text-muted">
          No transition — a hard cut, exactly as before this feature. Pick a type above to blend into this segment
          instead of cutting straight to it.
        </p>
      )}
    </div>
  );
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
  // Every video in the project, for the "additional video clips" picker — see
  // AdditionalClipsPanel. GET /projects/{id} is the one endpoint that exposes
  // the full list (ProjectResource's 'videos' key), not just the latest.
  const { data: projectRes } = useApi<{ data: Project }>(clip ? `/projects/${clip.project_id}` : null);
  const projectVideos = projectRes?.data.videos ?? [];
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
    cover_template_id: number | null;
    cover_text: string | null;
    cover_kicker: string | null;
    cover_subline: string | null;
  } | null>(null);
  const [saving, setSaving] = useState(false);
  const [splitting, setSplitting] = useState(false);
  const [currentTime, setCurrentTime] = useState(0);
  // One selection for the whole editor — a timeline block, a caption cue or a
  // video segment. See EditorSelection: keeping these in one state is what lets
  // the timeline, the preview and the inspector never disagree about what's
  // being edited.
  const [selection, setSelection] = useState<EditorSelection>(null);
  // Local rather than part of EditorSelection — AdditionalClipsPanel is a
  // self-contained list UI, not drawn on the interactive timeline/preview the
  // way layers/captions/segments/transitions are, so it has no need to
  // cross-highlight with anything else in the editor.
  const [selectedAdditionalClipIndex, setSelectedAdditionalClipIndex] = useState<number | null>(null);
  const selectedLayerId = selection?.kind === "layer" ? selection.id : null;
  const [uploadingSubtitle, setUploadingSubtitle] = useState(false);
  const [activeTab, setActiveTab] = useState<TabKey>("trim");
  const [isPlaying, setIsPlaying] = useState(false);
  const videoRef = useRef<HTMLVideoElement>(null);
  const subtitleInputRef = useRef<HTMLInputElement>(null);

  const history = useClipEditorHistory({
    segments: [],
    additionalVideoClips: [],
    layers: [],
    captionCues: [],
    captionCuesDirty: false,
    cropMode: "smart",
    cropKeyframe: null,
  });
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
        cover_template_id: clip.cover_template_id ?? null,
        cover_text: clip.cover_text ?? null,
        cover_kicker: clip.cover_kicker ?? null,
        cover_subline: clip.cover_subline ?? null,
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
      history.reset({
        segments,
        additionalVideoClips: clip.additional_video_clips ?? [],
        layers: preview.layers,
        captionCues: preview.subtitle_cues ?? [],
        // Already-saved edits stay "dirty" so every subsequent save re-persists
        // them — otherwise a later save that happened to omit caption_cues would
        // leave the clip's stored cues and the editor's view of them to drift.
        captionCuesDirty: preview.caption_cues_edited,
        cropMode,
        cropKeyframe,
      });
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
  // Length of what actually renders (sum of the kept segments), and where the
  // playhead sits on that output timeline — layer timings and caption cues are
  // both stored against it, so this is what decides what's on screen.
  const outputDuration = clipDuration(history.current.segments);
  const clipTime = sourceTimeToClip(currentTime, history.current.segments);
  const selectedLayer = selectedLayerId ? history.current.layers.find((l) => l.id === selectedLayerId) ?? null : null;

  function setLayers(layers: TemplateLayer[]) {
    history.push({ ...history.current, layers });
  }

  function setCaptionCues(captionCues: SubtitleCue[]) {
    history.push({ ...history.current, captionCues, captionCuesDirty: true });
  }

  // Selecting something anywhere — timeline block, preview overlay, panel list —
  // also opens the panel that edits it, so the inspector always reflects the
  // selection. Done here rather than in an effect so the tab switch is part of
  // the same update as the selection itself; the user can still change tabs
  // freely afterwards without it snapping back.
  // `addedLayer` covers the just-created case: a layer added this tick isn't in
  // history.current yet, so there'd be nothing to look up and the panel would
  // lag one interaction behind.
  function select(next: EditorSelection, addedLayer?: TemplateLayer) {
    setSelection(next);
    if (!next) return;
    if (next.kind === "segment" || next.kind === "transition") return setActiveTab("trim");
    if (next.kind === "caption") return setActiveTab("captions");
    const layer = addedLayer ?? history.current.layers.find((l) => l.id === next.id);
    if (!layer) return;
    // Staying put when the open panel can already edit this layer is what keeps
    // "add a sticker" from bouncing the user out of the Stickers panel and into
    // Elements — a sticker IS an image layer, so both panels legitimately handle
    // it, and the one the user is already in wins.
    if (TAB_LAYER_TYPES[activeTab]?.includes(layer.type)) return;
    setActiveTab(PREFERRED_TAB[layer.type] ?? "layers");
  }

  function selectLayer(id: string | null) {
    select(id ? { kind: "layer", id } : null);
  }

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
      additional_video_clips: snapshot.additionalVideoClips.length > 0 ? snapshot.additionalVideoClips : null,
      aspect_ratio: form.aspect_ratio,
      template_id: form.template_id ? Number(form.template_id) : null,
      cover_template_id: form.cover_template_id,
      cover_text: form.cover_text,
      cover_kicker: form.cover_kicker,
      cover_subline: form.cover_subline,
      subtitles_enabled: form.subtitles_enabled,
      subtitle_language: form.subtitle_language,
      layer_overrides: layerOverrides,
      // Only sent once the user has actually edited a caption — see
      // EditorSnapshot.captionCuesDirty. Sending it on every save would opt
      // every clip out of transcript-driven captions permanently.
      ...(snapshot.captionCuesDirty ? { caption_cues: snapshot.captionCues } : {}),
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

  // Hands captions back to the transcript pipeline: clears the stored cues and
  // reseeds the editor from whatever the next render generates.
  async function handleResetCaptions() {
    if (!confirm("Discard your caption edits and go back to auto-generated captions on the next render?")) return;
    try {
      await api.patch(clipKey, { caption_cues: null });
      history.push({ ...history.current, captionCuesDirty: false });
      await mutate(clipKey);
      await mutate(`${clipKey}/preview-config`);
      toast("Captions reset to auto — re-rendering.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to reset captions.", "danger");
    }
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
                  resolution={
                    form.template_id && templatesRes?.data
                      ? (() => {
                          const tpl = templatesRes.data.find((t) => String(t.id) === form.template_id);
                          return tpl?.resolution ?? preview.resolution;
                        })()
                      : preview.resolution
                  }
                  captionConfig={
                    form.template_id && templatesRes?.data
                      ? templatesRes.data.find((t) => String(t.id) === form.template_id)?.current_version?.config?.caption ?? preview.caption
                      : preview.caption
                  }
                  layers={history.current.layers}
                  clipTime={clipTime}
                  duration={outputDuration}
                  captionCues={history.current.captionCues}
                  activeCaptionIndex={selection?.kind === "caption" ? selection.index : null}
                  onTimeUpdate={setCurrentTime}
                  interactive
                  selectedLayerId={selectedLayerId}
                  onSelectLayer={selectLayer}
                  onLayersChange={setLayers}
                  segments={history.current.segments}
                  isPlaying={isPlaying}
                />
              </div>

              <MultiTrackTimeline
                duration={videoRes.data.duration_seconds ?? 0}
                segments={history.current.segments}
                onSegmentsChange={(segments) => history.push({ ...history.current, segments })}
                layers={history.current.layers}
                onLayersChange={setLayers}
                captionCues={history.current.captionCues}
                onCaptionCuesChange={setCaptionCues}
                selection={selection}
                onSelectionChange={select}
                currentTime={currentTime}
                onSeek={(t) => {
                  setCurrentTime(t);
                  if (videoRef.current) videoRef.current.currentTime = t;
                }}
                thumbnailStripUrl={videoRes.data.thumbnail_strip_url}
                waveformUrl={videoRes.data.waveform_url}
              />
              <p className="text-[11px] text-muted">
                Multiple segments are cut and stitched together into one clip (jump cuts) — drag the video track&apos;s
                handles to trim, or add another segment to pull in a second part of the source video. Drag any block
                to change when it appears, grab its edges to change how long it lasts, and select it to edit its
                properties on the right.
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

          <Card className="flex overflow-hidden p-0">
            <EditorIconRail active={activeTab} onChange={setActiveTab} />

            <div className="min-w-0 flex-1 space-y-4 p-5">
              {activeTab === "trim" && selection?.kind === "transition" && history.current.segments[selection.index] && (
                <div className="space-y-3">
                  <button
                    type="button"
                    onClick={() => setSelection(null)}
                    className="flex cursor-pointer items-center gap-1 text-xs text-muted hover:text-foreground"
                  >
                    <ArrowLeft className="size-3" />
                    Back to crop
                  </button>
                  <TransitionFields
                    segment={history.current.segments[selection.index]}
                    onChange={(transition_in) =>
                      history.push({
                        ...history.current,
                        segments: history.current.segments.map((s, i) =>
                          i === selection.index ? { ...s, transition_in } : s
                        ),
                      })
                    }
                  />
                </div>
              )}

              {activeTab === "trim" && selection?.kind !== "transition" && (
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
                <LayersPanel
                  layers={history.current.layers}
                  onChange={setLayers}
                  overriddenIds={overriddenIds}
                  selectedId={selectedLayerId}
                  onSelectChange={selectLayer}
                />
              )}

              {activeTab === "stickers" && (
                <StickerPanel
                  layers={history.current.layers}
                  onChange={setLayers}
                  selectedId={selectedLayerId}
                  onSelect={(id, added) => select(id ? { kind: "layer", id } : null, added)}
                  addAt={clipTime}
                  outputDuration={outputDuration}
                />
              )}

              {activeTab === "videoClips" && (
                <AdditionalClipsPanel
                  projectVideos={projectVideos}
                  clips={history.current.additionalVideoClips}
                  onChange={(additionalVideoClips) => history.push({ ...history.current, additionalVideoClips })}
                  selectedIndex={selectedAdditionalClipIndex}
                  onSelect={setSelectedAdditionalClipIndex}
                />
              )}

              {(activeTab === "effects" || activeTab === "filters") && (
                <FxPanel
                  kind={activeTab === "effects" ? "effect" : "filter"}
                  layers={history.current.layers}
                  onChange={setLayers}
                  selectedId={selectedLayer?.type === (activeTab === "effects" ? "effect" : "filter") ? selectedLayer.id : null}
                  onSelect={selectLayer}
                  addAt={clipTime}
                  outputDuration={outputDuration}
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
                    Need background music or a separate audio track? Add it from the Audio section of the timeline
                    below, or under the <span className="text-foreground">Elements</span> tab — it has its own
                    volume/fade.
                  </p>
                </div>
              )}

              {activeTab === "captions" && (
                <div className="space-y-4">
                  {selection?.kind === "caption" && history.current.captionCues[selection.index] ? (
                    <div className="space-y-2">
                      <button
                        type="button"
                        onClick={() => setSelection(null)}
                        className="flex cursor-pointer items-center gap-1 text-xs text-muted hover:text-foreground"
                      >
                        <ArrowLeft className="size-3" />
                        All caption settings
                      </button>
                      <CaptionCueFields
                        cue={history.current.captionCues[selection.index]}
                        outputDuration={outputDuration}
                        onChange={(cue) =>
                          setCaptionCues(history.current.captionCues.map((c, i) => (i === selection.index ? cue : c)))
                        }
                      />
                    </div>
                  ) : (
                    <div className="rounded-xl bg-surface-elevated px-3.5 py-3">
                      <div className="flex items-center justify-between">
                        <div>
                          <p className="text-sm font-medium">Caption cues</p>
                          <p className="text-xs text-muted">
                            {history.current.captionCues.length === 0
                              ? "Available once the clip has rendered at least once."
                              : history.current.captionCuesDirty
                                ? `${history.current.captionCues.length} cues — edited, burned in as-is on the next render.`
                                : `${history.current.captionCues.length} cues — click one on the timeline to edit it.`}
                          </p>
                        </div>
                        {history.current.captionCuesDirty && (
                          <Button variant="outline" size="sm" onClick={handleResetCaptions}>
                            Reset to auto
                          </Button>
                        )}
                      </div>
                      {history.current.captionCuesDirty && (
                        <p className="mt-2 text-[11px] text-muted">
                          Edited captions are kept exactly as timed, so automatic silence removal is skipped for this
                          clip — otherwise shortening the video underneath would desync every line.
                        </p>
                      )}
                    </div>
                  )}

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
                        onChange={(e) => {
                          const newAspectRatio = e.target.value;
                          // Just a FILTER on which templates are selectable below —
                          // render dimensions come from the chosen template, not
                          // this field, once one is attached (see the Template
                          // select's onChange). Changing it clears the current
                          // template selection since it's no longer in the filtered
                          // list, rather than silently leaving a mismatched pair.
                          const currentTpl = templatesRes?.data.find((t) => String(t.id) === form.template_id);
                          setForm({
                            ...form,
                            aspect_ratio: newAspectRatio,
                            template_id: currentTpl?.aspect_ratio === newAspectRatio ? form.template_id : "",
                          });
                        }}
                      >
                        <option value="9:16">9:16 — Shorts/Reels/TikTok</option>
                        <option value="1:1">1:1 — Square</option>
                        <option value="16:9">16:9 — Landscape</option>
                      </Select>
                      <p className="mt-1 text-xs text-muted">Filters which templates are available.</p>
                    </div>
                    <div>
                      <Label htmlFor="template">Template</Label>
                      <Select
                        id="template"
                        value={form.template_id}
                        onChange={(e) => {
                          const newTemplateId = e.target.value;
                          const selectedTpl = newTemplateId
                            ? templatesRes?.data.find((t) => String(t.id) === newTemplateId)
                            : undefined;
                          // The template is authoritative for render dimensions once
                          // attached — keep aspect_ratio in sync with it rather than
                          // letting the two fields disagree (that mismatch is what
                          // caused the crop-then-stretch distortion on render).
                          setForm({
                            ...form,
                            template_id: newTemplateId,
                            aspect_ratio: selectedTpl?.aspect_ratio ?? form.aspect_ratio,
                          });

                          if (newTemplateId) {
                            if (selectedTpl?.current_version?.config) {
                              const cfg = selectedTpl.current_version.config;
                              history.push({
                                ...history.current,
                                layers: cfg.layers ?? [],
                              });
                            } else {
                              api.get<{ data: Template }>(`/templates/${newTemplateId}`).then((res) => {
                                const cfg = res.data?.current_version?.config;
                                if (cfg) {
                                  history.push({
                                    ...history.current,
                                    layers: cfg.layers ?? [],
                                  });
                                }
                              }).catch(() => {});
                            }
                          }
                        }}
                      >
                        <option value="">No template</option>
                        {templatesRes?.data
                          .filter((t) => t.aspect_ratio === form.aspect_ratio)
                          .map((t) => (
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

              {activeTab === "cover" && (
                <CoverPanel
                  clipId={clipId}
                  clipReady={clip.status === "completed"}
                  coverTemplateId={form.cover_template_id}
                  coverText={form.cover_text}
                  coverKicker={form.cover_kicker}
                  coverSubline={form.cover_subline}
                  coverUrl={clip.cover_url}
                  titleOptions={clip.cover_title_options ?? []}
                  subtitleOptions={clip.cover_subtitle_options ?? []}
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
