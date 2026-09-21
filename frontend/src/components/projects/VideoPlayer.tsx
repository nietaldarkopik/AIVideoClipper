"use client";

import { forwardRef } from "react";

export const VideoPlayer = forwardRef<
  HTMLVideoElement,
  { src: string; poster?: string | null; onTimeUpdate?: (time: number) => void }
>(function VideoPlayer({ src, poster, onTimeUpdate }, ref) {
  return (
    <video
      ref={ref}
      src={src}
      poster={poster ?? undefined}
      controls
      onTimeUpdate={onTimeUpdate ? (e) => onTimeUpdate(e.currentTarget.currentTime) : undefined}
      className="h-full w-full rounded-2xl bg-black"
    />
  );
});
