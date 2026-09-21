"use client";

import { ArrowLeft, Film, Plus, Trash2 } from "lucide-react";
import { Input, Label } from "@/components/ui/Input";
import { formatDuration } from "@/lib/format";
import type { AdditionalVideoClip, TransitionIn, Video } from "@/lib/types";

/**
 * Extra videos appended AFTER the main clip's own footage — each cut from a
 * DIFFERENT source video in the same project (see
 * RenderClipJob::renderAdditionalVideoClips()). A CapCut-style multi-track
 * editor would let these live as ordinary blocks on the SAME interactive
 * video track as the main clip's segments; this app's video track is built
 * around one continuous source video's own filmstrip/waveform axis, and
 * unifying a genuinely different source video onto that same axis (its own
 * timeline position, its own preview scrubbing, its own drag/resize) is a
 * substantially larger rework than this pass takes on. Instead: a simple,
 * honest list — pick a video, trim it with plain start/end fields, order is
 * append-only (matching "additional clips play after the main one"), reorder
 * isn't offered.
 *
 * Each entry's own crop/watermark is handled entirely server-side
 * (RenderClipJob::renderAdditionalVideoClips()); there's deliberately no
 * captions/layers/effects editing for these yet — those are keyed to the
 * MAIN clip's own template and transcript, with no obvious per-additional-
 * clip equivalent without a larger redesign. The preview only shows the main
 * clip's own footage — it does not simulate an additional clip playing after
 * it.
 */
export function AdditionalClipsPanel({
  projectVideos,
  clips,
  onChange,
  selectedIndex,
  onSelect,
}: {
  projectVideos: Video[];
  clips: AdditionalVideoClip[];
  onChange: (clips: AdditionalVideoClip[]) => void;
  selectedIndex: number | null;
  onSelect: (index: number | null) => void;
}) {
  const selected = selectedIndex != null ? clips[selectedIndex] : undefined;

  function add(video: Video) {
    const duration = video.duration_seconds ?? 5;
    const entry: AdditionalVideoClip = { video_id: video.id, start: 0, end: Math.min(duration, 5) };
    onChange([...clips, entry]);
    onSelect(clips.length);
  }

  function update(patch: Partial<AdditionalVideoClip>) {
    if (selectedIndex == null) return;
    onChange(clips.map((c, i) => (i === selectedIndex ? { ...c, ...patch } : c)));
  }

  function remove(index: number) {
    onChange(clips.filter((_, i) => i !== index));
    if (selectedIndex === index) onSelect(null);
  }

  if (selected) {
    const video = projectVideos.find((v) => v.id === selected.video_id);
    const maxDuration = video?.duration_seconds ?? undefined;
    return (
      <div className="space-y-4">
        <button
          type="button"
          onClick={() => onSelect(null)}
          className="flex cursor-pointer items-center gap-1 text-xs text-muted hover:text-foreground"
        >
          <ArrowLeft className="size-3" />
          All video clips
        </button>

        <div className="flex items-center gap-2 rounded-xl bg-surface-elevated px-3 py-2.5">
          {video?.thumbnail_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={video.thumbnail_url} alt="" className="h-10 w-10 rounded-lg object-cover" />
          ) : (
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-black/30">
              <Film className="size-4 text-muted" />
            </div>
          )}
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm">{video?.title || video?.original_filename || "Video"}</p>
            <p className="text-[11px] text-muted">
              {video?.duration_seconds != null ? formatDuration(video.duration_seconds) : "Unknown length"} source
            </p>
          </div>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div>
            <Label>Start (s)</Label>
            <Input
              type="number"
              min={0}
              step={0.1}
              max={maxDuration}
              value={selected.start}
              onChange={(e) => update({ start: Math.max(0, Number(e.target.value)) })}
            />
          </div>
          <div>
            <Label>End (s)</Label>
            <Input
              type="number"
              min={0}
              step={0.1}
              max={maxDuration}
              value={selected.end}
              onChange={(e) => update({ end: Math.max(0, Number(e.target.value)) })}
            />
          </div>
        </div>

        {selectedIndex! > 0 && (
          <TransitionFieldsForAdditionalClip
            transition={selected.transition_in}
            onChange={(transition_in) => update({ transition_in })}
          />
        )}
        {selectedIndex === 0 && (
          <p className="text-[11px] text-muted">
            This is the first additional clip — its transition (set below the video track) is what cuts INTO it from
            the main clip.
          </p>
        )}

        <button
          type="button"
          onClick={() => remove(selectedIndex!)}
          className="flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-xl border border-danger/30 px-3 py-2 text-xs text-danger hover:bg-danger/10"
        >
          <Trash2 className="size-3.5" />
          Remove this clip
        </button>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div>
        <h4 className="mb-2 text-xs font-semibold text-muted">Add a video from this project</h4>
        {projectVideos.length === 0 ? (
          <p className="text-[11px] text-muted">
            No other videos in this project yet — import another source video first.
          </p>
        ) : (
          <div className="space-y-1.5">
            {projectVideos.map((video) => (
              <button
                key={video.id}
                type="button"
                onClick={() => add(video)}
                className="flex w-full cursor-pointer items-center gap-2 rounded-xl border border-border-subtle bg-surface-elevated px-2.5 py-2 text-left hover:border-accent"
              >
                {video.thumbnail_url ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={video.thumbnail_url} alt="" className="h-9 w-9 shrink-0 rounded-lg object-cover" />
                ) : (
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-black/30">
                    <Film className="size-3.5 text-muted" />
                  </div>
                )}
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-xs">{video.title || video.original_filename || "Video"}</span>
                  <span className="block text-[10px] text-muted">
                    {video.duration_seconds != null ? formatDuration(video.duration_seconds) : "Unknown length"}
                  </span>
                </span>
                <Plus className="size-3.5 shrink-0 text-accent-2" />
              </button>
            ))}
          </div>
        )}
      </div>

      {clips.length > 0 && (
        <div>
          <h4 className="mb-2 text-xs font-semibold text-muted">On this clip, in order</h4>
          <div className="space-y-1.5">
            {clips.map((clip, index) => {
              const video = projectVideos.find((v) => v.id === clip.video_id);
              return (
                <button
                  key={index}
                  type="button"
                  onClick={() => onSelect(index)}
                  className="flex w-full cursor-pointer items-center justify-between rounded-xl bg-surface-elevated px-3 py-2 text-left text-xs hover:bg-white/5"
                >
                  <span className="truncate">{video?.title || video?.original_filename || "Video"}</span>
                  <span className="shrink-0 text-muted">
                    {formatDuration(clip.start)} – {formatDuration(clip.end)}
                    {clip.transition_in ? ` · ${clip.transition_in.type}` : ""}
                  </span>
                </button>
              );
            })}
          </div>
        </div>
      )}

      <p className="text-[11px] text-muted">
        Each one is rendered on its own (smart-cropped to match, watermarked the same as the main clip) and appended
        in this order. Captions, text and effects apply only to the main clip for now.
      </p>
    </div>
  );
}

// TransitionFields (in the clip editor page) is typed against Segment, but
// only ever reads transition_in off whatever it's given — AdditionalVideoClip
// carries the same {start, end, transition_in} shape, so passing it straight
// through works without an adapter. This tiny wrapper exists only so this
// file doesn't need to import the page's private TransitionFields component,
// which isn't exported; instead it reimplements the same small type-select +
// duration-slider pair, kept in sync with TRANSITION_TYPES's meaning by using
// the shared TransitionIn/TransitionType types.
function TransitionFieldsForAdditionalClip({
  transition,
  onChange,
}: {
  transition: TransitionIn | null | undefined;
  onChange: (t: TransitionIn | null) => void;
}) {
  const type = transition?.type ?? "none";
  return (
    <div className="space-y-3">
      <div>
        <Label>Transition in</Label>
        <div className="mt-1.5 grid grid-cols-3 gap-1.5">
          {(["none", "fade", "dissolve"] as const).map((value) => (
            <button
              key={value}
              type="button"
              onClick={() =>
                onChange(value === "none" ? null : { type: value, duration: transition?.duration ?? 0.4 })
              }
              className={
                "rounded-lg px-2.5 py-1.5 text-xs capitalize cursor-pointer " +
                (type === value ? "bg-accent text-white" : "bg-surface-elevated text-muted hover:text-foreground")
              }
            >
              {value === "none" ? "None" : value}
            </button>
          ))}
        </div>
      </div>
      {transition && (
        <div>
          <Label>Duration ({transition.duration.toFixed(2)}s)</Label>
          <input
            type="range"
            min={0.1}
            max={2}
            step={0.05}
            value={transition.duration}
            onChange={(e) => onChange({ type: transition.type, duration: Number(e.target.value) })}
            className="mt-2.5 w-full accent-accent"
          />
        </div>
      )}
    </div>
  );
}
