"use client";

import { use, useEffect, useState } from "react";
import Link from "next/link";
import { mutate } from "swr";
import { ArrowLeft, Save, History, Archive } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { useAuthStore } from "@/store/auth";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Input, Label, Select } from "@/components/ui/Input";
import { Badge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import type { Template, TemplateConfig } from "@/lib/types";

export default function TemplateDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const templateId = Number(id);
  const key = `/templates/${templateId}`;
  const user = useAuthStore((s) => s.user);
  const isAdmin = user?.role === "admin";

  const { data, isLoading } = useApi<{ data: Template }>(key);
  const template = data?.data;

  const [caption, setCaption] = useState<NonNullable<TemplateConfig["caption"]>>({});
  const [watermarkOpacity, setWatermarkOpacity] = useState(0.8);
  const [progressBarEnabled, setProgressBarEnabled] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (template?.current_version?.config) {
      const cfg = template.current_version.config;
      setCaption(cfg.caption ?? {});
      setWatermarkOpacity(cfg.branding?.watermark_opacity ?? 0.8);
      setProgressBarEnabled(!!cfg.progress_bar?.enabled);
    }
  }, [template]);

  async function handleSave() {
    setSaving(true);
    try {
      await api.patch(`/admin/templates/${templateId}`, {
        config: {
          caption,
          branding: { watermark_opacity: watermarkOpacity },
          progress_bar: progressBarEnabled ? { enabled: true, color: caption.highlight_color ?? "#7c5cff" } : null,
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

  const previewBg = caption.background && (caption.background_opacity ?? 0) > 0 ? caption.background : "transparent";

  return (
    <div className="space-y-6">
      <div>
        <Link href="/templates" className="mb-3 flex items-center gap-1.5 text-xs text-muted hover:text-foreground">
          <ArrowLeft className="size-3.5" />
          Back to Templates
        </Link>
        <div className="flex items-start justify-between gap-4">
          <div>
            <div className="flex items-center gap-2.5">
              <h1 className="text-xl font-semibold">{template.name}</h1>
              <Badge tone="muted">{template.current_version?.label}</Badge>
              {template.is_system && <Badge tone="accent">System</Badge>}
            </div>
            <p className="mt-1 text-sm text-muted">{template.description}</p>
          </div>
          {isAdmin && (
            <div className="flex items-center gap-2">
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
          <div className="sticky top-6 mx-auto max-w-[220px]">
            <p className="mb-2 text-center text-xs text-muted">Caption Preview</p>
            <div
              className="relative flex aspect-[9/16] w-full items-end justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-slate-700 to-slate-900 p-4"
              style={{ textAlign: caption.position === "top" ? "left" : "center" }}
            >
              <div
                className="w-full"
                style={{
                  alignSelf:
                    caption.position === "top" ? "flex-start" : caption.position === "center" ? "center" : "flex-end",
                  position: caption.position === "top" ? "absolute" : undefined,
                  top: caption.position === "top" ? 16 : undefined,
                }}
              >
                <span
                  style={{
                    fontFamily: caption.font || "Arial",
                    color: caption.color || "#fff",
                    WebkitTextStroke: `${Math.min(caption.stroke_width ?? 3, 3)}px ${caption.stroke_color || "#000"}`,
                    fontWeight: caption.bold ? 800 : 500,
                    textTransform: caption.uppercase ? "uppercase" : "none",
                    background: previewBg,
                    padding: previewBg !== "transparent" ? "4px 8px" : 0,
                    fontSize: 18,
                    lineHeight: 1.3,
                  }}
                >
                  This is the{" "}
                  <span style={{ color: caption.highlight_color || "#FFD100", WebkitTextStroke: "0px" }}>biggest</span>{" "}
                  mistake
                </span>
              </div>
              {progressBarEnabled && (
                <div className="absolute left-0 right-0 top-0 h-1 bg-white/20">
                  <div className="h-full w-1/3" style={{ background: caption.highlight_color || "#7c5cff" }} />
                </div>
              )}
            </div>
          </div>
        </div>

        <div className="space-y-6 lg:col-span-3">
          <Card className="p-5">
            <h3 className="mb-4 text-sm font-semibold">Caption Style</h3>
            <fieldset disabled={!isAdmin} className="space-y-4 disabled:opacity-60">
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label>Font</Label>
                  <Input value={caption.font ?? ""} onChange={(e) => setCaption({ ...caption, font: e.target.value })} />
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
