"use client";

import { useCallback, useRef, useState } from "react";
import type { CropKeyframe } from "@/lib/types";

const MIN_ZOOM_FRACTION = 0.3; // crop window never shrinks below 30% of the max possible height

/**
 * Drag-to-pan + zoom-to-resize crop box over the full source frame — a manual
 * alternative to the AI smart-crop, in the same {x,y,width,height} SOURCE PIXEL
 * coordinate space FFmpegService's crop keyframes already use (see
 * buildCropSegments()), so this box's output slots directly into crop_config
 * with no conversion on the backend. The box's aspect ratio is locked to the
 * clip's target aspect ratio (you can't crop 9:16 out of a box shaped like
 * something else), matching how the smart-crop keyframes are always sized too.
 */
export function ManualCropEditor({
  videoUrl,
  posterUrl,
  sourceWidth,
  sourceHeight,
  targetAspect,
  keyframe,
  onChange,
}: {
  videoUrl: string | null;
  posterUrl?: string | null;
  sourceWidth: number;
  sourceHeight: number;
  targetAspect: { width: number; height: number };
  keyframe: CropKeyframe;
  onChange: (keyframe: CropKeyframe) => void;
}) {
  const frameRef = useRef<HTMLDivElement>(null);
  const [drag, setDrag] = useState<{ originX: number; originY: number; originClientX: number; originClientY: number } | null>(null);

  const maxHeight = sourceHeight;
  // Zoom is derived from the box's current height relative to the tallest
  // possible box (0 = zoomed out / full available height, 1 = most zoomed in).
  const minHeight = maxHeight * MIN_ZOOM_FRACTION;
  const zoom = maxHeight > minHeight ? 1 - (keyframe.height - minHeight) / (maxHeight - minHeight) : 0;

  const clampBox = useCallback(
    (x: number, y: number, width: number, height: number): CropKeyframe => {
      const w = Math.max(1, Math.min(width, sourceWidth));
      const h = Math.max(1, Math.min(height, sourceHeight));
      return {
        time: keyframe.time,
        x: Math.max(0, Math.min(x, sourceWidth - w)),
        y: Math.max(0, Math.min(y, sourceHeight - h)),
        width: w,
        height: h,
      };
    },
    [keyframe.time, sourceHeight, sourceWidth]
  );

  function handleZoomChange(z: number) {
    const height = maxHeight - z * (maxHeight - minHeight);
    const width = height * (targetAspect.width / targetAspect.height);
    // Re-center the box around its current middle when resizing, like a pinch-zoom.
    const centerX = keyframe.x + keyframe.width / 2;
    const centerY = keyframe.y + keyframe.height / 2;
    onChange(clampBox(centerX - width / 2, centerY - height / 2, width, height));
  }

  function handlePointerDown(e: React.PointerEvent) {
    (e.target as HTMLElement).setPointerCapture(e.pointerId);
    setDrag({ originX: keyframe.x, originY: keyframe.y, originClientX: e.clientX, originClientY: e.clientY });
  }

  function handlePointerMove(e: React.PointerEvent) {
    if (!drag) return;
    const rect = frameRef.current?.getBoundingClientRect();
    if (!rect || rect.width === 0) return;
    const scaleX = sourceWidth / rect.width;
    const scaleY = sourceHeight / rect.height;
    const dx = (e.clientX - drag.originClientX) * scaleX;
    const dy = (e.clientY - drag.originClientY) * scaleY;
    onChange(clampBox(drag.originX + dx, drag.originY + dy, keyframe.width, keyframe.height));
  }

  function handlePointerUp() {
    setDrag(null);
  }

  const boxStyle = {
    left: `${(keyframe.x / sourceWidth) * 100}%`,
    top: `${(keyframe.y / sourceHeight) * 100}%`,
    width: `${(keyframe.width / sourceWidth) * 100}%`,
    height: `${(keyframe.height / sourceHeight) * 100}%`,
  };

  return (
    <div>
      <div
        ref={frameRef}
        className="relative mx-auto w-full overflow-hidden rounded-xl bg-black select-none"
        style={{ aspectRatio: `${sourceWidth} / ${sourceHeight}` }}
      >
        {videoUrl ? (
          <video src={videoUrl} poster={posterUrl ?? undefined} muted playsInline className="pointer-events-none h-full w-full object-contain" />
        ) : (
          <div className="flex h-full items-center justify-center text-xs text-muted">No source video</div>
        )}

        {/* box-shadow with a huge spread dims everything outside the box itself,
            clipped to the frame by the parent's overflow-hidden */}
        <div
          onPointerDown={handlePointerDown}
          onPointerMove={handlePointerMove}
          onPointerUp={handlePointerUp}
          className="absolute cursor-grab border-2 border-accent shadow-[0_0_0_9999px_rgba(0,0,0,0.5)] active:cursor-grabbing"
          style={boxStyle}
        />
      </div>

      <div className="mt-3 flex items-center gap-3">
        <span className="text-[11px] text-muted">Zoom</span>
        <input
          type="range"
          min={0}
          max={1}
          step={0.01}
          value={zoom}
          onChange={(e) => handleZoomChange(Number(e.target.value))}
          className="w-full accent-accent"
        />
      </div>
      <p className="mt-1 text-[11px] text-muted">Drag the box to reposition, use the slider to zoom in/out.</p>
    </div>
  );
}
