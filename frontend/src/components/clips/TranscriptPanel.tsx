"use client";

import { useEffect, useMemo, useRef, type ReactNode } from "react";
import { FileText } from "lucide-react";
import { formatDuration } from "@/lib/format";

export interface TranscriptLine {
  start: number;
  end: number;
  text: string;
}

/**
 * YouTube-style transcript panel: every line as a clickable, timestamped row,
 * auto-scrolling to keep whichever one is currently playing in view. Reused by
 * two different callers on two different time axes:
 *  - the clip editor (frontend/src/app/(app)/clips/[id]/page.tsx), showing
 *    clip.caption_cues in CLIP-RELATIVE (output) time;
 *  - the project page's source-video player, showing the video's full
 *    Transcript.segments in SOURCE-video time.
 * This component doesn't care which axis `time`/`onSeek` are in — converting
 * between them (see timelineMath.ts's clipTimeToSource/sourceTimeToClip) is
 * entirely the caller's job, same as it already is for the timeline's own
 * onSeek.
 *
 * `downloadAction` is a slot, not a URL, because the two callers need
 * different download mechanisms: the clip's .srt lives on the public
 * /api/media/* stream (plain <a href>), while the full video's transcript
 * endpoint sits behind auth:sanctum (needs an authenticated fetch-then-blob
 * download, not a plain link the browser can't attach a token to).
 */
export function TranscriptPanel({
  lines,
  time,
  onSeek,
  downloadAction,
  emptyLabel = "No transcript available yet.",
  className,
}: {
  lines: TranscriptLine[];
  time: number;
  onSeek: (time: number) => void;
  downloadAction?: ReactNode;
  emptyLabel?: string;
  className?: string;
}) {
  const activeIndex = useMemo(() => {
    for (let i = 0; i < lines.length; i++) {
      if (time >= lines[i].start && time < lines[i].end) return i;
    }
    return -1;
  }, [lines, time]);

  const listRef = useRef<HTMLDivElement>(null);
  const activeRowRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    activeRowRef.current?.scrollIntoView({ block: "nearest", behavior: "smooth" });
  }, [activeIndex]);

  return (
    <div className={`flex flex-col rounded-xl border border-border-subtle bg-surface-elevated ${className ?? ""}`}>
      <div className="flex items-center justify-between gap-2 border-b border-border-subtle px-3 py-2">
        <div className="flex items-center gap-1.5 text-xs font-medium">
          <FileText className="size-3.5 text-muted" />
          Transcript
        </div>
        {downloadAction}
      </div>

      <div ref={listRef} className="max-h-80 overflow-y-auto p-1.5">
        {lines.length === 0 ? (
          <p className="p-3 text-center text-xs text-muted">{emptyLabel}</p>
        ) : (
          lines.map((line, i) => (
            <button
              key={i}
              ref={i === activeIndex ? activeRowRef : undefined}
              type="button"
              onClick={() => onSeek(line.start)}
              className={`flex w-full items-start gap-2.5 rounded-lg px-2 py-1.5 text-left text-sm cursor-pointer transition-colors ${
                i === activeIndex ? "bg-accent/15 text-accent-2" : "text-foreground hover:bg-white/5"
              }`}
            >
              <span className={`mt-0.5 shrink-0 font-mono text-xs ${i === activeIndex ? "text-accent-2" : "text-muted"}`}>
                {formatDuration(line.start)}
              </span>
              <span className="min-w-0 flex-1">{line.text}</span>
            </button>
          ))
        )}
      </div>
    </div>
  );
}
