"use client";

import { useEffect, useState } from "react";
import { mutate } from "swr";
import { Save } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Input, Label, Select } from "@/components/ui/Input";
import { Skeleton } from "@/components/ui/Skeleton";
import type { Template } from "@/lib/types";

interface AISettings {
  ai_model: string;
  transcript_model: string;
  clip_scoring_model: string;
  default_clip_duration: number;
  default_template_id: number | null;
  max_clips_per_video: number;
}

export default function AdminAISettingsPage() {
  const key = "/admin/settings";
  const { data, isLoading } = useApi<{ settings: AISettings }>(key);
  const { data: templatesRes } = useApi<{ data: Template[] }>("/templates");
  const [form, setForm] = useState<AISettings | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (data?.settings && !form) setForm(data.settings);
  }, [data, form]);

  async function handleSave() {
    if (!form) return;
    setSaving(true);
    try {
      await api.patch(key, {
        transcript_model: form.transcript_model,
        clip_scoring_model: form.clip_scoring_model,
        default_clip_duration: form.default_clip_duration,
        max_clips_per_video: form.max_clips_per_video,
        default_template_id: form.default_template_id,
      });
      await mutate(key);
      toast("Settings saved.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to save.", "danger");
    } finally {
      setSaving(false);
    }
  }

  if (isLoading || !form) return <Skeleton className="h-96" />;

  return (
    <Card className="max-w-2xl p-5">
      <h3 className="text-sm font-semibold">AI Configuration</h3>
      <p className="mt-1 text-xs text-muted">
        Providers can be switched here without editing .env or restarting the queue worker — e.g. fall back to a
        self-hosted engine if the OpenAI account runs out of credit. Social metadata generation still comes from
        backend .env (AI_SOCIAL_METADATA_PROVIDER).
      </p>

      <div className="mt-5 space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <div>
            <Label htmlFor="transcript_model">Transcription Model</Label>
            <Select
              id="transcript_model"
              value={form.transcript_model}
              onChange={(e) => setForm({ ...form, transcript_model: e.target.value })}
            >
              <option value="mock">Mock (deterministic filler, no cost)</option>
              <option value="openai">OpenAI Whisper (real, paid)</option>
              <option value="whisper_engine">Whisper Engine (real, self-hosted, free)</option>
            </Select>
          </div>
          <div>
            <Label htmlFor="clip_scoring_model">Clip Scoring Model</Label>
            <Select
              id="clip_scoring_model"
              value={form.clip_scoring_model}
              onChange={(e) => setForm({ ...form, clip_scoring_model: e.target.value })}
            >
              <option value="mock">Mock (deterministic filler, no cost)</option>
              <option value="openai">OpenAI (real, paid)</option>
              <option value="ollama">Ollama (real, self-hosted, free)</option>
              <option value="claude">Claude (real, paid)</option>
              <option value="gemini">Gemini / Google AI Studio (real, paid)</option>
            </Select>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <Label htmlFor="default_duration">Default Clip Duration (sec)</Label>
            <Input
              id="default_duration"
              type="number"
              value={form.default_clip_duration}
              onChange={(e) => setForm({ ...form, default_clip_duration: Number(e.target.value) })}
            />
          </div>
          <div>
            <Label htmlFor="max_clips">Max Clips Per Video</Label>
            <Input
              id="max_clips"
              type="number"
              value={form.max_clips_per_video}
              onChange={(e) => setForm({ ...form, max_clips_per_video: Number(e.target.value) })}
            />
          </div>
        </div>

        <div>
          <Label htmlFor="default_template">Default Template</Label>
          <Select
            id="default_template"
            value={form.default_template_id ?? ""}
            onChange={(e) => setForm({ ...form, default_template_id: e.target.value ? Number(e.target.value) : null })}
          >
            <option value="">None</option>
            {templatesRes?.data.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </Select>
        </div>

        <Button onClick={handleSave} loading={saving}>
          <Save className="size-4" />
          Save Settings
        </Button>
      </div>
    </Card>
  );
}
