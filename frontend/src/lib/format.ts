export function formatDuration(totalSeconds: number | null | undefined): string {
  if (totalSeconds == null || Number.isNaN(totalSeconds)) return "--:--";
  const s = Math.max(0, Math.round(totalSeconds));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  if (h > 0) {
    return `${h}:${String(m).padStart(2, "0")}:${String(sec).padStart(2, "0")}`;
  }
  return `${m}:${String(sec).padStart(2, "0")}`;
}

export function formatBytes(bytes: number | null | undefined): string {
  if (!bytes) return "--";
  const units = ["B", "KB", "MB", "GB"];
  let value = bytes;
  let unitIndex = 0;
  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024;
    unitIndex++;
  }
  return `${value.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
}

export function formatRelativeTime(dateStr: string | null | undefined): string {
  if (!dateStr) return "--";
  const date = new Date(dateStr);
  const diffMs = Date.now() - date.getTime();
  const diffSec = Math.round(diffMs / 1000);
  const diffMin = Math.round(diffSec / 60);
  const diffHour = Math.round(diffMin / 60);
  const diffDay = Math.round(diffHour / 24);

  if (diffSec < 60) return "just now";
  if (diffMin < 60) return `${diffMin} minute${diffMin === 1 ? "" : "s"} ago`;
  if (diffHour < 24) return `${diffHour} hour${diffHour === 1 ? "" : "s"} ago`;
  if (diffDay < 30) return `${diffDay} day${diffDay === 1 ? "" : "s"} ago`;
  return date.toLocaleDateString();
}

export function statusLabel(status: string): string {
  return status
    .split("_")
    .map((w) => w[0]?.toUpperCase() + w.slice(1))
    .join(" ");
}

/**
 * Countdown for a FUTURE timestamp (e.g. a channel's next scheduled research).
 * formatRelativeTime() only handles the past — feeding it a future date rounds a
 * negative difference and reports "just now" for something hours away.
 */
export function formatUpcomingTime(dateStr: string | null | undefined): string {
  if (!dateStr) return "--";
  const date = new Date(dateStr);
  const diffMin = Math.round((date.getTime() - Date.now()) / 60000);

  if (diffMin <= 0) return "segera";
  if (diffMin < 60) return `dalam ${diffMin} menit`;

  const diffHour = Math.round(diffMin / 60);
  if (diffHour < 24) return `dalam ${diffHour} jam`;

  return date.toLocaleString("id-ID", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
}

/** Wall-clock time only, for schedules that are already timezone-resolved. */
export function formatClock(dateStr: string | null | undefined): string {
  if (!dateStr) return "--";
  return new Date(dateStr).toLocaleString("id-ID", {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });
}

/**
 * A full-date section header for grouping items by day (e.g. content ideas by
 * research_date) — "Hari Ini", "Kemarin", or "Senin, 7 September 2026". Distinct
 * from formatClock(): this is a day-only label used as a group heading, not a
 * per-item timestamp.
 */
export function formatDayHeading(dateStr: string | null | undefined): string {
  if (!dateStr) return "Tanggal tidak diketahui";

  const date = new Date(dateStr.length <= 10 ? `${dateStr}T00:00:00` : dateStr);
  const today = new Date();
  const yesterday = new Date();
  yesterday.setDate(today.getDate() - 1);

  const sameDay = (a: Date, b: Date) =>
    a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();

  if (sameDay(date, today)) return "Hari Ini";
  if (sameDay(date, yesterday)) return "Kemarin";

  return date.toLocaleDateString("id-ID", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
}
