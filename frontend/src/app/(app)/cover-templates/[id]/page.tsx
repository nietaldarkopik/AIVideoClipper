"use client";

import { use, useEffect, useState } from "react";
import Link from "next/link";
import { mutate } from "swr";
import { ArrowLeft, Save, Archive, Sparkles } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { useAuthStore } from "@/store/auth";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Input, Label, Select, Textarea } from "@/components/ui/Input";
import { Badge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { CoverLivePreview } from "@/components/cover-templates/CoverLivePreview";
import type {
  CoverTemplate,
  CoverTemplateBackgroundConfig,
  CoverTemplateBadgeConfig,
  CoverTemplateKickerConfig,
  CoverTemplateSublineConfig,
  CoverTemplateTextConfig,
} from "@/lib/types";

const SAMPLE_HEADLINE = "Ternyata ini rahasianya";

export default function CoverTemplateDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const coverTemplateId = Number(id);
  const key = `/cover-templates/${coverTemplateId}`;
  const user = useAuthStore((s) => s.user);
  const isAdmin = user?.role === "admin";

  const { data, isLoading } = useApi<{ data: CoverTemplate }>(key);
  const coverTemplate = data?.data;
  // Real, text-free frame from the shared demo video so the live preview sits
  // on actual footage instead of a flat placeholder — see
  // CoverTemplateController::demoFrame().
  const { data: demoFrame } = useApi<{ url: string | null }>("/cover-templates/demo-frame");

  const [background, setBackground] = useState<CoverTemplateBackgroundConfig>({});
  const [kicker, setKicker] = useState<CoverTemplateKickerConfig>({});
  const [text, setText] = useState<CoverTemplateTextConfig>({});
  const [subline, setSubline] = useState<CoverTemplateSublineConfig>({});
  const [badge, setBadge] = useState<CoverTemplateBadgeConfig>({});
  const [sampleHeadline, setSampleHeadline] = useState(SAMPLE_HEADLINE);
  const [saving, setSaving] = useState(false);
  const [generating, setGenerating] = useState(false);

  useEffect(() => {
    if (coverTemplate?.config) {
      setBackground(coverTemplate.config.background ?? {});
      setKicker(coverTemplate.config.kicker ?? {});
      setText(coverTemplate.config.text ?? {});
      setSubline(coverTemplate.config.subline ?? {});
      setBadge(coverTemplate.config.badge ?? {});
    }
  }, [coverTemplate]);

  const gradient = background.gradient ?? {};
  const setGradient = (patch: Partial<NonNullable<CoverTemplateBackgroundConfig["gradient"]>>) =>
    setBackground({ ...background, gradient: { ...gradient, ...patch } });

  async function handleSave() {
    setSaving(true);
    try {
      await api.patch(`/admin/cover-templates/${coverTemplateId}`, {
        config: { background, kicker, text, subline, badge },
      });
      await mutate(key);
      toast("Cover template saved.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to save cover template.", "danger");
    } finally {
      setSaving(false);
    }
  }

  async function handleGenerateSample() {
    setGenerating(true);
    try {
      await api.post(`/admin/cover-templates/${coverTemplateId}/generate-thumbnail`);
      await mutate(key);
      toast("Sample cover rendered.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to render a sample cover.", "danger");
    } finally {
      setGenerating(false);
    }
  }

  async function handleArchive() {
    if (!confirm("Archive this cover template? Clips already using it keep their generated cover.")) return;
    try {
      await api.post(`/admin/cover-templates/${coverTemplateId}/archive`);
      await mutate(key);
      toast("Cover template archived.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to archive.", "danger");
    }
  }

  if (isLoading || !coverTemplate) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-96" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <Link href="/cover-templates" className="mb-3 flex items-center gap-1.5 text-xs text-muted hover:text-foreground">
          <ArrowLeft className="size-3.5" />
          Back to Cover Templates
        </Link>
        <div className="flex items-start justify-between gap-4">
          <div>
            <div className="flex items-center gap-2.5">
              <h1 className="text-xl font-semibold">{coverTemplate.name}</h1>
              <Badge tone="muted">{coverTemplate.aspect_ratio}</Badge>
              {coverTemplate.is_system && <Badge tone="accent">System</Badge>}
            </div>
            <p className="mt-1 text-sm text-muted">{coverTemplate.description}</p>
          </div>
          {isAdmin && (
            <div className="flex items-center gap-2">
              <Button variant="outline" size="sm" onClick={handleGenerateSample} loading={generating}>
                <Sparkles className="size-3.5" />
                Render Real Sample
              </Button>
              <Button variant="outline" size="sm" onClick={handleArchive}>
                <Archive className="size-3.5" />
                Archive
              </Button>
              <Button onClick={handleSave} loading={saving}>
                <Save className="size-4" />
                Save
              </Button>
            </div>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-5">
        <div className="lg:col-span-2">
          <div className="sticky top-6 space-y-4">
            <div>
              <p className="mb-2 text-center text-xs text-muted">Live Preview</p>
              <div className="flex justify-center">
                <CoverLivePreview
                  config={{ background, kicker, text, subline, badge }}
                  aspectRatio={coverTemplate.aspect_ratio}
                  headline={sampleHeadline}
                  frameUrl={demoFrame?.url ?? null}
                  widthPx={coverTemplate.aspect_ratio === "16:9" ? 320 : 240}
                />
              </div>
            </div>

            <div>
              <Label>Sample Headline</Label>
              <Input
                value={sampleHeadline}
                onChange={(e) => setSampleHeadline(e.target.value)}
                placeholder="Type a headline to preview wrapping"
              />
              <p className="mt-1.5 text-xs text-muted">
                Preview only — the real cover uses each clip&apos;s own hook text.
              </p>
            </div>

            {coverTemplate.thumbnail_url && (
              <div>
                <p className="mb-2 text-xs text-muted">Last rendered sample (real ffmpeg output)</p>
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={coverTemplate.thumbnail_url} alt="" className="w-full rounded-xl" />
              </div>
            )}
          </div>
        </div>

        <div className="space-y-6 lg:col-span-3">
          <Card className="p-5">
            <h2 className="mb-4 text-sm font-semibold">Background</h2>
            <div className="grid grid-cols-2 gap-4">
              <div className="col-span-2">
                <Label>Source</Label>
                <Select
                  value={background.source ?? "clip_frame"}
                  onChange={(e) =>
                    setBackground({ ...background, source: e.target.value as CoverTemplateBackgroundConfig["source"] })
                  }
                >
                  <option value="clip_frame">Real frame from the clip&apos;s video</option>
                  <option value="ai_generated">AI-generated scene (from a prompt)</option>
                </Select>
              </div>
              <div>
                <Label>Color Wash</Label>
                <Input
                  type="color"
                  value={background.overlay_color ?? "#000000"}
                  onChange={(e) => setBackground({ ...background, overlay_color: e.target.value })}
                  className="h-10 p-1"
                />
              </div>
              <div>
                <Label>Wash Strength ({Math.round((background.overlay_opacity ?? 0) * 100)}%)</Label>
                <input
                  type="range"
                  min={0}
                  max={0.7}
                  step={0.02}
                  value={background.overlay_opacity ?? 0}
                  onChange={(e) => setBackground({ ...background, overlay_opacity: Number(e.target.value) })}
                  className="mt-3 w-full accent-accent"
                />
              </div>
            </div>

            <div className="mt-4 grid grid-cols-2 gap-4 border-t border-border-subtle pt-4">
              <div className="col-span-2">
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={!!gradient.enabled}
                    onChange={(e) => setGradient({ enabled: e.target.checked })}
                    className="size-4 rounded accent-accent"
                  />
                  Darkening gradient behind the text
                </label>
              </div>
              {gradient.enabled && (
                <>
                  <div>
                    <Label>Gradient Color</Label>
                    <Input
                      type="color"
                      value={gradient.color ?? "#000000"}
                      onChange={(e) => setGradient({ color: e.target.value })}
                      className="h-10 p-1"
                    />
                  </div>
                  <div>
                    <Label>Side</Label>
                    <Select
                      value={gradient.position ?? "bottom"}
                      onChange={(e) => setGradient({ position: e.target.value as "top" | "bottom" })}
                    >
                      <option value="bottom">Bottom</option>
                      <option value="top">Top</option>
                    </Select>
                  </div>
                  <div>
                    <Label>Strength ({Math.round((gradient.opacity ?? 0) * 100)}%)</Label>
                    <input
                      type="range"
                      min={0}
                      max={1}
                      step={0.05}
                      value={gradient.opacity ?? 0.8}
                      onChange={(e) => setGradient({ opacity: Number(e.target.value) })}
                      className="mt-3 w-full accent-accent"
                    />
                  </div>
                  <div>
                    <Label>Size ({Math.round((gradient.size ?? 0.45) * 100)}%)</Label>
                    <input
                      type="range"
                      min={0.15}
                      max={0.8}
                      step={0.05}
                      value={gradient.size ?? 0.45}
                      onChange={(e) => setGradient({ size: Number(e.target.value) })}
                      className="mt-3 w-full accent-accent"
                    />
                  </div>
                </>
              )}
            </div>

            {background.source === "ai_generated" && (
              <div className="mt-4 border-t border-border-subtle pt-4">
                <Label>AI Image Prompt</Label>
                <Textarea
                  rows={4}
                  value={background.ai_prompt ?? ""}
                  onChange={(e) => setBackground({ ...background, ai_prompt: e.target.value })}
                  placeholder="Describe the scene — subject, lighting, mood, and where the safe zone for text is. 9:16 aspect ratio."
                />
                <p className="mt-1.5 text-xs text-muted">
                  Falls back to a real frame from the clip if AI generation fails or isn&apos;t configured
                  (AI_COVER_IMAGE_PROVIDER=mock).
                </p>
              </div>
            )}
          </Card>

          <Card className="p-5">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-sm font-semibold">Kicker (small label above)</h2>
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={!!kicker.enabled}
                  onChange={(e) => setKicker({ ...kicker, enabled: e.target.checked })}
                  className="size-4 rounded accent-accent"
                />
                Enabled
              </label>
            </div>
            {kicker.enabled && (
              <div className="grid grid-cols-4 gap-4">
                <div className="col-span-2">
                  <Label>Text</Label>
                  <Input value={kicker.text ?? ""} onChange={(e) => setKicker({ ...kicker, text: e.target.value })} maxLength={30} />
                </div>
                <div>
                  <Label>Text Color</Label>
                  <Input
                    type="color"
                    value={kicker.color ?? "#111111"}
                    onChange={(e) => setKicker({ ...kicker, color: e.target.value })}
                    className="h-10 p-1"
                  />
                </div>
                <div>
                  <Label>Background</Label>
                  <Input
                    type="color"
                    value={kicker.background ?? "#FFD100"}
                    onChange={(e) => setKicker({ ...kicker, background: e.target.value })}
                    className="h-10 p-1"
                  />
                </div>
              </div>
            )}
          </Card>

          <Card className="p-5">
            <h2 className="mb-4 text-sm font-semibold">Headline</h2>
            <div className="grid grid-cols-3 gap-4">
              <div>
                <Label>Position</Label>
                <Select
                  value={text.position ?? "bottom"}
                  onChange={(e) => setText({ ...text, position: e.target.value as CoverTemplateTextConfig["position"] })}
                >
                  <option value="top">Top</option>
                  <option value="center">Center</option>
                  <option value="bottom">Bottom</option>
                </Select>
              </div>
              <div>
                <Label>Align</Label>
                <Select
                  value={text.align ?? "center"}
                  onChange={(e) => setText({ ...text, align: e.target.value as CoverTemplateTextConfig["align"] })}
                >
                  <option value="center">Center</option>
                  <option value="left">Left</option>
                </Select>
              </div>
              <div>
                <Label>Block Style</Label>
                <Select
                  value={text.block_style ?? "lines"}
                  onChange={(e) => setText({ ...text, block_style: e.target.value as CoverTemplateTextConfig["block_style"] })}
                >
                  <option value="lines">Box per line</option>
                  <option value="band">One full-width bar</option>
                  <option value="none">No background</option>
                </Select>
              </div>
              <div>
                <Label>Size ({Math.round((text.font_scale ?? 0.085) * 1000) / 10}%)</Label>
                <input
                  type="range"
                  min={0.05}
                  max={0.13}
                  step={0.005}
                  value={text.font_scale ?? 0.085}
                  onChange={(e) => setText({ ...text, font_scale: Number(e.target.value) })}
                  className="mt-3 w-full accent-accent"
                />
              </div>
              <div>
                <Label>Wrap Width (chars)</Label>
                <Input
                  type="number"
                  min={8}
                  max={40}
                  value={text.wrap_chars ?? 16}
                  onChange={(e) => setText({ ...text, wrap_chars: Number(e.target.value) })}
                />
              </div>
              <div>
                <Label>Accent Lines</Label>
                <Select
                  value={text.highlight_mode ?? "none"}
                  onChange={(e) =>
                    setText({ ...text, highlight_mode: e.target.value as CoverTemplateTextConfig["highlight_mode"] })
                  }
                >
                  <option value="none">None</option>
                  <option value="first_line">First line</option>
                  <option value="last_line">Last line</option>
                  <option value="alternate">Alternating lines</option>
                </Select>
              </div>
              <div>
                <Label>Text Color</Label>
                <Input
                  type="color"
                  value={text.color ?? "#ffffff"}
                  onChange={(e) => setText({ ...text, color: e.target.value })}
                  className="h-10 p-1"
                />
              </div>
              <div>
                <Label>Accent Color</Label>
                <Input
                  type="color"
                  value={text.highlight_color ?? "#ffd100"}
                  onChange={(e) => setText({ ...text, highlight_color: e.target.value })}
                  className="h-10 p-1"
                />
              </div>
              <div>
                <Label>Box Color</Label>
                <Input
                  type="color"
                  value={text.background ?? "#000000"}
                  onChange={(e) => setText({ ...text, background: e.target.value })}
                  className="h-10 p-1"
                />
              </div>
              <div>
                <Label>Box Opacity ({Math.round((text.background_opacity ?? 0) * 100)}%)</Label>
                <input
                  type="range"
                  min={0}
                  max={1}
                  step={0.05}
                  value={text.background_opacity ?? 0.55}
                  onChange={(e) => setText({ ...text, background_opacity: Number(e.target.value) })}
                  className="mt-3 w-full accent-accent"
                />
              </div>
              <div>
                <Label>Stroke Color</Label>
                <Input
                  type="color"
                  value={text.stroke_color ?? "#000000"}
                  onChange={(e) => setText({ ...text, stroke_color: e.target.value })}
                  className="h-10 p-1"
                />
              </div>
              <div>
                <Label>Stroke Width</Label>
                <Input
                  type="number"
                  min={0}
                  max={14}
                  value={text.stroke_width ?? 6}
                  onChange={(e) => setText({ ...text, stroke_width: Number(e.target.value) })}
                />
              </div>
              <div>
                <Label>Shadow Color</Label>
                <Input
                  type="color"
                  value={text.shadow_color ?? "#000000"}
                  onChange={(e) => setText({ ...text, shadow_color: e.target.value })}
                  className="h-10 p-1"
                />
              </div>
              <div>
                <Label>Shadow X</Label>
                <Input
                  type="number"
                  min={0}
                  max={20}
                  value={text.shadow_x ?? 4}
                  onChange={(e) => setText({ ...text, shadow_x: Number(e.target.value) })}
                />
              </div>
              <div>
                <Label>Shadow Y</Label>
                <Input
                  type="number"
                  min={0}
                  max={20}
                  value={text.shadow_y ?? 5}
                  onChange={(e) => setText({ ...text, shadow_y: Number(e.target.value) })}
                />
              </div>
            </div>
            <label className="mt-4 flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={!!text.uppercase}
                onChange={(e) => setText({ ...text, uppercase: e.target.checked })}
                className="size-4 rounded accent-accent"
              />
              Uppercase
            </label>
          </Card>

          <Card className="p-5">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-sm font-semibold">Subline (small label below)</h2>
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={!!subline.enabled}
                  onChange={(e) => setSubline({ ...subline, enabled: e.target.checked })}
                  className="size-4 rounded accent-accent"
                />
                Enabled
              </label>
            </div>
            {subline.enabled && (
              <div className="grid grid-cols-4 gap-4">
                <div className="col-span-2">
                  <Label>Text</Label>
                  <Input
                    value={subline.text ?? ""}
                    onChange={(e) => setSubline({ ...subline, text: e.target.value })}
                    maxLength={40}
                  />
                </div>
                <div>
                  <Label>Text Color</Label>
                  <Input
                    type="color"
                    value={subline.color ?? "#ffffff"}
                    onChange={(e) => setSubline({ ...subline, color: e.target.value })}
                    className="h-10 p-1"
                  />
                </div>
                <div>
                  <Label>Background</Label>
                  <Input
                    type="color"
                    value={subline.background ?? "#e11d48"}
                    onChange={(e) => setSubline({ ...subline, background: e.target.value })}
                    className="h-10 p-1"
                  />
                </div>
              </div>
            )}
          </Card>

          <Card className="p-5">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="text-sm font-semibold">Corner Badge</h2>
              <label className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox"
                  checked={!!badge.enabled}
                  onChange={(e) => setBadge({ ...badge, enabled: e.target.checked })}
                  className="size-4 rounded accent-accent"
                />
                Enabled
              </label>
            </div>
            {badge.enabled && (
              <div className="grid grid-cols-3 gap-4">
                <div>
                  <Label>Badge Text</Label>
                  <Input value={badge.text ?? "VIRAL"} onChange={(e) => setBadge({ ...badge, text: e.target.value })} maxLength={20} />
                </div>
                <div>
                  <Label>Badge Color</Label>
                  <Input
                    type="color"
                    value={badge.color ?? "#ff3b30"}
                    onChange={(e) => setBadge({ ...badge, color: e.target.value })}
                    className="h-10 p-1"
                  />
                </div>
                <div>
                  <Label>Text Color</Label>
                  <Input
                    type="color"
                    value={badge.text_color ?? "#ffffff"}
                    onChange={(e) => setBadge({ ...badge, text_color: e.target.value })}
                    className="h-10 p-1"
                  />
                </div>
              </div>
            )}
          </Card>
        </div>
      </div>
    </div>
  );
}
