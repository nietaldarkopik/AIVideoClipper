"use client";

import type { CoverTemplateConfig } from "@/lib/types";

/**
 * Live, WYSIWYG preview of a cover template, drawn in CSS over a real
 * (text-free) video frame.
 *
 * This deliberately mirrors FFmpegService::renderCoverImage()'s layout math
 * one-for-one — same wrap, same font scale, same line height, same paddings,
 * same block anchoring — just expressed in CSS at a smaller scale, so what an
 * admin tweaks here is what actually gets burned in. Any spacing/sizing change
 * on either side needs the matching change on the other.
 */

/** PHP's wordwrap($text, $width, "\n", true), reimplemented so lines break identically. */
function wordwrap(text: string, width: number): string[] {
  const out: string[] = [];
  for (const paragraph of text.split("\n")) {
    let line = "";
    for (const word of paragraph.split(" ")) {
      let w = word;
      // cut=true: a word longer than the limit is hard-split rather than overflowing.
      while (w.length > width) {
        if (line) {
          out.push(line);
          line = "";
        }
        out.push(w.slice(0, width));
        w = w.slice(width);
      }
      if (!line) line = w;
      else if (line.length + 1 + w.length <= width) line += " " + w;
      else {
        out.push(line);
        line = w;
      }
    }
    out.push(line);
  }
  return out;
}

function rgba(hex: string | undefined, opacity = 1): string {
  const clean = (hex ?? "#000000").replace("#", "");
  const r = parseInt(clean.slice(0, 2), 16) || 0;
  const g = parseInt(clean.slice(2, 4), 16) || 0;
  const b = parseInt(clean.slice(4, 6), 16) || 0;
  return `rgba(${r}, ${g}, ${b}, ${Math.max(0, Math.min(1, opacity))})`;
}

export function CoverLivePreview({
  config,
  aspectRatio,
  headline,
  frameUrl,
  widthPx = 260,
}: {
  config: CoverTemplateConfig;
  aspectRatio: "9:16" | "1:1" | "16:9";
  headline: string;
  frameUrl: string | null;
  widthPx?: number;
}) {
  // Matches AspectRatio::resolution() — the canvas the render actually targets.
  const [canvasW, canvasH] =
    aspectRatio === "16:9" ? [1920, 1080] : aspectRatio === "1:1" ? [1080, 1080] : [1080, 1920];
  const scale = widthPx / canvasW;
  const px = (v: number) => v * scale;

  const background = config.background ?? {};
  const kicker = config.kicker ?? {};
  const text = config.text ?? {};
  const subline = config.subline ?? {};
  const badge = config.badge ?? {};

  const uppercase = !!text.uppercase;
  const raw = (headline || "Tulis headline di sini").trim();
  const lines = wordwrap(uppercase ? raw.toUpperCase() : raw, Math.max(6, text.wrap_chars ?? 16));

  const fontSize = text.font_size || Math.round(canvasW * (text.font_scale ?? 0.085));
  const lineHeight = Math.round(fontSize * 1.28);
  const linePad = Math.round(fontSize * 0.18);
  const gap = Math.round(fontSize * 0.3);
  const marginY = Math.round(canvasH * 0.05);
  const marginX = Math.round(canvasW * 0.055);

  const kickerOn = !!kicker.enabled && !!(kicker.text ?? "").trim();
  const kickerFont = Math.round(canvasW * (kicker.font_scale ?? 0.045));
  const kickerPad = Math.round(kickerFont * 0.32);
  const kickerHeight = kickerFont + 2 * kickerPad;

  const sublineOn = !!subline.enabled && !!(subline.text ?? "").trim();
  const sublineFont = Math.round(canvasW * (subline.font_scale ?? 0.038));
  const sublinePad = Math.round(sublineFont * 0.32);
  const sublineHeight = sublineFont + 2 * sublinePad;

  const blockHeight =
    (kickerOn ? kickerHeight + gap : 0) + lines.length * lineHeight + (sublineOn ? gap + sublineHeight : 0);

  const position = text.position ?? "bottom";
  const blockTop =
    position === "top" ? marginY : position === "center" ? Math.round((canvasH - blockHeight) / 2) : canvasH - marginY - blockHeight;

  const alignLeft = (text.align ?? "center") === "left";
  const blockStyle = text.block_style ?? "lines";
  const bgOpacity = text.background_opacity ?? 0;
  const highlightMode = text.highlight_mode ?? "none";

  const gradient = background.gradient ?? {};
  const gradientFromTop = (gradient.position ?? "bottom") === "top";

  const badgeFont = Math.round(canvasW * 0.045);
  const badgePad = Math.round(badgeFont * 0.35);

  const isAccent = (i: number) =>
    highlightMode === "first_line"
      ? i === 0
      : highlightMode === "last_line"
        ? i === lines.length - 1
        : highlightMode === "alternate"
          ? i % 2 === 1
          : false;

  const shadowX = text.shadow_x ?? 0;
  const shadowY = text.shadow_y ?? 0;
  const strokeW = text.stroke_width ?? 0;

  // The render draws with DEFAULT_FONT_FILE (Arial) at its regular weight —
  // previewing in a bolder/different family would misreport how wide each
  // wrapped line actually comes out.
  const fontFamily = "Arial, Helvetica, sans-serif";

  return (
    <div
      className="relative overflow-hidden rounded-2xl bg-surface-elevated"
      style={{ width: widthPx, height: widthPx * (canvasH / canvasW) }}
    >
      {frameUrl ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={frameUrl} alt="" className="absolute inset-0 h-full w-full object-cover" />
      ) : (
        <div className="absolute inset-0 bg-gradient-to-br from-slate-700 to-slate-900" />
      )}

      {/* Flat color wash — background.overlay_* */}
      {(background.overlay_opacity ?? 0) > 0.001 && (
        <div
          className="absolute inset-0"
          style={{ background: rgba(background.overlay_color, background.overlay_opacity ?? 0) }}
        />
      )}

      {/* Darkening ramp — the render approximates this with stacked bands, CSS does it smoothly */}
      {gradient.enabled && (gradient.opacity ?? 0) > 0.001 && (
        <div
          className="absolute inset-x-0"
          style={{
            [gradientFromTop ? "top" : "bottom"]: 0,
            height: `${(gradient.size ?? 0.45) * 100}%`,
            background: `linear-gradient(${gradientFromTop ? "to bottom" : "to top"}, ${rgba(
              gradient.color,
              gradient.opacity ?? 0
            )}, transparent)`,
          }}
        />
      )}

      {/* Full-width bar behind the whole text block — text.block_style === 'band' */}
      {blockStyle === "band" && bgOpacity > 0.001 && (
        <div
          className="absolute inset-x-0"
          style={{
            top: px(Math.max(0, blockTop - marginY)),
            height: px(blockHeight + 2 * marginY),
            background: rgba(text.background, bgOpacity),
          }}
        />
      )}

      {/* Text block */}
      <div
        className="absolute flex flex-col"
        style={{
          top: px(blockTop),
          left: alignLeft ? px(marginX) : 0,
          right: alignLeft ? undefined : 0,
          alignItems: alignLeft ? "flex-start" : "center",
          maxWidth: alignLeft ? `calc(100% - ${px(marginX)}px)` : undefined,
        }}
      >
        {kickerOn && (
          <span
            style={{
              fontFamily,
              fontSize: px(kickerFont),
              lineHeight: 1,
              padding: `${px(kickerPad)}px ${px(kickerPad)}px`,
              marginBottom: px(gap),
              color: kicker.color ?? "#111111",
              background: rgba(kicker.background, kicker.background_opacity ?? 1),
              whiteSpace: "nowrap",
            }}
          >
            {(kicker.text ?? "").toUpperCase()}
          </span>
        )}

        {lines.map((line, i) => (
          <span
            key={i}
            style={{
              fontFamily,
              fontSize: px(fontSize),
              // ffmpeg's per-line box (boxborderw) is taller than the line
              // advance, so consecutive boxes overlap slightly — mimic that with
              // extra height pulled back by a negative margin, otherwise the
              // preview shows gaps the real render doesn't have.
              lineHeight: `${px(lineHeight + 2 * linePad)}px`,
              height: px(lineHeight + 2 * linePad),
              marginBottom: px(-2 * linePad),
              display: "inline-flex",
              alignItems: "center",
              padding: blockStyle === "lines" && bgOpacity > 0.001 ? `0 ${px(linePad)}px` : 0,
              background: blockStyle === "lines" && bgOpacity > 0.001 ? rgba(text.background, bgOpacity) : "transparent",
              color: isAccent(i) ? text.highlight_color ?? "#FFD100" : text.color ?? "#FFFFFF",
              whiteSpace: "nowrap",
              WebkitTextStroke: strokeW > 0 ? `${Math.max(0.4, px(strokeW))}px ${text.stroke_color ?? "#000"}` : undefined,
              paintOrder: "stroke fill",
              textShadow:
                shadowX || shadowY
                  ? `${px(shadowX)}px ${px(shadowY)}px 0 ${text.shadow_color ?? "#000000"}`
                  : undefined,
            }}
          >
            {line}
          </span>
        ))}

        {sublineOn && (
          <span
            style={{
              fontFamily,
              fontSize: px(sublineFont),
              lineHeight: 1,
              padding: `${px(sublinePad)}px ${px(sublinePad)}px`,
              marginTop: px(gap + 2 * linePad),
              color: subline.color ?? "#FFFFFF",
              background: rgba(subline.background, subline.background_opacity ?? 1),
              whiteSpace: "nowrap",
            }}
          >
            {subline.text}
          </span>
        )}
      </div>

      {badge.enabled && !!(badge.text ?? "").trim() && (
        <span
          className="absolute"
          style={{
            fontFamily,
            top: px(marginY),
            left: px(marginX),
            fontSize: px(badgeFont),
            lineHeight: 1,
            padding: `${px(badgePad)}px ${px(badgePad)}px`,
            color: badge.text_color ?? "#FFFFFF",
            background: badge.color ?? "#FF3B30",
          }}
        >
          {(badge.text ?? "").toUpperCase()}
        </span>
      )}
    </div>
  );
}
