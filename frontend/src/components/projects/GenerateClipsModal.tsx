"use client";

import { useState } from "react";
import { mutate } from "swr";
import { Sparkles } from "lucide-react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Label, Select } from "@/components/ui/Input";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import type { Template } from "@/lib/types";

const DURATION_MODES = [
  { value: "top_3", label: "Top 3 Clips" },
  { value: "top_5", label: "Top 5 Clips" },
  { value: "top_10", label: "Top 10 Clips" },
  { value: "all", label: "All Recommended Clips" },
];

export function GenerateClipsModal({
  open,
  onClose,
  projectId,
  candidateIds,
}: {
  open: boolean;
  onClose: () => void;
  projectId: number;
  candidateIds?: number[];
}) {
  const { data: templatesData } = useApi<{ data: Template[] }>(open ? "/templates" : null);
  const [mode, setMode] = useState("top_5");
  const [templateId, setTemplateId] = useState<string>("");
  const [aspectRatio, setAspectRatio] = useState("9:16");
  const [submitting, setSubmitting] = useState(false);

  const isSingleSelection = !!candidateIds && candidateIds.length > 0;

  async function handleSubmit() {
    setSubmitting(true);
    try {
      await api.post(`/projects/${projectId}/generate-clips`, {
        ...(isSingleSelection ? { candidate_ids: candidateIds } : { mode }),
        template_id: templateId ? Number(templateId) : null,
        aspect_ratio: aspectRatio,
      });
      await mutate(`/projects/${projectId}`);
      await mutate((key) => typeof key === "string" && key.startsWith(`/projects/${projectId}/clip-candidates`));
      toast("Clips queued for rendering.", "success");
      onClose();
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Something went wrong.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} onClose={onClose} title={isSingleSelection ? "Generate Clip" : "Generate Clips"}>
      <div className="space-y-4">
        {!isSingleSelection && (
          <div>
            <Label htmlFor="mode">How many clips?</Label>
            <Select id="mode" value={mode} onChange={(e) => setMode(e.target.value)}>
              {DURATION_MODES.map((m) => (
                <option key={m.value} value={m.value}>
                  {m.label}
                </option>
              ))}
            </Select>
          </div>
        )}

        <div>
          <Label htmlFor="template">Template</Label>
          <Select id="template" value={templateId} onChange={(e) => setTemplateId(e.target.value)}>
            <option value="">No template (default captions)</option>
            {templatesData?.data.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name} — {t.aspect_ratio}
              </option>
            ))}
          </Select>
        </div>

        <div>
          <Label htmlFor="aspect">Aspect Ratio</Label>
          <Select id="aspect" value={aspectRatio} onChange={(e) => setAspectRatio(e.target.value)}>
            <option value="9:16">9:16 — TikTok / Reels / Shorts</option>
            <option value="1:1">1:1 — Square</option>
            <option value="16:9">16:9 — Landscape</option>
          </Select>
        </div>
      </div>

      <div className="mt-6 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>
          Cancel
        </Button>
        <Button onClick={handleSubmit} loading={submitting}>
          <Sparkles className="size-4" />
          Generate
        </Button>
      </div>
    </Modal>
  );
}
