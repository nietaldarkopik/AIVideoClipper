"use client";

import { clsx } from "clsx";
import type { ReactionLayout } from "@/lib/types";

const LAYOUTS: { value: ReactionLayout; label: string }[] = [
  { value: "pip_bottom_right", label: "PIP — Bottom Right" },
  { value: "pip_bottom_left", label: "PIP — Bottom Left" },
  { value: "split_top_bottom", label: "Split — Top / Bottom" },
  { value: "split_side_by_side", label: "Split — Side by Side" },
];

// Webcam box geometry for each layout, as a scaled preview inside a 9:16 frame —
// mirrors the actual render geometry in FFmpegService::renderReactionClip().
const WEBCAM_BOX: Record<ReactionLayout, string> = {
  pip_bottom_right: "right-[6%] bottom-[6%] w-[32%] h-[18%]",
  pip_bottom_left: "left-[6%] bottom-[6%] w-[32%] h-[18%]",
  split_top_bottom: "inset-x-0 top-0 h-1/2",
  split_side_by_side: "inset-y-0 right-0 w-1/2",
};

export function ReactionLayoutPicker({
  value,
  onChange,
}: {
  value: ReactionLayout;
  onChange: (layout: ReactionLayout) => void;
}) {
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
      {LAYOUTS.map((layout) => {
        const active = value === layout.value;
        return (
          <button
            key={layout.value}
            type="button"
            onClick={() => onChange(layout.value)}
            className={clsx(
              "flex flex-col items-center gap-2 rounded-xl border p-2.5 text-center transition-colors cursor-pointer",
              active ? "border-accent bg-accent/10" : "border-border-subtle hover:border-accent/40"
            )}
          >
            <div className="relative aspect-[9/16] w-full max-w-[72px] overflow-hidden rounded-md bg-surface-elevated">
              <div className={clsx("absolute rounded-sm bg-accent/60 ring-1 ring-accent", WEBCAM_BOX[layout.value])} />
            </div>
            <span className="text-[11px] font-medium leading-tight">{layout.label}</span>
          </button>
        );
      })}
    </div>
  );
}
