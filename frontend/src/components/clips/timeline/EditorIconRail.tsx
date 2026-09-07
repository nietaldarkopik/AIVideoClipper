"use client";

import {
  Captions,
  Clapperboard,
  Crop,
  Image as ImageIcon,
  Info,
  Layers,
  Music,
  Palette,
  Send,
  Smile,
  Sparkles,
  Video as VideoIcon,
} from "lucide-react";

export type EditorTabKey =
  | "trim"
  | "layers"
  | "audio"
  | "captions"
  | "stickers"
  | "effects"
  | "filters"
  | "videoClips"
  | "details"
  | "reaction"
  | "cover"
  | "publish";

// Every entry here maps to a panel that actually does something end-to-end
// (editor → clip state → preview → render); nothing is listed just to look like
// CapCut's rail.
const RAIL_ITEMS: { key: EditorTabKey; label: string; icon: typeof Crop }[] = [
  { key: "trim", label: "Crop", icon: Crop },
  { key: "layers", label: "Elements", icon: Layers },
  { key: "audio", label: "Audio", icon: Music },
  { key: "captions", label: "Captions", icon: Captions },
  { key: "stickers", label: "Stickers", icon: Smile },
  { key: "effects", label: "Effects", icon: Sparkles },
  { key: "filters", label: "Filters", icon: Palette },
  { key: "videoClips", label: "Clips", icon: Clapperboard },
  { key: "details", label: "Details", icon: Info },
  { key: "reaction", label: "Reaction", icon: VideoIcon },
  { key: "cover", label: "Cover", icon: ImageIcon },
  { key: "publish", label: "Publish", icon: Send },
];

export function EditorIconRail({ active, onChange }: { active: EditorTabKey; onChange: (key: EditorTabKey) => void }) {
  return (
    <div className="flex shrink-0 flex-col gap-1 border-r border-border-subtle p-1.5">
      {RAIL_ITEMS.map((item) => {
        const Icon = item.icon;
        const selected = active === item.key;
        return (
          <button
            key={item.key}
            type="button"
            title={item.label}
            onClick={() => onChange(item.key)}
            className={
              "flex w-14 cursor-pointer flex-col items-center gap-1 rounded-lg px-1.5 py-2 text-[10px] font-medium " +
              (selected ? "bg-accent text-white" : "text-muted hover:bg-white/5 hover:text-foreground")
            }
          >
            <Icon className="size-4" />
            {item.label}
          </button>
        );
      })}
    </div>
  );
}
