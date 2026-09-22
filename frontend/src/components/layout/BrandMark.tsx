import Image from "next/image";

/** ClipWize logo tile + wordmark ("Wize" carries the accent colour). */
export function BrandMark({ className = "" }: { className?: string }) {
  return (
    <span className={`flex items-center gap-2 ${className}`}>
      <Image src="/logo.png" alt="" width={32} height={32} className="size-8 rounded-lg" priority />
      <span className="text-sm font-semibold tracking-tight">
        Clip<span className="text-accent">Wize</span>
      </span>
    </span>
  );
}
