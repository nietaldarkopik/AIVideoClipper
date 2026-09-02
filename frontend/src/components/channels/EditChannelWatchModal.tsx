"use client";

import { useState } from "react";
import { mutate } from "swr";
import { Save } from "lucide-react";
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

export function EditChannelWatchModal({
  watch,
  open,
  onClose,
}: {
  watch: ChannelWatch;
  open: boolean;
  onClose: () => void;
}) {
  const { data: templatesData } = useApi<{ data: Template[] }>(open ? "/templates" : null);
  const { data: profilesData } = useApi<{ data: PublishingProfile[] }>(open ? "/publishing-profiles" : null);

  const [clipMode, setClipMode] = useState(watch.settings.clip_mode);
  const [templateId, setTemplateId] = useState(watch.settings.template_id ? String(watch.settings.template_id) : "");
  const [aspectRatio, setAspectRatio] = useState(watch.settings.aspect_ratio);
  const [publishingProfileId, setPublishingProfileId] = useState(
    watch.settings.publishing_profile_id ? String(watch.settings.publishing_profile_id) : ""
  );
  const [staggerMin, setStaggerMin] = useState(String(watch.settings.publish_stagger_min_minutes ?? 30));
  const [staggerMax, setStaggerMax] = useState(String(watch.settings.publish_stagger_max_minutes ?? 60));
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    setSubmitting(true);
    try {
      const res = await api.patch<{ data: ChannelWatch; resynced_posts?: number }>(`/channel-watches/${watch.id}`, {
        clip_mode: clipMode,
        template_id: templateId ? Number(templateId) : null,
        aspect_ratio: aspectRatio,
        publishing_profile_id: publishingProfileId ? Number(publishingProfileId) : null,
        publish_stagger_min_minutes: Number(staggerMin),
        publish_stagger_max_minutes: Number(staggerMax),
      });
      await mutate((key) => typeof key === "string" && key.startsWith("/channel-watches"));
      // resynced_posts is only ever >0 when "Publish to" actually changed (see
      // ChannelWatchController::update()) — it means clips already rendered
      // under this channel, still waiting to publish, just got redirected onto
      // the new target instead of the old (wrong) one.
      toast(
        res.resynced_posts
          ? `Channel settings updated — ${res.resynced_posts} already-scheduled post(s) moved to the new channel.`
          : "Channel settings updated.",
        "success"
      );
      onClose();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to update channel.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} onClose={onClose} title={`Edit — ${watch.channel_title ?? watch.channel_url}`} className="max-w-xl">
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <Label htmlFor="ecw_mode">Clips per video</Label>
            <Select id="ecw_mode" value={clipMode} onChange={(e) => setClipMode(e.target.value as typeof clipMode)}>
              {CLIP_MODES.map((m) => (
                <option key={m.value} value={m.value}>
                  {m.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label htmlFor="ecw_aspect">Aspect Ratio</Label>
            <Select id="ecw_aspect" value={aspectRatio} onChange={(e) => setAspectRatio(e.target.value as typeof aspectRatio)}>
              <option value="9:16">9:16 — TikTok / Reels / Shorts</option>
              <option value="1:1">1:1 — Square</option>
              <option value="16:9">16:9 — Landscape</option>
            </Select>
          </div>
        </div>

        <div>
          <Label htmlFor="ecw_template">Template</Label>
          <Select id="ecw_template" value={templateId} onChange={(e) => setTemplateId(e.target.value)}>
            <option value="">No template (default captions)</option>
            {templatesData?.data.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name} — {t.aspect_ratio}
              </option>
            ))}
          </Select>
        </div>

        <div>
          <Label htmlFor="ecw_publish">Publish to</Label>
          <Select id="ecw_publish" value={publishingProfileId} onChange={(e) => setPublishingProfileId(e.target.value)}>
            <option value="">Auto — every active (auto-publish) social account</option>
            {profilesData?.data.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </Select>
          <p className="mt-1.5 text-xs text-muted">
            Changing this also redirects any of this channel&apos;s clips that are already rendered but not
            published yet — they move onto the new target instead of staying queued for the old one. Clips already
            published are never touched; a clip somewhere else entirely already scheduled to the wrong channel can be
            fixed on the Scheduler page&apos;s &quot;Move to Channel&quot; action.
          </p>
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
        <Button variant="ghost" onClick={onClose} disabled={submitting}>
          Cancel
        </Button>
        <Button onClick={handleSubmit} loading={submitting}>
          <Save className="size-4" />
          Save Changes
        </Button>
      </div>
    </Modal>
  );
}
