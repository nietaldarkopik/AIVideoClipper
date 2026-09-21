"use client";

import { useEffect, useRef, useState } from "react";
import { formatCount } from "@/lib/format";

export interface TrendPoint {
  date: string;
  value: number;
}

const HEIGHT = 168;
const PADDING = { top: 12, right: 12, bottom: 24, left: 48 };

/**
 * Rounds an axis maximum up to a clean 1/2/5 × 10^n, so ticks read as 0 / 500 /
 * 1,000 rather than 0 / 431 / 862.
 */
function niceMax(value: number): number {
  if (value <= 0) return 1;
  const magnitude = 10 ** Math.floor(Math.log10(value));
  const normalized = value / magnitude;
  const step = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;

  return step * magnitude;
}

function formatDay(date: string): string {
  return new Date(`${date}T00:00:00`).toLocaleDateString("id-ID", { day: "numeric", month: "short" });
}

/**
 * A single-series area chart of one metric over time.
 *
 * Deliberately one series per chart: views and likes differ by two orders of
 * magnitude, and putting them on one plot would need a second y-axis — the
 * alignment of which is arbitrary and invents a correlation that isn't in the
 * data. Two small multiples say the same thing honestly. One series also means no
 * legend is needed: the heading names it.
 */
export function TrendChart({
  points,
  label,
  color,
  emptyHint,
}: {
  points: TrendPoint[];
  label: string;
  color: string;
  emptyHint?: string;
}) {
  const containerRef = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState(0);
  const [activeIndex, setActiveIndex] = useState<number | null>(null);

  // The SVG is drawn at real pixel size rather than being scaled by a viewBox:
  // stretching a viewBox to fit would distort the 2px stroke horizontally.
  useEffect(() => {
    const element = containerRef.current;
    if (!element) return;

    const observer = new ResizeObserver(([entry]) => setWidth(entry.contentRect.width));
    observer.observe(element);

    return () => observer.disconnect();
  }, []);

  const plotWidth = Math.max(0, width - PADDING.left - PADDING.right);
  const plotHeight = HEIGHT - PADDING.top - PADDING.bottom;
  const max = niceMax(Math.max(...points.map((p) => p.value), 0));
  const total = points.reduce((sum, p) => sum + p.value, 0);

  const x = (index: number) =>
    PADDING.left + (points.length <= 1 ? plotWidth / 2 : (index / (points.length - 1)) * plotWidth);
  const y = (value: number) => PADDING.top + plotHeight - (value / max) * plotHeight;

  const linePath = points.map((p, i) => `${i === 0 ? "M" : "L"}${x(i)},${y(p.value)}`).join(" ");
  const areaPath = points.length
    ? `${linePath} L${x(points.length - 1)},${PADDING.top + plotHeight} L${x(0)},${PADDING.top + plotHeight} Z`
    : "";

  const active = activeIndex !== null ? points[activeIndex] : null;

  /** Snaps to the nearest point: readers aim at a date, never at a 2px line. */
  function handlePointer(event: React.PointerEvent<SVGSVGElement>) {
    if (!points.length || plotWidth <= 0) return;

    const bounds = event.currentTarget.getBoundingClientRect();
    const offset = event.clientX - bounds.left - PADDING.left;
    const ratio = Math.min(1, Math.max(0, offset / plotWidth));

    setActiveIndex(Math.round(ratio * (points.length - 1)));
  }

  function handleKeyDown(event: React.KeyboardEvent<SVGSVGElement>) {
    if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
    event.preventDefault();

    const current = activeIndex ?? points.length - 1;
    const next = event.key === "ArrowLeft" ? current - 1 : current + 1;

    setActiveIndex(Math.min(points.length - 1, Math.max(0, next)));
  }

  return (
    <div>
      <div className="flex items-baseline justify-between gap-3">
        <h3 className="text-sm font-semibold">{label}</h3>
        <span className="text-xs text-muted">total {formatCount(total)}</span>
      </div>

      <div ref={containerRef} className="relative mt-3">
        {total === 0 && (
          <p className="absolute inset-x-0 top-1/2 -translate-y-1/2 text-center text-xs text-muted">
            {emptyHint ?? "Belum ada data tren"}
          </p>
        )}

        {width > 0 && (
          <svg
            width={width}
            height={HEIGHT}
            role="img"
            aria-label={`${label} per hari. Total ${total}.`}
            tabIndex={0}
            className="touch-none outline-none focus-visible:ring-1 focus-visible:ring-accent"
            onPointerMove={handlePointer}
            onPointerLeave={() => setActiveIndex(null)}
            onKeyDown={handleKeyDown}
            onBlur={() => setActiveIndex(null)}
          >
            {/* Recessive hairline gridlines — one step off the surface, solid. */}
            {[0, 0.5, 1].map((fraction) => (
              <g key={fraction}>
                <line
                  x1={PADDING.left}
                  x2={width - PADDING.right}
                  y1={y(max * fraction)}
                  y2={y(max * fraction)}
                  stroke="var(--color-border-subtle)"
                  strokeWidth={1}
                />
                <text
                  x={PADDING.left - 8}
                  y={y(max * fraction) + 3}
                  textAnchor="end"
                  className="fill-[var(--color-muted)] text-[10px]"
                >
                  {formatCount(Math.round(max * fraction))}
                </text>
              </g>
            ))}

            {total > 0 && (
              <>
                {/* A wash, never a saturated block. */}
                <path d={areaPath} fill={color} fillOpacity={0.1} />
                <path
                  d={linePath}
                  fill="none"
                  stroke={color}
                  strokeWidth={2}
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </>
            )}

            {active && (
              <>
                <line
                  x1={x(activeIndex!)}
                  x2={x(activeIndex!)}
                  y1={PADDING.top}
                  y2={PADDING.top + plotHeight}
                  stroke="var(--color-muted)"
                  strokeWidth={1}
                />
                {/* 2px ring in the surface colour keeps the dot legible where it
                    sits on the line. */}
                <circle
                  cx={x(activeIndex!)}
                  cy={y(active.value)}
                  r={4}
                  fill={color}
                  stroke="var(--color-surface)"
                  strokeWidth={2}
                />
              </>
            )}

            {/* First and last dates only — a tick per day collides on a 90-day range. */}
            {points.length > 0 && (
              <>
                <text x={PADDING.left} y={HEIGHT - 6} className="fill-[var(--color-muted)] text-[10px]">
                  {formatDay(points[0].date)}
                </text>
                <text
                  x={width - PADDING.right}
                  y={HEIGHT - 6}
                  textAnchor="end"
                  className="fill-[var(--color-muted)] text-[10px]"
                >
                  {formatDay(points[points.length - 1].date)}
                </text>
              </>
            )}
          </svg>
        )}

        {active && (
          // Value leads, label follows: the reader already knows the series and
          // wants the number.
          <div
            className="pointer-events-none absolute top-0 rounded-lg border border-border-subtle bg-surface-elevated px-2.5 py-1.5 text-xs shadow-lg"
            style={{
              left: Math.min(Math.max(x(activeIndex!) - 50, 0), Math.max(0, width - 100)),
            }}
          >
            <p className="font-semibold tabular-nums">{active.value.toLocaleString("id-ID")}</p>
            <p className="text-[11px] text-muted">{formatDay(active.date)}</p>
          </div>
        )}
      </div>
    </div>
  );
}
