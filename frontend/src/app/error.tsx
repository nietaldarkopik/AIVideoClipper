"use client";

import { useEffect } from "react";
import Link from "next/link";
import { AlertTriangle, RotateCcw } from "lucide-react";
import { Button } from "@/components/ui/Button";

/**
 * Catches render-time errors anywhere below the root layout. Without this file,
 * Next serves its own bare error screen in production — a white page with no way
 * back into the app, and no clue what happened.
 *
 * Deliberately not styled as a Card: this renders when something has already gone
 * wrong, so it depends on as little of the app's own component tree as possible.
 */
export default function AppError({ error, retry }: { error: Error & { digest?: string }; retry: () => void }) {
  useEffect(() => {
    // The user can't act on a stack trace, but whoever they report this to can.
    console.error(error);
  }, [error]);

  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center gap-4 px-6 text-center">
      <div className="flex size-12 items-center justify-center rounded-2xl bg-danger/15 text-danger">
        <AlertTriangle className="size-6" />
      </div>
      <div>
        <h1 className="text-lg font-semibold">Something broke on this page</h1>
        <p className="mt-1 max-w-md text-sm text-muted">
          The page failed to render. Your projects and clips are unaffected — this is a display error, not lost work.
        </p>
        {error.digest && (
          // Next strips error messages in production builds and leaves only this
          // digest, which is the one string that ties this screen to the matching
          // server log entry.
          <p className="mt-2 font-mono text-[11px] text-muted">Reference: {error.digest}</p>
        )}
      </div>
      <div className="flex flex-wrap items-center justify-center gap-2">
        {/* `retry`, not the older `reset` — the prop was renamed and stabilised in
            Next 16.3, which is the version this app is on. */}
        <Button onClick={() => retry()}>
          <RotateCcw className="size-4" />
          Try again
        </Button>
        <Link href="/dashboard">
          <Button variant="secondary">Back to dashboard</Button>
        </Link>
      </div>
    </div>
  );
}
