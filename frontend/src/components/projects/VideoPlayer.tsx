"use client";

import { forwardRef } from "react";

export const VideoPlayer = forwardRef<HTMLVideoElement, { src: string; poster?: string | null }>(
  function VideoPlayer({ src, poster }, ref) {
    return (
      <video
        ref={ref}
        src={src}
        poster={poster ?? undefined}
        controls
        className="h-full w-full rounded-2xl bg-black"
      />
    );
  }
);
