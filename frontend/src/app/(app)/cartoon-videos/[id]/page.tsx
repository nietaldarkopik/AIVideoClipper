"use client";

import { use, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { mutate } from "swr";
import { ArrowLeft, Clapperboard, ExternalLink, ImageOff, Play, RotateCcw, Square, Trash2 } from "lucide-react";
import { useCartoonVideo, CARTOON_ACTIVE_STATUSES } from "@/lib/hooks";
import { cartoonApi, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { formatDuration, formatRelativeTime } from "@/lib/format";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Label, Textarea } from "@/components/ui/Input";
import { Skeleton } from "@/components/ui/Skeleton";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { ProgressBar } from "@/components/ui/ProgressBar";
import type { CartoonScene, CartoonVideo } from "@/lib/types";

const SCENE_TONE = { pending: "muted", processing: "accent", done: "success", failed: "danger" } as const;
const SCENE_LABEL = { pending: "Menunggu", processing: "Diproses", done: "Selesai", failed: "Gagal" } as const;

type SceneField = "narration" | "visual_prompt" | "motion_prompt";

function SceneCard({
  scene,
  locked,
  onSave,
}: {
  scene: CartoonScene;
  locked: boolean;
  onSave: (sceneId: number, field: SceneField, value: string) => Promise<void>;
}) {
  const [values, setValues] = useState<Record<SceneField, string>>({
    narration: scene.narration,
    visual_prompt: scene.visual_prompt,
    motion_prompt: scene.motion_prompt ?? "",
  });

  // Re-sync when the server value changes (e.g. after a regenerated storyboard) —
  // done during render rather than in an effect, per React's derived-state pattern.
  const serverKey = `${scene.narration}0000${scene.visual_prompt}0000${scene.motion_prompt ?? ""}`;
  const [syncedKey, setSyncedKey] = useState(serverKey);
  if (syncedKey !== serverKey) {
    setSyncedKey(serverKey);
    setValues({
      narration: scene.narration,
      visual_prompt: scene.visual_prompt,
      motion_prompt: scene.motion_prompt ?? "",
    });
  }

  function field(name: SceneField, label: string, rows: number) {
    const original = name === "motion_prompt" ? scene.motion_prompt ?? "" : scene[name];
    return (
      <div>
        <Label>{label}</Label>
        <Textarea
          rows={rows}
          value={values[name]}
          disabled={locked}
          onChange={(e) => setValues((v) => ({ ...v, [name]: e.target.value }))}
          onBlur={() => {
            if (values[name] !== original) onSave(scene.id, name, values[name]);
          }}
        />
      </div>
    );
  }

  return (
    <Card className="p-4">
      <div className="mb-3 flex items-center justify-between">
        <h3 className="text-sm font-semibold">Scene {scene.position}</h3>
        <div className="flex items-center gap-2">
          {scene.audio_duration ? <span className="text-xs text-muted">{scene.audio_duration.toFixed(1)} dtk</span> : null}
          {scene.clip_provider === 'camera_motion' && (
            <span className="text-xs text-muted" title="Provider video AI tidak tersedia, memakai gerak kamera gratis">gerak kamera</span>
          )}
          <Badge tone={SCENE_TONE[scene.status]}>{SCENE_LABEL[scene.status]}</Badge>
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-[auto_1fr]">
        <div className="flex gap-2">
          <div className="aspect-[9/16] h-48 overflow-hidden rounded-lg bg-white/5">
            {scene.image_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={scene.image_url} alt={`Scene ${scene.position}`} className="size-full object-cover" />
            ) : (
              <div className="flex size-full flex-col items-center justify-center gap-1 text-muted">
                <ImageOff className="size-5" />
                <span className="text-[10px]">Belum ada gambar</span>
              </div>
            )}
          </div>
          {scene.clip_url && (
            <video
              src={scene.clip_url}
              controls
              muted
              loop
              playsInline
              className="aspect-[9/16] h-48 rounded-lg bg-black object-cover"
            />
          )}
        </div>

        <div className="space-y-3">
          {field("narration", "Narasi", 3)}
          {field("visual_prompt", "Prompt visual (Inggris)", 3)}
          {field("motion_prompt", "Gerakan", 2)}
        </div>
      </div>

      {scene.error && (
        <p className="mt-3 break-words rounded-lg bg-danger/10 p-2.5 text-xs text-danger">{scene.error}</p>
      )}
    </Card>
  );
}

export default function CartoonVideoDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const videoId = Number(id);
  const router = useRouter();
  const key = `/cartoon-videos/${videoId}`;
  const { data: res, isLoading } = useCartoonVideo(videoId);
  const video = res?.data;

  const [busy, setBusy] = useState<string | null>(null);
  const [character, setCharacter] = useState("");

  const serverCharacter = video?.character_description ?? "";
  const [syncedCharacter, setSyncedCharacter] = useState(serverCharacter);
  if (syncedCharacter !== serverCharacter) {
    setSyncedCharacter(serverCharacter);
    setCharacter(serverCharacter);
  }

  function apply(next: { data: CartoonVideo }) {
    return mutate(key, next, { revalidate: false });
  }

  async function run(name: string, action: () => Promise<{ data: CartoonVideo }>, success?: string) {
    setBusy(name);
    try {
      await apply(await action());
      if (success) toast(success, "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Terjadi kesalahan.", "danger");
    } finally {
      setBusy(null);
    }
  }

  async function saveScene(sceneId: number, field: SceneField, value: string) {
    try {
      await apply(await cartoonApi.updateScene(videoId, sceneId, { [field]: value }));
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Gagal menyimpan scene.", "danger");
      await mutate(key);
    }
  }

  async function saveCharacter() {
    if (!video || character === (video.character_description ?? "")) return;
    if (!confirm("Mengubah karakter akan membuat ulang semua gambar scene saat render berikutnya. Lanjutkan?")) {
      setCharacter(video.character_description ?? "");
      return;
    }
    await run("character", () => cartoonApi.update(videoId, { character_description: character || null }));
  }

  async function handleDelete() {
    if (!confirm("Hapus video kartun ini?")) return;
    setBusy("delete");
    try {
      await cartoonApi.remove(videoId);
      await mutate((k) => typeof k === "string" && k.startsWith("/cartoon-videos"));
      toast("Video kartun dihapus.", "success");
      router.push("/cartoon-videos");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Gagal menghapus.", "danger");
      setBusy(null);
    }
  }

  if (isLoading || !video) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-96" />
      </div>
    );
  }

  const active = CARTOON_ACTIVE_STATUSES.includes(video.status);
  const scenes = video.scenes ?? [];
  const canRender =
    ["storyboard_ready", "needs_attention", "completed", "failed", "cancelled"].includes(video.status) &&
    scenes.length > 0;
  const showScenes = scenes.length > 0 && !["pending", "storyboarding"].includes(video.status);
  const renderLabel =
    video.status === "needs_attention"
      ? "Render ulang scene yang gagal"
      : video.status === "completed"
        ? "Render ulang"
        : "Render Video";
  const cost = video.estimated_cost_usd ?? 0;

  return (
    <div className="space-y-6">
      <div>
        <Link href="/cartoon-videos" className="mb-3 flex items-center gap-1.5 text-xs text-muted hover:text-foreground">
          <ArrowLeft className="size-3.5" />
          Kembali ke Video Kartun
        </Link>
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0">
            <div className="flex items-center gap-2.5">
              <h1 className="text-xl font-semibold">{video.title}</h1>
              <StatusBadge status={video.status} />
            </div>
            <p className="mt-1 text-sm text-muted">
              Dibuat {formatRelativeTime(video.created_at)}
              {video.message && ` · ${video.message}`}
            </p>
          </div>
          <Button variant="danger" size="sm" onClick={handleDelete} loading={busy === "delete"} disabled={active}>
            <Trash2 className="size-3.5" />
            Hapus
          </Button>
        </div>
      </div>

      {active && (
        <Card className="p-5">
          <div className="flex items-center justify-between text-xs text-muted">
            <span>{video.message || "Memproses..."}</span>
            <span>{video.progress}%</span>
          </div>
          <ProgressBar className="mt-2" value={video.progress} />
          <div className="mt-3">
            <Button
              variant="secondary"
              size="sm"
              onClick={() => run("cancel", () => cartoonApi.cancel(videoId), "Membatalkan...")}
              loading={busy === "cancel"}
            >
              <Square className="size-3.5" />
              Batalkan
            </Button>
          </div>
        </Card>
      )}

      {(video.status === "failed" || video.status === "needs_attention") && (
        <Card className="border-danger/30 bg-danger/5 p-5">
          <p className="text-sm text-danger">
            {video.failure_reason || video.message || "Ada langkah yang gagal. Periksa error pada scene di bawah."}
          </p>
        </Card>
      )}

      {video.status === "completed" && video.url && (
        <Card className="p-5">
          <div className="flex flex-wrap items-start gap-6">
            <video src={video.url} controls playsInline className="aspect-[9/16] h-[28rem] rounded-xl bg-black" />
            <div className="space-y-3 text-sm">
              <p className="text-muted">Durasi {formatDuration(video.duration_seconds)} · 1080×1920</p>
              {video.clip_id && (
                <Link href="/clips" className="flex items-center gap-1.5 text-accent-2 hover:underline">
                  <ExternalLink className="size-3.5" />
                  Lihat di AI Clips untuk dijadwalkan/publish
                </Link>
              )}
            </div>
          </div>
        </Card>
      )}

      {showScenes && (
        <>
          <Card className="p-5">
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div>
                <h2 className="text-sm font-semibold">
                  {scenes.length} scene · gerakan: {video.motion_provider}
                </h2>
                {cost > 0 && (
                  <p className="mt-1 text-xs text-muted">Perkiraan biaya render: ~US${cost.toFixed(2)}</p>
                )}
              </div>
              <div className="flex gap-2">
                <Button
                  variant="secondary"
                  onClick={() =>
                    run("storyboard", () => cartoonApi.regenerateStoryboard(videoId), "Membuat ulang storyboard...")
                  }
                  loading={busy === "storyboard"}
                  disabled={active}
                >
                  <RotateCcw className="size-4" />
                  Buat ulang storyboard
                </Button>
                {canRender && (
                  <Button
                    onClick={() => run("render", () => cartoonApi.render(videoId), "Render dimulai.")}
                    loading={busy === "render"}
                    disabled={active}
                  >
                    <Play className="size-4" />
                    {renderLabel}
                  </Button>
                )}
              </div>
            </div>

            <div className="mt-4">
              <Label>Karakter maskot (dipakai di semua scene)</Label>
              <Textarea
                rows={2}
                value={character}
                disabled={active}
                onChange={(e) => setCharacter(e.target.value)}
                onBlur={saveCharacter}
              />
            </div>
          </Card>

          <div className="space-y-4">
            {scenes.map((scene) => (
              <SceneCard key={scene.id} scene={scene} locked={active} onSave={saveScene} />
            ))}
          </div>
        </>
      )}

      {!showScenes && !active && video.status !== "failed" && (
        <Card className="flex items-center gap-3 p-5 text-sm text-muted">
          <Clapperboard className="size-4" />
          Belum ada scene.
        </Card>
      )}
    </div>
  );
}
