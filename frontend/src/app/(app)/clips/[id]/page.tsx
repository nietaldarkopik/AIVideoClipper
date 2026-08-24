"use client";

import { use, useEffect, useState } from "react";
import Link from "next/link";
import { mutate } from "swr";
import { ArrowLeft, Save, RefreshCw, Trash2, Copy, Download, Video as VideoIcon } from "lucide-react";
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
import { ReactionRecorderModal } from "@/components/reactions/ReactionRecorderModal";
import { formatDuration } from "@/lib/format";
import type { Clip, Template, Video } from "@/lib/types";

const ACTIVE = ["queued", "rendering"];

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
  const [reacting, setReacting] = useState(false);

  const [form, setForm] = useState<{
    title: string;
    caption: string;
    hashtags: string;
    start_time: number;
    end_time: number;
    aspect_ratio: string;
    template_id: string;
    subtitles_enabled: boolean;
    subtitle_language: string;
  } | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (clip && !form) {
      setForm({
        title: clip.title ?? "",
        caption: clip.caption ?? "",
        hashtags: (clip.hashtags ?? []).join(", "),
        start_time: clip.start_time,
        end_time: clip.end_time,
        aspect_ratio: clip.aspect_ratio,
        template_id: clip.template?.id ? String(clip.template.id) : "",
        subtitles_enabled: clip.subtitles_enabled,
        subtitle_language: clip.subtitle_language,
      });
    }
  }, [clip, form]);

  async function handleSave() {
    if (!clip || !form) return;
    setSaving(true);
    try {
      await api.patch(clipKey, {
        title: form.title,
        caption: form.caption,
        hashtags: form.hashtags
          .split(",")
          .map((h) => h.trim())
          .filter(Boolean),
        start_time: Number(form.start_time),
        end_time: Number(form.end_time),
        aspect_ratio: form.aspect_ratio,
        template_id: form.template_id ? Number(form.template_id) : null,
        subtitles_enabled: form.subtitles_enabled,
        subtitle_language: form.subtitle_language,
      });
      await mutate(clipKey);
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

              <div className="grid grid-cols-2 gap-4">
                <div>
                  <Label htmlFor="start">Start (seconds)</Label>
                  <Input
                    id="start"
                    type="number"
                    step="0.1"
                    value={form.start_time}
                    onChange={(e) => setForm({ ...form, start_time: Number(e.target.value) })}
                  />
                  <p className="mt-1 text-[11px] text-muted">{formatDuration(form.start_time)}</p>
                </div>
                <div>
                  <Label htmlFor="end">End (seconds)</Label>
                  <Input
                    id="end"
                    type="number"
                    step="0.1"
                    value={form.end_time}
                    onChange={(e) => setForm({ ...form, end_time: Number(e.target.value) })}
                  />
                  <p className="mt-1 text-[11px] text-muted">{formatDuration(form.end_time)}</p>
                </div>
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
        startTime={clip.start_time}
        endTime={clip.end_time}
        sourceVideoUrl={videoRes?.data.url ?? null}
        submitUrl={`/clips/${clip.id}/reaction`}
        onSuccess={() => mutate(clipKey)}
      />
    </div>
  );
}
