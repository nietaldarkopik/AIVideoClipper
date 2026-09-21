"use client";

import { useState } from "react";
import { api, ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { toast } from "@/store/toast";

/**
 * Redirects to TikTok's consent screen (AuthController::tiktokRedirect). The
 * callback (AuthController::tiktokCallback) always lands back on /login with
 * either ?tiktok_token=... or ?error=... — see useTikTokLoginCallback, used
 * by the login page to pick that up regardless of which page started the flow.
 */
export function TikTokLoginButton({ label = "Continue with TikTok" }: { label?: string }) {
  const [loading, setLoading] = useState(false);

  async function handleClick() {
    setLoading(true);
    try {
      const { authorization_url } = await api.get<{ authorization_url: string }>("/auth/tiktok/redirect");
      window.location.href = authorization_url;
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Could not start TikTok login.", "danger");
      setLoading(false);
    }
  }

  return (
    <Button type="button" variant="secondary" className="w-full" loading={loading} onClick={handleClick}>
      <span className="flex items-center justify-center gap-2">
        <TikTokGlyph />
        {label}
      </span>
    </Button>
  );
}

function TikTokGlyph() {
  return (
    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true">
      <path d="M16.6 5.82c-.94-.65-1.6-1.62-1.83-2.75h-3.03v13.06c0 1.5-1.22 2.72-2.72 2.72a2.72 2.72 0 0 1 0-5.44c.28 0 .55.04.8.12V10.5a5.75 5.75 0 0 0-.8-.06 5.75 5.75 0 1 0 5.75 5.75V9.4a8.6 8.6 0 0 0 4.83 1.48V7.85c-1.09 0-2.1-.36-2.9-.98a5.62 5.62 0 0 1-.1-1.05Z" />
    </svg>
  );
}
