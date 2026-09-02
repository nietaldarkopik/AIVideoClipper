"use client";

import { use, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { mutate } from "swr";
import { ArrowLeft, Save, History, Archive, Sparkles } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { useAuthStore } from "@/store/auth";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Input, Label, Select } from "@/components/ui/Input";
import { Badge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { LayerEditor } from "@/components/layers/LayerEditor";
import { LayerOverlay } from "@/components/clips/timeline/ClipVideoPreview";
import { CanvasSizeField, nearestAspectRatio } from "@/components/templates/CanvasSizeField";
import type { Template, TemplateConfig, TemplateLayer, VideoRegion } from "@/lib/types";

// Bundled looping placeholder clip (frontend/public/sample-preview.mp4) so the
// template builder's preview shows real moving footage — including how a zoom/
// shake effect actually reads in motion — instead of a static gradient. Content-
// neutral (an abstract animated gradient, not real footage) since there's no
// per-template source video at this stage of editing.
const SAMPLE_PREVIEW_VIDEO = "/sample-preview.mp4";

function hexToRgba(hex: string, opacity: number): string {
  const clean = hex.replace("#", "");
  const r = parseInt(clean.slice(0, 2), 16) || 0;
  const g = parseInt(clean.slice(2, 4), 16) || 0;
  const b = parseInt(clean.slice(4, 6), 16) || 0;

  return `rgba(${r}, ${g}, ${b}, ${Math.max(0, Math.min(1, opacity))})`;
}

export default function TemplateDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const templateId = Number(id);
  const key = `/templates/${templateId}`;
  const user = useAuthStore((s) => s.user);
  const isAdmin = user?.role === "admin";

  const { data, isLoading } = useApi<{ data: Template }>(key);
  const template = data?.data;

  const [resolutionWidth, setResolutionWidth] = useState(1080);
  const [resolutionHeight, setResolutionHeight] = useState(1920);
  const [caption, setCaption] = useState<NonNullable<TemplateConfig["caption"]>>({});
  const [watermarkOpacity, setWatermarkOpacity] = useState(0.8);
  const [progressBarEnabled, setProgressBarEnabled] = useState(false);
  const [layers, setLayers] = useState<TemplateLayer[]>([]);
  const [videoRegion, setVideoRegion] = useState<VideoRegion | null>(null);
  const [canvasBackgroundColor, setCanvasBackgroundColor] = useState("#000000");
  const [effectType, setEffectType] = useState<NonNullable<TemplateConfig["effects"]>["type"]>("none");
  const [effectIntensity, setEffectIntensity] = useState(0.15);
  const [transitionType, setTransitionType] = useState<NonNullable<TemplateConfig["transition"]>["type"]>("cut");
  const [transitionDuration, setTransitionDuration] = useState(0.4);
  const [saving, setSaving] = useState(false);
  const [generatingCover, setGeneratingCover] = useState(false);

  const previewVideoRef = useRef<HTMLVideoElement>(null);
  const [previewTime, setPreviewTime] = useState(0);
  const [previewDuration, setPreviewDuration] = useState(6);

  useEffect(() => {
    if (template?.resolution) {
      setResolutionWidth(template.resolution.width);
      setResolutionHeight(template.resolution.height);
    }
    if (template?.current_version?.config) {
      const cfg = template.current_version.config;
      setCaption(cfg.caption ?? {});
      setWatermarkOpacity(cfg.branding?.watermark_opacity ?? 0.8);
      setProgressBarEnabled(!!cfg.progress_bar?.enabled);
      setLayers(cfg.layers ?? []);
      const region = cfg.video_region;
      setVideoRegion(region ? { top: region.y, height: region.height } : null);
      setCanvasBackgroundColor(cfg.canvas_background_color ?? "#000000");
      setEffectType(cfg.effects?.type ?? "none");
      setEffectIntensity(cfg.effects?.intensity ?? 0.15);
      setTransitionType(cfg.transition?.type ?? "cut");
      setTransitionDuration(cfg.transition?.duration ?? 0.4);
    }
  }, [template]);

  async function handleSave() {
    setSaving(true);
    try {
      await api.patch(`/admin/templates/${templateId}`, {
        // Plain metadata on the Template row itself — updated in place, no new
        // version needed for these two (unlike config below). aspect_ratio is
        // recomputed here too since it's the coarse bucket used for template<->
        // clip matching elsewhere; the exact canvas size is what actually
        // drives the render (see Clip::targetResolution()).
        resolution_width: resolutionWidth,
        resolution_height: resolutionHeight,
        aspect_ratio: nearestAspectRatio(resolutionWidth, resolutionHeight),
        config: {
          // Bumping to version 2 only affects the NEW version this save creates —
          // clips already pinned to an earlier template_version_id (version 1 or
          // below) are untouched, and a v2 config with an empty layers array
          // renders identically to v1 (see LayerCompositionService/RenderClipJob).
          version: 2,
          caption,
          branding: { watermark_opacity: watermarkOpacity },
          progress_bar: progressBarEnabled ? { enabled: true, color: caption.highlight_color ?? "#7c5cff" } : null,
          layers,
          // The editor only exposes a full-width vertical band (x/width always
          // 0/1) — see VideoRegion — expanded here to the general shape
          // FFmpegService::renderClip() reads.
          video_region: videoRegion ? { x: 0, y: videoRegion.top, width: 1, height: videoRegion.height } : null,
          canvas_background_color: canvasBackgroundColor,
          effects: { type: effectType, intensity: effectIntensity },
          transition: { type: transitionType, duration: transitionDuration },
        },
      });
      await mutate(key);
      toast("New template version published.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to save template.", "danger");
    } finally {
      setSaving(false);
    }
  }

  async function handleGenerateCover() {
    setGeneratingCover(true);
    try {
      await api.post(`/admin/templates/${templateId}/generate-thumbnail`);
      await mutate(key);
      toast("Cover generated.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to generate cover.", "danger");
    } finally {
      setGeneratingCover(false);
    }
  }

  async function handleArchive() {
    if (!confirm("Archive this template? Existing clips keep using it, but it will be hidden from selection.")) return;
    try {
      await api.post(`/admin/templates/${templateId}/archive`);
      await mutate(key);
      toast("Template archived.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to archive.", "danger");
    }
  }

  if (isLoading || !template) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-96" />
      </div>
    );
  }

  const backgroundEnabled = !!caption.background && (caption.background_opacity ?? 0) > 0;
  const previewBg = backgroundEnabled ? hexToRgba(caption.background!, caption.background_opacity ?? 1) : "transparent";

  // Live edited size (not the stale template.resolution) so the preview below
  // updates the instant a new Canvas Size preset/custom value is picked, same
  // as every other field on this page.
  //
  // Mirrors SubtitleService::toAss()'s auto-size formula exactly, so "Auto" in
  // the preview always matches what actually gets burned into the rendered
  // clip: max(36, videoHeight / 20).
  const resolution = { width: resolutionWidth, height: resolutionHeight };
  const autoFontSize = Math.max(36, Math.round(resolution.height / 20));
  const effectiveFontSize = caption.font_size ?? autoFontSize;
  const PREVIEW_WIDTH_PX = 220;
  const previewScale = PREVIEW_WIDTH_PX / resolution.width;
  const previewPadding = backgroundEnabled
    ? Math.max(2, (caption.background_padding ?? 8) * previewScale)
    : 0;
  const previewAnimation =
    caption.animation === "fade"
      ? "captionPreviewFade 1.4s ease-in-out infinite"
      : caption.animation === "pop"
        ? "captionPreviewPop 1.4s ease-in-out infinite"
        : undefined;

  // Approximates FFmpegService::buildEffectFilter() (zoom in/out over the clip,
  // Ken Burns = zoom + diagonal drift, shake = small jitter) as a CSS animation
  // on the preview <video> so an effect choice actually reads as motion here
  // instead of only showing up once the clip is rendered. Timed to the sample
  // clip's own loop length so it doesn't visibly reset mid-loop.
  const effectSpan = Math.max(2, Math.min(10, previewDuration || 6));
  let effectKeyframes = "";
  let effectAnimation: string | undefined;
  if (effectType === "zoom_in" || effectType === "zoom_out" || effectType === "ken_burns") {
    const from = effectType === "zoom_out" ? 1 + effectIntensity : 1;
    const to = effectType === "zoom_out" ? 1 : 1 + effectIntensity;
    const driftX = effectType === "ken_burns" ? effectIntensity * 30 : 0;
    const driftY = effectType === "ken_burns" ? effectIntensity * 20 : 0;
    effectKeyframes = `@keyframes templatePreviewZoom { 0% { transform: scale(${from}) translate(0%, 0%); } 100% { transform: scale(${to}) translate(${driftX}%, ${driftY}%); } }`;
    effectAnimation = `templatePreviewZoom ${effectSpan}s ease-in-out infinite alternate`;
  } else if (effectType === "shake") {
    const amt = Math.max(1, effectIntensity * 24);
    effectKeyframes = `@keyframes templatePreviewShake { 0%, 100% { transform: translate(0, 0); } 25% { transform: translate(${amt}px, -${amt}px); } 50% { transform: translate(-${amt}px, ${amt}px); } 75% { transform: translate(${amt}px, ${amt}px); } }`;
    effectAnimation = "templatePreviewShake 0.45s steps(1) infinite";
  }

  // Mirrors FFmpegService::renderClip()'s split: a layer with a negative
  // z_index renders BEFORE the caption burn-in (so it can sit behind the
  // caption instead of covering it) and, since that happens before the
  // video_region inset too, scales/crops together with the video+caption —
  // rendered here inside the video-region box, at the same coordinate space
  // the caption preview already uses. Every other layer keeps rendering on
  // the outer (post-inset) canvas, on top, exactly as before.
  const sortedLayers = [...layers].sort((a, b) => (a.z_index ?? 0) - (b.z_index ?? 0));
  const behindCaptionLayers = sortedLayers.filter((l) => (l.z_index ?? 0) < 0);
  const aboveCaptionLayers = sortedLayers.filter((l) => (l.z_index ?? 0) >= 0);

  return (
    <div className="space-y-6">
      <div>
        <Link href="/templates" className="mb-3 flex items-center gap-1.5 text-xs text-muted hover:text-foreground">
          <ArrowLeft className="size-3.5" />
          Back to Templates
        </Link>
        <div className="flex items-start justify-between gap-4">
          <div className="flex items-start gap-3">
            {template.thumbnail_url && (
              <img
                src={template.thumbnail_url}
                alt=""
                className="h-14 w-14 shrink-0 rounded-lg object-cover"
              />
            )}
            <div>
              <div className="flex items-center gap-2.5">
                <h1 className="text-xl font-semibold">{template.name}</h1>
                <Badge tone="muted">{template.current_version?.label}</Badge>
                {template.is_system && <Badge tone="accent">System</Badge>}
              </div>
              <p className="mt-1 text-sm text-muted">{template.description}</p>
            </div>
          </div>
          {isAdmin && (
            <div className="flex items-center gap-2">
              <Button variant="outline" size="sm" onClick={handleGenerateCover} loading={generatingCover}>
                <Sparkles className="size-3.5" />
                {template.thumbnail_url ? "Regenerate Cover" : "Generate Cover"}
              </Button>
              <Button variant="outline" size="sm" onClick={handleArchive}>
                <Archive className="size-3.5" />
                Archive
              </Button>
              <Button onClick={handleSave} loading={saving}>
                <Save className="size-4" />
                Save as New Version
              </Button>
            </div>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-5">
        <div className="lg:col-span-2">
          <div className="sticky top-6 mx-auto max-w-[260px]">
            <p className="mb-2 text-center text-xs text-muted">
              Live Preview <span className="text-muted/70">({resolution.width}×{resolution.height})</span>
            </p>
            <div
              className="relative w-full overflow-hidden rounded-2xl"
              style={{
                aspectRatio: `${resolution.width} / ${resolution.height}`,
                background: canvasBackgroundColor,
              }}
            >
              {/* Video region — full-bleed (inset-0) unless a custom area is set below.
                  A bundled placeholder clip stands in for the eventual source video so
                  effects/crop framing read as real motion; captions/layers are drawn on
                  top at the same relative position they'd render on the final canvas
                  (see FFmpegService::renderClip()'s videoRegion step: captions burn in
                  before the region crop, so they move/scale with the video box). */}
              <div
                className="absolute overflow-hidden"
                style={{
                  left: 0,
                  width: "100%",
                  top: `${(videoRegion?.top ?? 0) * 100}%`,
                  height: `${(videoRegion?.height ?? 1) * 100}%`,
                }}
              >
                <video
                  ref={previewVideoRef}
                  src={SAMPLE_PREVIEW_VIDEO}
                  autoPlay
                  muted
                  loop
                  playsInline
                  className="h-full w-full object-cover"
                  style={{ animation: effectAnimation, transformOrigin: "center" }}
                  onTimeUpdate={(e) => setPreviewTime(e.currentTarget.currentTime)}
                  onLoadedMetadata={(e) => setPreviewDuration(e.currentTarget.duration || 6)}
                />

                {behindCaptionLayers.length > 0 && (
                  <div className="pointer-events-none absolute inset-0">
                    {behindCaptionLayers.map((layer) => (
                      <LayerOverlay key={layer.id} layer={layer} currentTime={previewTime} duration={previewDuration} />
                    ))}
                  </div>
                )}

                <div
                  className="pointer-events-none absolute inset-0 flex items-end justify-center p-[4%]"
                  style={{ textAlign: caption.position === "top" ? "left" : "center" }}
                >
                  <div
                    className="w-full"
                    style={{
                      alignSelf:
                        caption.position === "top"
                          ? "flex-start"
                          : caption.position === "center"
                            ? "center"
                            : "flex-end",
                      position: caption.position === "top" ? "absolute" : undefined,
                      top: caption.position === "top" ? "4%" : undefined,
                    }}
                  >
                    <span
                      style={{
                        fontFamily: caption.font || "Arial",
                        color: caption.color || "#fff",
                        // Matches SubtitleService::toAss(): a background box replaces the
                        // glyph stroke in the real render (ASS can't draw both at once),
                        // so the preview drops the stroke too once a box is enabled.
                        WebkitTextStroke: backgroundEnabled
                          ? "0px"
                          : `${Math.max(0.5, (caption.stroke_width ?? 3) * previewScale)}px ${caption.stroke_color || "#000"}`,
                        fontWeight: caption.bold ? 800 : 500,
                        fontStyle: caption.italic ? "italic" : "normal",
                        textTransform: caption.uppercase ? "uppercase" : "none",
                        background: previewBg,
                        padding: backgroundEnabled ? `${previewPadding}px ${previewPadding * 1.5}px` : 0,
                        borderRadius: backgroundEnabled ? 2 : 0,
                        fontSize: effectiveFontSize * previewScale,
                        lineHeight: 1.3,
                        display: "inline-block",
                        animation: previewAnimation,
                      }}
                    >
                      This is the{" "}
                      <span style={{ color: caption.highlight_color || "#FFD100", WebkitTextStroke: "0px" }}>
                        biggest
                      </span>{" "}
                      mistake
                    </span>
                  </div>
                </div>
              </div>

              {/* Non-negative-z_index layers render on the full canvas, on top of the
                  (possibly inset) video box AND the caption — same stacking order as
                  LayerCompositionService on the backend. */}
              <div className="pointer-events-none absolute inset-0">
                {aboveCaptionLayers.map((layer) => (
                  <LayerOverlay key={layer.id} layer={layer} currentTime={previewTime} duration={previewDuration} />
                ))}
              </div>

              {/* Matches RenderClipJob's translation of this legacy toggle into a
                  progress_bar layer: no position field is saved from this checkbox,
                  so LayerCompositionService::buildProgressBarLayer() defaults to
                  the bottom edge. */}
              {progressBarEnabled && (
                <div className="pointer-events-none absolute left-0 right-0 bottom-0 h-1 bg-white/20">
                  <div className="h-full w-1/3" style={{ background: caption.highlight_color || "#7c5cff" }} />
                </div>
              )}
            </div>
            <style>{`
              @keyframes captionPreviewFade {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.15; }
              }
              @keyframes captionPreviewPop {
                0%, 80%, 100% { transform: scale(1); }
                90% { transform: scale(1.12); }
              }
              ${effectKeyframes}
            `}</style>
          </div>
        </div>

        <div className="space-y-6 lg:col-span-3">
          <Card className="p-5">
            <h3 className="mb-1 text-sm font-semibold">Canvas Size</h3>
            <p className="mb-4 text-xs text-muted">
              The exact pixel size clips render at when they use this template — pick a preset or type your own.
              Existing clips already rendered under an earlier version keep their original size; this only affects
              the next render.
            </p>
            <CanvasSizeField
              width={resolutionWidth}
              height={resolutionHeight}
              disabled={!isAdmin}
              onChange={({ width, height }) => {
                setResolutionWidth(width);
                setResolutionHeight(height);
              }}
            />
            {!isAdmin && <p className="mt-4 text-xs text-muted">Only admins can edit templates.</p>}
          </Card>

          <Card className="p-5">
            <h3 className="mb-4 text-sm font-semibold">Caption Style</h3>
            <fieldset disabled={!isAdmin} className="space-y-4 disabled:opacity-60">
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <Label>Font</Label>
                  <Input value={caption.font ?? ""} onChange={(e) => setCaption({ ...caption, font: e.target.value })} />
                </div>
                <div>
                  <Label>
                    Font Size{" "}
                    {caption.font_size == null && (
                      <span className="font-normal text-muted">(Auto · {autoFontSize}px)</span>
                    )}
                  </Label>
                  <div className="flex items-center gap-1.5">
                    <Input
                      type="number"
                      min={12}
                      max={300}
                      placeholder={String(autoFontSize)}
                      value={caption.font_size ?? ""}
                      onChange={(e) =>
                        setCaption({ ...caption, font_size: e.target.value === "" ? null : Number(e.target.value) })
                      }
                    />
                    {caption.font_size != null && (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => setCaption({ ...caption, font_size: null })}
                      >
                        Auto
                      </Button>
                    )}
                  </div>
                </div>
                <div>
                  <Label>Position</Label>
                  <Select
                    value={caption.position ?? "bottom"}
                    onChange={(e) => setCaption({ ...caption, position: e.target.value as "top" | "center" | "bottom" })}
                  >
                    <option value="top">Top</option>
                    <option value="center">Center</option>
                    <option value="bottom">Bottom</option>
                  </Select>
                </div>
              </div>

              <div className="grid grid-cols-3 gap-4">
                <div>
                  <Label>Text Color</Label>
                  <Input
                    type="color"
                    value={caption.color ?? "#ffffff"}
                    onChange={(e) => setCaption({ ...caption, color: e.target.value })}
                    className="h-10 p-1"
                  />
                </div>
                <div>
                  <Label>Highlight Color</Label>
                  <Input
                    type="color"
                    value={caption.highlight_color ?? "#ffd100"}
                    onChange={(e) => setCaption({ ...caption, highlight_color: e.target.value })}
                    className="h-10 p-1"
                  />
                </div>
                <div>
                  <Label>Stroke Color</Label>
                  <Input
                    type="color"
                    value={caption.stroke_color ?? "#000000"}
                    onChange={(e) => setCaption({ ...caption, stroke_color: e.target.value })}
                    className="h-10 p-1"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Words per line</Label>
                  <Input
                    type="number"
                    min={1}
                    max={8}
                    value={caption.words_per_line ?? 3}
                    onChange={(e) => setCaption({ ...caption, words_per_line: Number(e.target.value) })}
                  />
                </div>
                <div>
                  <Label>Stroke width</Label>
                  <Input
                    type="number"
                    min={0}
                    max={10}
                    value={caption.stroke_width ?? 3}
                    onChange={(e) => setCaption({ ...caption, stroke_width: Number(e.target.value) })}
                  />
                </div>
              </div>

              <div className="grid grid-cols-3 gap-4">
                <div>
                  <Label>Background</Label>
                  <Input
                    type="color"
                    value={caption.background ?? "#000000"}
                    onChange={(e) => setCaption({ ...caption, background: e.target.value })}
                    className="h-10 p-1"
                  />
                </div>
                <div>
                  <Label>Background opacity ({Math.round((caption.background_opacity ?? 0) * 100)}%)</Label>
                  <input
                    type="range"
                    min={0}
                    max={1}
                    step={0.05}
                    value={caption.background_opacity ?? 0}
                    onChange={(e) => setCaption({ ...caption, background_opacity: Number(e.target.value) })}
                    className="mt-2.5 w-full accent-accent"
                  />
                </div>
                <div>
                  <Label>Background padding</Label>
                  <Input
                    type="number"
                    min={0}
                    max={40}
                    disabled={!backgroundEnabled}
                    value={caption.background_padding ?? 8}
                    onChange={(e) => setCaption({ ...caption, background_padding: Number(e.target.value) })}
                  />
                </div>
              </div>
              {backgroundEnabled && (
                <p className="-mt-2 text-[11px] text-muted">
                  A background box replaces the text stroke in the rendered clip (ASS captions can only draw one or
                  the other) — Stroke Color/width above are ignored while this is on.
                </p>
              )}

              <div>
                <Label>Animation</Label>
                <Select
                  value={caption.animation ?? "none"}
                  onChange={(e) => setCaption({ ...caption, animation: e.target.value as "none" | "fade" | "pop" })}
                >
                  <option value="none">None</option>
                  <option value="fade">Fade in/out</option>
                  <option value="pop">Pop in</option>
                </Select>
              </div>

              <div className="flex flex-wrap gap-4">
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={!!caption.highlight_active_word}
                    onChange={(e) => setCaption({ ...caption, highlight_active_word: e.target.checked })}
                    className="size-4 rounded accent-accent"
                  />
                  Highlight active word
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={!!caption.italic}
                    onChange={(e) => setCaption({ ...caption, italic: e.target.checked })}
                    className="size-4 rounded accent-accent"
                  />
                  Italic
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={!!caption.uppercase}
                    onChange={(e) => setCaption({ ...caption, uppercase: e.target.checked })}
                    className="size-4 rounded accent-accent"
                  />
                  Uppercase
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={!!caption.bold}
                    onChange={(e) => setCaption({ ...caption, bold: e.target.checked })}
                    className="size-4 rounded accent-accent"
                  />
                  Bold
                </label>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={progressBarEnabled}
                    onChange={(e) => setProgressBarEnabled(e.target.checked)}
                    className="size-4 rounded accent-accent"
                  />
                  Progress bar
                </label>
              </div>

              <div>
                <Label>Watermark opacity ({Math.round(watermarkOpacity * 100)}%)</Label>
                <input
                  type="range"
                  min={0}
                  max={1}
                  step={0.05}
                  value={watermarkOpacity}
                  onChange={(e) => setWatermarkOpacity(Number(e.target.value))}
                  className="w-full accent-accent"
                />
              </div>
            </fieldset>
            {!isAdmin && <p className="mt-4 text-xs text-muted">Only admins can edit templates.</p>}
          </Card>

          <Card className="p-5">
            <h3 className="mb-1 text-sm font-semibold">Video Layout</h3>
            <p className="mb-4 text-xs text-muted">
              By default the video fills the whole frame. Switch to a custom area to shrink it into a band and build
              color bars (via Layers below, using a &quot;Color bar&quot; layer) above/below it — the &quot;news
              compilation&quot; look, headline bar + inset video + branding bar.
            </p>
            <div className="mb-4 flex items-center gap-2 rounded-lg bg-surface-elevated p-0.5 w-fit">
              <button
                type="button"
                disabled={!isAdmin}
                onClick={() => setVideoRegion(null)}
                className={
                  "rounded-md px-2.5 py-1 text-xs cursor-pointer disabled:cursor-not-allowed " +
                  (videoRegion === null ? "bg-accent text-white" : "text-muted hover:text-foreground")
                }
              >
                Full-bleed video (default)
              </button>
              <button
                type="button"
                disabled={!isAdmin}
                onClick={() => setVideoRegion(videoRegion ?? { top: 0.15, height: 0.65 })}
                className={
                  "rounded-md px-2.5 py-1 text-xs cursor-pointer disabled:cursor-not-allowed " +
                  (videoRegion !== null ? "bg-accent text-white" : "text-muted hover:text-foreground")
                }
              >
                Custom video area
              </button>
            </div>
            {videoRegion && (
              <fieldset disabled={!isAdmin} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <Label>Video area top ({Math.round(videoRegion.top * 100)}%)</Label>
                    <input
                      type="range"
                      min={0}
                      max={0.9}
                      step={0.01}
                      value={videoRegion.top}
                      onChange={(e) => setVideoRegion({ ...videoRegion, top: Number(e.target.value) })}
                      className="mt-2.5 w-full accent-accent"
                    />
                  </div>
                  <div>
                    <Label>Video area height ({Math.round(videoRegion.height * 100)}%)</Label>
                    <input
                      type="range"
                      min={0.1}
                      max={1 - videoRegion.top}
                      step={0.01}
                      value={videoRegion.height}
                      onChange={(e) => setVideoRegion({ ...videoRegion, height: Number(e.target.value) })}
                      className="mt-2.5 w-full accent-accent"
                    />
                  </div>
                </div>
                <div>
                  <Label>Canvas background color (shows behind the inset video)</Label>
                  <Input
                    type="color"
                    className="h-10 w-24 p-1"
                    value={canvasBackgroundColor}
                    onChange={(e) => setCanvasBackgroundColor(e.target.value)}
                  />
                </div>
              </fieldset>
            )}
          </Card>

          <Card className="p-5">
            <h3 className="mb-1 text-sm font-semibold">Effects &amp; Transition</h3>
            <p className="mb-4 text-xs text-muted">
              Effect applies to the base video only (captions/layers stay put). Transition only matters for clips
              that have an intro or outro card — it controls how those segments join the main clip.
            </p>
            <fieldset disabled={!isAdmin} className="space-y-4 disabled:opacity-60">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Effect</Label>
                  <Select
                    value={effectType}
                    onChange={(e) => setEffectType(e.target.value as NonNullable<TemplateConfig["effects"]>["type"])}
                  >
                    <option value="none">None</option>
                    <option value="zoom_in">Zoom in</option>
                    <option value="zoom_out">Zoom out</option>
                    <option value="ken_burns">Ken Burns</option>
                    <option value="shake">Shake</option>
                  </Select>
                </div>
                <div>
                  <Label>Intensity ({Math.round(effectIntensity * 100)}%)</Label>
                  <input
                    type="range"
                    min={0.02}
                    max={0.5}
                    step={0.01}
                    disabled={effectType === "none"}
                    value={effectIntensity}
                    onChange={(e) => setEffectIntensity(Number(e.target.value))}
                    className="mt-2.5 w-full accent-accent disabled:opacity-40"
                  />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Transition (intro/outro)</Label>
                  <Select
                    value={transitionType}
                    onChange={(e) => setTransitionType(e.target.value as NonNullable<TemplateConfig["transition"]>["type"])}
                  >
                    <option value="cut">Hard cut</option>
                    <option value="fade">Crossfade</option>
                  </Select>
                </div>
                <div>
                  <Label>Duration ({transitionDuration.toFixed(2)}s)</Label>
                  <input
                    type="range"
                    min={0.1}
                    max={1.5}
                    step={0.05}
                    disabled={transitionType === "cut"}
                    value={transitionDuration}
                    onChange={(e) => setTransitionDuration(Number(e.target.value))}
                    className="mt-2.5 w-full accent-accent disabled:opacity-40"
                  />
                </div>
              </div>
            </fieldset>
          </Card>

          <Card className="p-5">
            <h3 className="mb-1 text-sm font-semibold">Layers</h3>
            <p className="mb-4 text-xs text-muted">
              Text, logo, background-audio and progress-bar overlays. Clips using this template can override any
              layer individually in the clip editor.
            </p>
            <LayerEditor layers={layers} onChange={setLayers} disabled={!isAdmin} />
          </Card>

          {template.versions && template.versions.length > 0 && (
            <Card className="p-5">
              <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                <History className="size-4 text-accent-2" />
                Version History
              </h3>
              <div className="space-y-2">
                {template.versions.map((v) => (
                  <div
                    key={v.id}
                    className="flex items-center justify-between rounded-lg bg-surface-elevated px-3 py-2 text-xs"
                  >
                    <span>{v.label}</span>
                    {v.id === template.current_version?.id && <Badge tone="success">Current</Badge>}
                  </div>
                ))}
              </div>
              <p className="mt-3 text-[11px] text-muted">
                Projects already using an older version keep rendering with it — publishing a new version never
                changes past clips.
              </p>
            </Card>
          )}
        </div>
      </div>
    </div>
  );
}
