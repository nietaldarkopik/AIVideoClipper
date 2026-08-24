"use client";

import { useState } from "react";
import { mutate } from "swr";
import { Rss } from "lucide-react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Label, Select, Input } from "@/components/ui/Input";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import type { ChannelWatch, PublishingProfile, Template } from "@/lib/types";

const CLIP_MODES = [
  { value: "top_3", label: "Top 3 Clips" },
  { value: "top_5", label: "Top 5 Clips" },
  { value: "top_10", label: "Top 10 Clips" },
  { value: "all", label: "All Recommended Clips" },
];

export function NewChannelWatchModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { data: templatesData } = useApi<{ data: Template[] }>(open ? "/templates" : null);
  const { data: profilesData } = useApi<{ data: PublishingProfile[] }>(open ? "/publishing-profiles" : null);

  const [channelUrl, setChannelUrl] = useState("");
  const [clipMode, setClipMode] = useState("top_5");
  const [templateId, setTemplateId] = useState("");
  const [aspectRatio, setAspectRatio] = useState("9:16");
  const [publishingProfileId, setPublishingProfileId] = useState("");
  const [staggerMin, setStaggerMin] = useState("30");
  const [staggerMax, setStaggerMax] = useState("60");
  const [submitting, setSubmitting] = useState(false);

  function resetAndClose() {
    setChannelUrl("");
    setClipMode("top_5");
    setTemplateId("");
    setAspectRatio("9:16");
    setPublishingProfileId("");
    setStaggerMin("30");
    setStaggerMax("60");
    onClose();
  }

  async function handleSubmit() {
    if (!channelUrl.trim()) {
      toast("Enter a channel URL or @handle.", "danger");
      return;
    }

    setSubmitting(true);
    try {
      await api.post<{ data: ChannelWatch }>("/channel-watches", {
        channel_url: channelUrl.trim(),
        clip_mode: clipMode,
        template_id: templateId ? Number(templateId) : null,
        aspect_ratio: aspectRatio,
        publishing_profile_id: publishingProfileId ? Number(publishingProfileId) : null,
        publish_stagger_min_minutes: Number(staggerMin),
        publish_stagger_max_minutes: Number(staggerMax),
      });
      await mutate((key) => typeof key === "string" && key.startsWith("/channel-watches"));
      toast("Now watching this channel for new uploads.", "success");
      resetAndClose();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to add channel.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} onClose={resetAndClose} title="Watch a Channel" className="max-w-xl">
      <div className="space-y-4">
        <div>
          <Label htmlFor="channel_url">Channel URL or @handle</Label>
          <Input
            id="channel_url"
            value={channelUrl}
            onChange={(e) => setChannelUrl(e.target.value)}
            placeholder="https://youtube.com/@channelname"
          />
          <p className="mt-1.5 text-xs text-muted">
            Every new upload after today gets imported, transcribed, clipped, and published
            automatically — the channel&apos;s existing videos are left alone.
          </p>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <Label htmlFor="cw_mode">Clips per video</Label>
            <Select id="cw_mode" value={clipMode} onChange={(e) => setClipMode(e.target.value)}>
              {CLIP_MODES.map((m) => (
                <option key={m.value} value={m.value}>
                  {m.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label htmlFor="cw_aspect">Aspect Ratio</Label>
            <Select id="cw_aspect" value={aspectRatio} onChange={(e) => setAspectRatio(e.target.value)}>
              <option value="9:16">9:16 — TikTok / Reels / Shorts</option>
              <option value="1:1">1:1 — Square</option>
              <option value="16:9">16:9 — Landscape</option>
            </Select>
          </div>
        </div>

        <div>
          <Label htmlFor="cw_template">Template</Label>
          <Select id="cw_template" value={templateId} onChange={(e) => setTemplateId(e.target.value)}>
            <option value="">No template (default captions)</option>
            {templatesData?.data.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name} — {t.aspect_ratio}
              </option>
            ))}
          </Select>
        </div>

        <div>
          <Label htmlFor="cw_publish">Publish to</Label>
          <Select id="cw_publish" value={publishingProfileId} onChange={(e) => setPublishingProfileId(e.target.value)}>
            <option value="">Auto — every active (auto-publish) social account</option>
            {profilesData?.data.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </Select>
        </div>

        <div>
          <Label>Gap between clip publishes (minutes)</Label>
          <div className="grid grid-cols-2 gap-4">
            <Input
              type="number"
              min={0}
              max={1440}
              value={staggerMin}
              onChange={(e) => setStaggerMin(e.target.value)}
              placeholder="Min"
            />
            <Input
              type="number"
              min={0}
              max={1440}
              value={staggerMax}
              onChange={(e) => setStaggerMax(e.target.value)}
              placeholder="Max"
            />
          </div>
          <p className="mt-1.5 text-xs text-muted">
            Each clip posts a random number of minutes (between these two) after the last, so
            posts don&apos;t all land at once.
          </p>
        </div>
      </div>

      <div className="mt-6 flex justify-end gap-2">
        <Button variant="ghost" onClick={resetAndClose} disabled={submitting}>
          Cancel
        </Button>
        <Button onClick={handleSubmit} loading={submitting}>
          <Rss className="size-4" />
          Watch Channel
        </Button>
      </div>
    </Modal>
  );
}
