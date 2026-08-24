"use client";

import { Play, Sparkles, CheckCircle2, Video } from "lucide-react";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { formatDuration } from "@/lib/format";
import type { ClipCandidate } from "@/lib/types";

const MOMENT_EMOJI: Record<string, string> = {
  hook: "🔥",
  question: "❓",
  emotional: "❤️",
  funny: "😂",
  controversial: "⚡",
  educational: "🎓",
  story_peak: "📈",
  conclusion: "✅",
};

function scoreTone(score: number): "success" | "warning" | "danger" {
  if (score >= 85) return "success";
  if (score >= 70) return "warning";
  return "danger";
}

export function CandidateCard({
  candidate,
  onPreview,
  onGenerate,
  onReact,
  generating,
}: {
  candidate: ClipCandidate;
  onPreview: () => void;
  onGenerate: () => void;
  onReact: () => void;
  generating: boolean;
}) {
  const alreadyGenerated = candidate.status === "generated";

  return (
    <Card className="p-5">
      <div className="flex items-start justify-between gap-3">
        <div>
          <div className="flex items-center gap-2">
            <span className="text-lg">{MOMENT_EMOJI[candidate.moment_type] ?? "✨"}</span>
            <span className="text-sm font-semibold capitalize">
              {candidate.moment_type.replace(/_/g, " ")} — {candidate.scores.overall}/100
            </span>
          </div>
          <p className="mt-1 text-xs text-muted">
            {formatDuration(candidate.start_time)} → {formatDuration(candidate.end_time)} · {formatDuration(candidate.duration)}
          </p>
        </div>
        <Badge tone={scoreTone(candidate.scores.overall)}>{candidate.scores.overall}/100</Badge>
      </div>

      <p className="mt-3 rounded-xl bg-surface-elevated px-3.5 py-2.5 text-sm italic text-foreground">
        &ldquo;{candidate.hook_text}&rdquo;
      </p>

      <div className="mt-3 grid grid-cols-3 gap-2 text-center">
        {[
          ["Hook", candidate.scores.hook],
          ["Story", candidate.scores.story],
          ["Emotional", candidate.scores.emotional],
          ["Info", candidate.scores.information],
          ["Engagement", candidate.scores.engagement],
          ["Viral", candidate.scores.viral_potential],
        ].map(([label, value]) => (
          <div key={label as string} className="rounded-lg bg-surface px-2 py-1.5">
            <p className="text-[10px] text-muted">{label}</p>
            <p className="text-xs font-semibold">{value}</p>
          </div>
        ))}
      </div>

      <div className="mt-3 space-y-1">
        {candidate.reasons.map((reason, i) => (
          <div key={i} className="flex items-center gap-1.5 text-xs text-muted">
            <CheckCircle2 className="size-3 shrink-0 text-success" />
            {reason}
          </div>
        ))}
      </div>

      <div className="mt-3 flex flex-wrap gap-1">
        {candidate.suggested_hashtags.slice(0, 4).map((tag) => (
          <span key={tag} className="text-xs text-accent-2">
            {tag}
          </span>
        ))}
      </div>

      <div className="mt-4 flex gap-2">
        <Button variant="outline" size="sm" className="flex-1" onClick={onPreview}>
          <Play className="size-3.5" />
          Preview
        </Button>
        <Button variant="outline" size="sm" className="flex-1" onClick={onReact}>
          <Video className="size-3.5" />
          React
        </Button>
        <Button
          size="sm"
          className="flex-1"
          onClick={onGenerate}
          loading={generating}
          disabled={alreadyGenerated}
        >
          <Sparkles className="size-3.5" />
          {alreadyGenerated ? "Generated" : "Generate"}
        </Button>
      </div>
    </Card>
  );
}
