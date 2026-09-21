"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

/**
 * /api/media/* only serves URLs carrying an expiring signature, and
 * <img>/<audio> can't attach a bearer token. Paths the client holds (a layer's
 * image_path, a waveform sidecar, ...) are therefore exchanged for signed URLs
 * via POST /media/sign, which checks ownership. Requests made in the same tick
 * are batched, and results are cached until shortly before they expire.
 */
const SAFETY_MS = 5 * 60 * 1000;
const FALLBACK_TTL_MS = 30 * 60 * 1000;

const cache = new Map<string, { url: string | null; until: number }>();
const waiting = new Map<string, Array<(url: string | null) => void>>();
let scheduled = false;

function expiryOf(url: string): number {
  const expires = Number(new URL(url).searchParams.get("expires"));
  return expires ? expires * 1000 - SAFETY_MS : Date.now() + FALLBACK_TTL_MS;
}

async function flush() {
  scheduled = false;
  const batch = [...waiting.entries()];
  waiting.clear();
  const paths = batch.map(([path]) => path);
  let urls: Record<string, string | null> = {};
  try {
    const res = await api.post<{ data: Record<string, string | null> }>("/media/sign", { paths });
    urls = res.data;
  } catch {
    // Leave everything unresolved (null) and don't cache, so a retry can succeed.
    batch.forEach(([, resolvers]) => resolvers.forEach((r) => r(null)));
    return;
  }
  for (const [path, resolvers] of batch) {
    const url = urls[path] ?? null;
    cache.set(path, { url, until: url ? expiryOf(url) : Date.now() + SAFETY_MS });
    resolvers.forEach((r) => r(url));
  }
}

export function signMedia(path: string): Promise<string | null> {
  const key = path.replace(/^\/+/, "");
  const hit = cache.get(key);
  if (hit && hit.until > Date.now()) return Promise.resolve(hit.url);

  return new Promise((resolve) => {
    const resolvers = waiting.get(key) ?? [];
    resolvers.push(resolve);
    waiting.set(key, resolvers);
    if (!scheduled) {
      scheduled = true;
      queueMicrotask(flush);
    }
  });
}

/** Signed URL for a media-disk path, or null while loading / when not permitted. */
export function useMediaUrl(path: string | null | undefined): string | null {
  const [state, setState] = useState<{ path: string; url: string | null } | null>(null);

  useEffect(() => {
    if (!path) return;
    let cancelled = false;
    signMedia(path).then((url) => {
      if (!cancelled) setState({ path, url });
    });
    return () => {
      cancelled = true;
    };
  }, [path]);

  return path && state?.path === path ? state.url : null;
}

export function SignedImg({ path, ...props }: { path: string } & Omit<React.ImgHTMLAttributes<HTMLImageElement>, "src">) {
  const src = useMediaUrl(path);
  if (!src) return null;
  // eslint-disable-next-line @next/next/no-img-element
  return <img src={src} alt={props.alt ?? ""} {...props} />;
}

export function SignedAudio({ path, ...props }: { path: string } & Omit<React.AudioHTMLAttributes<HTMLAudioElement>, "src">) {
  const src = useMediaUrl(path);
  return <audio src={src ?? undefined} {...props} />;
}
