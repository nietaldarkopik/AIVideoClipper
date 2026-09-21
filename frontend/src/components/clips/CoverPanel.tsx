"use client";

import { useState } from "react";
import { mutate } from "swr";
import { Sparkles } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Input, Label, Select } from "@/components/ui/Input";
import type { Clip, CoverTemplate } from "@/lib/types";

interface CoverPanelProps {
  clipId: number;
  clipReady: boolean;
  coverTemplateId: number | null;
  coverText: string | null;
  coverKicker: string | null;
  coverSubline: string | null;
  coverUrl: string | null;
  /** Short headline variants the clip analysis wrote for this moment. */
  titleOptions: string[];
  /** Even shorter kicker/subline variants from the same analysis. */
  subtitleOptions: string[];
  onChange: (
    patch: Partial<{ cover_template_id: number | null; cover_text: string | null; cover_kicker: string | null; cover_subline: string | null }>
  ) => void;
}

/** Clickable AI suggestion chips — clicking one fills the field it belongs to. */
function Suggestions({ options, active, onPick }: { options: string[]; active: string | null; onPick: (v: string) => void }) {
  if (options.length === 0) return null;

  return (
    <div className="mt-1.5 flex flex-wrap gap-1.5">
      {options.map((option) => (
        <button
          key={option}
          type="button"
          onClick={() => onPick(option)}
          className={
            "rounded-lg px-2 py-1 text-[11px] transition-colors cursor-pointer " +
            (active === option
              ? "bg-accent text-white"
              : "bg-surface-elevated text-muted hover:bg-white/10 hover:text-foreground")
          }
        >
          {option}
        </button>
      ))}
    </div>
  );
}

export function CoverPanel({
  clipId,
  clipReady,
  coverTemplateId,
  coverText,
  coverKicker,
  coverSubline,
  coverUrl,
  titleOptions,
  subtitleOptions,
  onChange,
}: CoverPanelProps) {
  const { data: templatesRes } = useApi<{ data: CoverTemplate[] }>("/cover-templates");
  const [generating, setGenerating] = useState(false);

  const selected = templatesRes?.data.find((t) => t.id === coverTemplateId);
  const kickerSlot = !!selected?.config?.kicker?.enabled;
  const sublineSlot = !!selected?.config?.subline?.enabled;

  async function handleGenerate() {
    if (!coverTemplateId) {
      toast("Pick a cover template first.", "danger");
      return;
    }
    setGenerating(true);
    try {
      const res = await api.post<{ data: Clip }>(`/clips/${clipId}/generate-cover`, {
        cover_template_id: coverTemplateId,
        text: coverText || undefined,
        kicker: coverKicker ?? "",
        subline: coverSubline ?? "",
      });
      onChange({
        cover_template_id: res.data.cover_template_id,
        cover_text: res.data.cover_text,
        cover_kicker: res.data.cover_kicker,
        cover_subline: res.data.cover_subline,
      });
      await mutate(`/clips/${clipId}`);
      toast("Cover generated.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to generate cover.", "danger");
    } finally {
      setGenerating(false);
    }
  }

  return (
    <div className="space-y-4">
      <p className="text-xs text-muted">
        Grabs a real frame from this clip&apos;s rendered video and burns in the headline — uploaded alongside the
        post on platforms that support a custom thumbnail (YouTube, Facebook).
      </p>

      {!clipReady && (
        <p className="rounded-lg bg-amber-500/10 p-3 text-xs text-amber-500">
          Render the clip first — a cover needs a rendered video to grab a frame from.
        </p>
      )}

      <div>
        <Label>Cover Template</Label>
        <Select
          value={coverTemplateId ?? ""}
          onChange={(e) => onChange({ cover_template_id: e.target.value ? Number(e.target.value) : null })}
        >
          <option value="">No cover template</option>
          {templatesRes?.data.map((t) => (
            <option key={t.id} value={t.id}>
              {t.name} ({t.aspect_ratio})
            </option>
          ))}
        </Select>
      </div>

      <div>
        <Label>Headline</Label>
        <Input
          value={coverText ?? ""}
          onChange={(e) => onChange({ cover_text: e.target.value })}
          placeholder="Defaults to this clip's hook if left blank"
          maxLength={120}
        />
        <Suggestions options={titleOptions} active={coverText} onPick={(v) => onChange({ cover_text: v })} />
      </div>

      {kickerSlot && (
        <div>
          <Label>Kicker (small label above)</Label>
          <Input
            value={coverKicker ?? ""}
            onChange={(e) => onChange({ cover_kicker: e.target.value })}
            placeholder={selected?.config?.kicker?.text ?? "e.g. FAKTA BARU"}
            maxLength={30}
          />
          <Suggestions options={subtitleOptions} active={coverKicker} onPick={(v) => onChange({ cover_kicker: v })} />
        </div>
      )}

      {sublineSlot && (
        <div>
          <Label>Subline (small label below)</Label>
          <Input
            value={coverSubline ?? ""}
            onChange={(e) => onChange({ cover_subline: e.target.value })}
            placeholder={selected?.config?.subline?.text ?? "e.g. TONTON SAMPAI HABIS"}
            maxLength={40}
          />
          <Suggestions options={subtitleOptions} active={coverSubline} onPick={(v) => onChange({ cover_subline: v })} />
        </div>
      )}

      {coverTemplateId && !kickerSlot && !sublineSlot && (
        <p className="text-xs text-muted">
          This template&apos;s design has no kicker or subline — enable them on the cover template to use the
          shorter AI labels.
        </p>
      )}

      <Button className="w-full" onClick={handleGenerate} loading={generating} disabled={!clipReady || !coverTemplateId}>
        <Sparkles className="size-4" />
        {coverUrl ? "Regenerate Cover" : "Generate Cover"}
      </Button>

      {coverUrl && (
        <div className="overflow-hidden rounded-xl border border-border-subtle">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={coverUrl} alt="Cover preview" className="w-full object-cover" />
        </div>
      )}
    </div>
  );
}
