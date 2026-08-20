"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { mutate } from "swr";
import { Bot } from "lucide-react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Label, Select, Textarea, Input } from "@/components/ui/Input";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import type { PublishingProfile, Template, VideoBatch } from "@/lib/types";

const CLIP_MODES = [
  { value: "top_3", label: "Top 3 Clips" },
  { value: "top_5", label: "Top 5 Clips" },
  { value: "top_10", label: "Top 10 Clips" },
  { value: "all", label: "All Recommended Clips" },
];

export function NewBatchModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const router = useRouter();
  const { data: templatesData } = useApi<{ data: Template[] }>(open ? "/templates" : null);
  const { data: profilesData } = useApi<{ data: PublishingProfile[] }>(open ? "/publishing-profiles" : null);

  const [name, setName] = useState("");
  const [urlsText, setUrlsText] = useState("");
  const [clipMode, setClipMode] = useState("top_5");
  const [templateId, setTemplateId] = useState("");
  const [aspectRatio, setAspectRatio] = useState("9:16");
  const [publishingProfileId, setPublishingProfileId] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const urlCount = urlsText.split("\n").map((l) => l.trim()).filter(Boolean).length;

  function resetAndClose() {
    setName("");
    setUrlsText("");
    setClipMode("top_5");
    setTemplateId("");
    setAspectRatio("9:16");
    setPublishingProfileId("");
    onClose();
  }

  async function handleSubmit() {
    const urls = urlsText.split("\n").map((l) => l.trim()).filter(Boolean);
    if (urls.length === 0) {
      toast("Paste at least one video URL.", "danger");
      return;
    }

    setSubmitting(true);
    try {
      const res = await api.post<{ data: VideoBatch }>("/video-batches", {
        name: name || undefined,
        urls,
        clip_mode: clipMode,
        template_id: templateId ? Number(templateId) : null,
        aspect_ratio: aspectRatio,
        publishing_profile_id: publishingProfileId ? Number(publishingProfileId) : null,
      });
      await mutate((key) => typeof key === "string" && key.startsWith("/video-batches"));
      toast(`Batch started — processing ${urls.length} video${urls.length === 1 ? "" : "s"} one at a time.`, "success");
      resetAndClose();
      router.push(`/batches/${res.data.id}`);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to start batch.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} onClose={resetAndClose} title="New Batch" className="max-w-xl">
      <div className="space-y-4">
        <div>
          <Label htmlFor="batch_name">Name (optional)</Label>
          <Input id="batch_name" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Monday drop" />
        </div>

        <div>
          <Label htmlFor="batch_urls">Video URLs — one per line</Label>
          <Textarea
            id="batch_urls"
            rows={6}
            value={urlsText}
            onChange={(e) => setUrlsText(e.target.value)}
            placeholder={"https://youtube.com/watch?v=...\nhttps://tiktok.com/@user/video/...\nhttps://instagram.com/reel/..."}
          />
          <p className="mt-1.5 text-xs text-muted">
            {urlCount} video{urlCount === 1 ? "" : "s"} queued. Each is imported, transcribed, clipped, and
            published one at a time — never in parallel — so the machine doesn&apos;t choke.
          </p>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <Label htmlFor="batch_mode">Clips per video</Label>
            <Select id="batch_mode" value={clipMode} onChange={(e) => setClipMode(e.target.value)}>
              {CLIP_MODES.map((m) => (
                <option key={m.value} value={m.value}>
                  {m.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label htmlFor="batch_aspect">Aspect Ratio</Label>
            <Select id="batch_aspect" value={aspectRatio} onChange={(e) => setAspectRatio(e.target.value)}>
              <option value="9:16">9:16 — TikTok / Reels / Shorts</option>
              <option value="1:1">1:1 — Square</option>
              <option value="16:9">16:9 — Landscape</option>
            </Select>
          </div>
        </div>

        <div>
          <Label htmlFor="batch_template">Template</Label>
          <Select id="batch_template" value={templateId} onChange={(e) => setTemplateId(e.target.value)}>
            <option value="">No template (default captions)</option>
            {templatesData?.data.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name} — {t.aspect_ratio}
              </option>
            ))}
          </Select>
        </div>

        <div>
          <Label htmlFor="batch_publish">Publish to</Label>
          <Select id="batch_publish" value={publishingProfileId} onChange={(e) => setPublishingProfileId(e.target.value)}>
            <option value="">Auto — every active (auto-publish) social account</option>
            {profilesData?.data.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </Select>
        </div>
      </div>

      <div className="mt-6 flex justify-end gap-2">
        <Button variant="ghost" onClick={resetAndClose} disabled={submitting}>
          Cancel
        </Button>
        <Button onClick={handleSubmit} loading={submitting}>
          <Bot className="size-4" />
          Start Batch
        </Button>
      </div>
    </Modal>
  );
}
