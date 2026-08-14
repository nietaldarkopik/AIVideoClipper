import { clsx } from "clsx";

export function ProgressBar({
  value,
  className,
  tone = "accent",
}: {
  value: number;
  className?: string;
  tone?: "accent" | "success" | "danger";
}) {
  const barColor =
    tone === "success" ? "bg-success" : tone === "danger" ? "bg-danger" : "bg-accent";
  return (
    <div className={clsx("h-1.5 w-full overflow-hidden rounded-full bg-white/10", className)}>
      <div
        className={clsx("h-full rounded-full transition-all duration-500", barColor)}
        style={{ width: `${Math.max(0, Math.min(100, value))}%` }}
      />
    </div>
  );
}
