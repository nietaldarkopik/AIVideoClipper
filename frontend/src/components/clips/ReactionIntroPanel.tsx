"use client";

import { useState } from "react";
import { mutate } from "swr";
import { Sparkles, Mic, Link2 } from "lucide-react";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Input, Label, Select, Textarea } from "@/components/ui/Input";
import { Badge } from "@/components/ui/Badge";
import type { Clip } from "@/lib/types";

const VOICES = ["alloy", "echo", "fable", "onyx", "nova", "shimmer"];

interface ReactionIntroPanelProps {
  clipId: number;
  reactionScript: string;
  reactionTone: Clip["reaction_tone"];
  introEnabled: boolean;
  outroEnabled: boolean;
  introVoice: string;
  referenceUrl: string | null;
  onChange: (
    patch: Partial<{
      reaction_script: string;
      intro_enabled: boolean;
      outro_enabled: boolean;
      intro_voice: string;
      reference_url: string | null;
    }>
  ) => void;
}

export function ReactionIntroPanel({
  clipId,
  reactionScript,
  reactionTone,
  introEnabled,
  outroEnabled,
  introVoice,
  referenceUrl,
  onChange,
}: ReactionIntroPanelProps) {
  const [generating, setGenerating] = useState(false);

  async function handleGenerate() {
    setGenerating(true);
    try {
      const res = await api.post<{ data: Clip }>(`/clips/${clipId}/generate-reaction-script`, {
        voice: introVoice || undefined,
        reference_url: referenceUrl || undefined,
      });
      onChange({
        reaction_script: res.data.reaction_script ?? "",
        intro_enabled: res.data.intro_enabled,
        intro_voice: res.data.intro_voice ?? introVoice,
        reference_url: res.data.reference_url ?? null,
      });
      await mutate(`/clips/${clipId}`);
      toast("Reaction intro generated.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to generate reaction script.", "danger");
    } finally {
      setGenerating(false);
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm font-medium">AI Reaction Intro</p>
          <p className="text-xs text-muted">
            A short AI hot-take, narrated over a cover screen before the clip plays.
          </p>
        </div>
        {reactionTone && (
          <Badge tone={reactionTone === "positive" ? "success" : "accent"} className="capitalize">
            {reactionTone}
          </Badge>
        )}
      </div>

      <div>
        <Label htmlFor="reference_url">
          <Link2 className="mr-1 inline size-3" />
          Reference URL (optional)
        </Label>
        <Input
          id="reference_url"
          type="url"
          placeholder="https://example.com/the-article-this-clip-reacts-to"
          value={referenceUrl ?? ""}
          onChange={(e) => onChange({ reference_url: e.target.value || null })}
        />
        <p className="mt-1 text-[11px] text-muted">
          Fetched as extra context for the reaction script (and social metadata generation below).
        </p>
      </div>

      <Button variant="secondary" className="w-full" onClick={handleGenerate} loading={generating}>
        <Sparkles className="size-4" />
        {reactionScript ? "Regenerate Reaction" : "Generate Reaction Intro"}
      </Button>

      {reactionScript && (
        <>
          <div>
            <Label htmlFor="reaction_script">Reaction script</Label>
            <Textarea
              id="reaction_script"
              rows={2}
              value={reactionScript}
              onChange={(e) => onChange({ reaction_script: e.target.value })}
            />
            <p className="mt-1 text-[11px] text-muted">
              Editing this clears the cached narration — it re-synthesizes on the next Save & Re-render.
            </p>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <Label htmlFor="intro_voice">
                <Mic className="mr-1 inline size-3" />
                Voice
              </Label>
              <Select id="intro_voice" value={introVoice} onChange={(e) => onChange({ intro_voice: e.target.value })}>
                {VOICES.map((v) => (
                  <option key={v} value={v} className="capitalize">
                    {v}
                  </option>
                ))}
              </Select>
            </div>
            <div className="flex flex-col justify-center gap-2 pt-5">
              <label className="flex items-center gap-2 text-xs text-muted">
                <input
                  type="checkbox"
                  checked={introEnabled}
                  onChange={(e) => onChange({ intro_enabled: e.target.checked })}
                  className="size-4 rounded accent-accent"
                />
                Play intro before clip
              </label>
              <label className="flex items-center gap-2 text-xs text-muted">
                <input
                  type="checkbox"
                  checked={outroEnabled}
                  onChange={(e) => onChange({ outro_enabled: e.target.checked })}
                  className="size-4 rounded accent-accent"
                />
                Add outro card after clip
              </label>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
