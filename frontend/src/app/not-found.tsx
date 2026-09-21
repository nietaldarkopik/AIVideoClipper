import Link from "next/link";
import { Compass } from "lucide-react";
import { Button } from "@/components/ui/Button";

/**
 * Shown for unknown URLs and for any page that calls notFound() — most usefully a
 * detail page whose record was deleted, which is otherwise reachable from a stale
 * bookmark or a link in an older toast.
 */
export default function NotFound() {
  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center gap-4 px-6 text-center">
      <div className="flex size-12 items-center justify-center rounded-2xl bg-surface-elevated text-muted">
        <Compass className="size-6" />
      </div>
      <div>
        <h1 className="text-lg font-semibold">Page not found</h1>
        <p className="mt-1 max-w-md text-sm text-muted">
          This page doesn&apos;t exist, or the project, clip, or template it pointed at was deleted.
        </p>
      </div>
      <Link href="/dashboard">
        <Button>Back to dashboard</Button>
      </Link>
    </div>
  );
}
