import { HTMLAttributes } from "react";
import { clsx } from "clsx";

type Tone = "default" | "success" | "warning" | "danger" | "accent" | "muted";

const tones: Record<Tone, string> = {
  default: "bg-white/10 text-foreground",
  success: "bg-success/15 text-success",
  warning: "bg-warning/15 text-warning",
  danger: "bg-danger/15 text-danger",
  accent: "bg-accent/15 text-accent-2",
  muted: "bg-white/5 text-muted",
};

export function Badge({
  tone = "default",
  className,
  ...props
}: HTMLAttributes<HTMLSpanElement> & { tone?: Tone }) {
  return (
    <span
      className={clsx(
        "inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium",
        tones[tone],
        className
      )}
      {...props}
    />
  );
}

const STATUS_TONE: Record<string, Tone> = {
  completed: "success",
  published: "success",
  connected: "success",
  ready: "default",
  failed: "danger",
  error: "danger",
  revoked: "danger",
  expired: "warning",
  retrying: "warning",
  processing: "accent",
  rendering: "accent",
  analyzing: "accent",
  transcribing: "accent",
  uploading: "accent",
  generating_clips: "accent",
  queued: "muted",
  draft: "muted",
  scheduled: "accent",
  publishing: "accent",
  cancelled: "muted",
  pending: "muted",
  importing: "accent",
  skipped: "muted",
  running: "accent",
  completed_with_errors: "warning",
};

export function StatusBadge({ status }: { status: string }) {
  const tone = STATUS_TONE[status] ?? "default";
  const label = status.replace(/_/g, " ");
  return (
    <Badge tone={tone} className="capitalize">
      {label}
    </Badge>
  );
}
