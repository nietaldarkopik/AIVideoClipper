"use client";

import { useRef, useState } from "react";
import { mutate } from "swr";
import { Check, Music, Trash2, Upload } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError, mediaUrl } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Input, Label } from "@/components/ui/Input";
import type { MediaUpload } from "@/lib/types";

const ACCEPT: Record<"image" | "audio", string> = {
  image: ".png,.jpg,.jpeg,.webp,.gif",
  audio: ".mp3,.wav,.m4a,.aac,.ogg",
};

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/**
 * Upload-or-pick for the one asset an image/logo/audio layer points at.
 *
 * Before this existed, both layer types offered only a bare "path on the media
 * disk" text field, so adding background music or a logo meant knowing a path
 * that already existed on the server — the layer would otherwise appear on the
 * timeline and render nothing at all (LayerCompositionService skips an
 * image/audio layer whose path is empty).
 *
 * The manual path field is kept, demoted, because template assets live outside
 * the per-user library (`branding/logo.png` and friends) and must stay
 * addressable.
 */
export function MediaPicker({
  kind,
  value,
  onChange,
  label,
}: {
  kind: "image" | "audio";
  value: string;
  // `asset` is null when the path was typed by hand rather than picked, since
  // there's no library entry (and so no waveform) to go with it.
  onChange: (path: string, asset: MediaUpload | null) => void;
  label: string;
}) {
  const listKey = `/media-uploads?kind=${kind}`;
  const { data, isLoading } = useApi<{ data: MediaUpload[] }>(listKey);
  const assets = data?.data ?? [];
  const [uploading, setUploading] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  async function handleUpload(file: File) {
    setUploading(true);
    try {
      const body = new FormData();
      body.append("kind", kind);
      body.append("file", file);
      const res = await api.post<{ data: MediaUpload }>("/media-uploads", body);
      await mutate(listKey);
      // Selecting it immediately is the whole point of uploading from here.
      onChange(res.data.path, res.data);
      toast(`${res.data.name} uploaded.`, "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Upload failed.", "danger");
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = "";
    }
  }

  async function handleDelete(asset: MediaUpload) {
    if (!confirm(`Delete ${asset.name} from your library? Layers still using it will render nothing.`)) return;
    try {
      await api.del(`/media-uploads?path=${encodeURIComponent(asset.path)}`);
      await mutate(listKey);
      if (value === asset.path) onChange("", null);
      toast("Asset deleted.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to delete.", "danger");
    }
  }

  const selected = assets.find((a) => a.path === value) ?? null;

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <Label>{label}</Label>
        <Button variant="outline" size="sm" onClick={() => fileRef.current?.click()} loading={uploading}>
          <Upload className="size-3.5" />
          Upload
        </Button>
        <input
          ref={fileRef}
          type="file"
          accept={ACCEPT[kind]}
          className="hidden"
          onChange={(e) => {
            const file = e.target.files?.[0];
            if (file) void handleUpload(file);
          }}
        />
      </div>

      {/* A path that isn't in the library (a template asset, or one typed by
          hand) still needs to read as "this is what's selected". */}
      {value && !selected && (
        <div className="rounded-xl bg-surface-elevated px-3 py-2 text-[11px] text-muted">
          Using <span className="text-foreground">{value}</span> — not in your library.
        </div>
      )}

      {isLoading ? (
        <p className="text-[11px] text-muted">Loading your {kind}s…</p>
      ) : assets.length === 0 ? (
        <p className="text-[11px] text-muted">
          Nothing uploaded yet. Upload {kind === "image" ? "a PNG/JPG/WebP/GIF" : "an MP3/WAV/M4A/AAC/OGG"} to use it
          here.
        </p>
      ) : kind === "image" ? (
        <div className="grid grid-cols-3 gap-2">
          {/* Delete sits alongside the select button rather than inside it —
              a button nested in a button is invalid HTML, and screen readers
              disagree about which one they're activating. */}
          {assets.map((asset) => (
            <div
              key={asset.path}
              className={
                "group relative aspect-square overflow-hidden rounded-xl border bg-black/30 " +
                (asset.path === value ? "border-accent" : "border-border-subtle hover:border-white/40")
              }
            >
              <button
                type="button"
                onClick={() => onChange(asset.path, asset)}
                title={`${asset.name} · ${formatSize(asset.size_bytes)}`}
                className="size-full cursor-pointer"
              >
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={asset.url} alt={asset.name} className="size-full object-contain" />
              </button>
              {asset.path === value && (
                <span className="pointer-events-none absolute right-1 top-1 rounded-full bg-accent p-0.5 text-white">
                  <Check className="size-2.5" />
                </span>
              )}
              <button
                type="button"
                onClick={() => void handleDelete(asset)}
                title="Delete from library"
                className="absolute bottom-1 right-1 cursor-pointer rounded-full bg-black/70 p-1 text-white/70 opacity-0 hover:text-danger group-hover:opacity-100"
              >
                <Trash2 className="size-2.5" />
              </button>
            </div>
          ))}
        </div>
      ) : (
        <div className="space-y-1.5">
          {assets.map((asset) => (
            <div
              key={asset.path}
              className={
                "flex items-center gap-2 rounded-xl border px-2.5 py-2 " +
                (asset.path === value ? "border-accent bg-accent/10" : "border-border-subtle bg-surface-elevated")
              }
            >
              <button
                type="button"
                onClick={() => onChange(asset.path, asset)}
                className="flex min-w-0 flex-1 cursor-pointer items-center gap-2 text-left"
              >
                <Music className="size-3.5 shrink-0 text-muted" />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-xs">{asset.name}</span>
                  <span className="block text-[10px] text-muted">{formatSize(asset.size_bytes)}</span>
                </span>
              </button>
              {asset.waveform_url && (
                <div
                  className="hidden h-6 w-20 shrink-0 opacity-70 sm:block"
                  style={{
                    backgroundImage: `url(${asset.waveform_url})`,
                    backgroundSize: "100% 100%",
                    backgroundRepeat: "no-repeat",
                  }}
                />
              )}
              <button
                type="button"
                onClick={() => void handleDelete(asset)}
                className="shrink-0 cursor-pointer text-muted hover:text-danger"
                title="Delete from library"
              >
                <Trash2 className="size-3.5" />
              </button>
            </div>
          ))}
        </div>
      )}

      {selected && kind === "audio" && (
        <audio src={mediaUrl(selected.path) ?? undefined} controls className="w-full" />
      )}

      <details className="text-[11px] text-muted">
        <summary className="cursor-pointer">Or enter a path on the media disk</summary>
        <Input
          className="mt-1.5"
          value={value}
          placeholder={kind === "image" ? "branding/logo.png" : "assets/music/track.mp3"}
          onChange={(e) => onChange(e.target.value, null)}
        />
      </details>
    </div>
  );
}
