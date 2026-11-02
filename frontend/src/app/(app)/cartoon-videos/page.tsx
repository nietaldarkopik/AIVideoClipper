"use client";

import { useState } from "react";
import Link from "next/link";
import { mutate } from "swr";
import { Clapperboard, ArrowRight, Trash2 } from "lucide-react";
import { useCartoonVideos, CARTOON_ACTIVE_STATUSES } from "@/lib/hooks";
import { cartoonApi, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusBadge } from "@/components/ui/Badge";
import { ProgressBar } from "@/components/ui/ProgressBar";
import { formatRelativeTime, formatDuration } from "@/lib/format";

export default function CartoonVideosPage() {
  const { data, isLoading } = useCartoonVideos();
  const [deletingId, setDeletingId] = useState<number | null>(null);

  async function handleDelete(id: number) {
    if (!confirm("Hapus video kartun ini?")) return;
    setDeletingId(id);
    try {
      await cartoonApi.remove(id);
      await mutate((k) => typeof k === "string" && k.startsWith("/cartoon-videos"));
      toast("Video kartun dihapus.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Gagal menghapus.", "danger");
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Video Kartun</h1>
        <p className="mt-1 text-sm text-muted">
          Ubah hasil riset menjadi video pendek bergaya kartun. Mulai dari tombol &quot;Buat Video
          Kartun&quot; di halaman Riset Konten atau Content Research.
        </p>
      </div>

      {isLoading || !data ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
      ) : data.data.length === 0 ? (
        <EmptyState
          icon={<Clapperboard className="size-6" />}
          title="Belum ada video kartun"
          description="Buka sebuah riset konten atau ide, lalu klik Buat Video Kartun."
          action={
            <Link href="/content-briefs">
              <Button size="sm">Ke Riset Konten</Button>
            </Link>
          }
        />
      ) : (
        <div className="space-y-3">
          {data.data.map((video) => {
            const active = CARTOON_ACTIVE_STATUSES.includes(video.status);
            return (
              <Card key={video.id} className="group flex items-center gap-4 p-4 transition-colors hover:border-accent/40">
                <Link href={`/cartoon-videos/${video.id}`} className="flex min-w-0 flex-1 items-center gap-4">
                  <div className="aspect-[9/16] h-24 shrink-0 overflow-hidden rounded-lg bg-white/5">
                    {video.thumbnail_url ? (
                      // eslint-disable-next-line @next/next/no-img-element
                      <img src={video.thumbnail_url} alt="" className="size-full object-cover" />
                    ) : (
                      <div className="flex size-full items-center justify-center text-muted">
                        <Clapperboard className="size-5" />
                      </div>
                    )}
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2.5">
                      <h3 className="truncate text-sm font-semibold">{video.title}</h3>
                      <StatusBadge status={video.status} />
                    </div>
                    <p className="mt-1 text-xs text-muted">
                      Dibuat {formatRelativeTime(video.created_at)}
                      {video.duration_seconds ? ` · ${formatDuration(video.duration_seconds)}` : ""}
                      {video.message && ` · ${video.message}`}
                    </p>
                    {active && <ProgressBar className="mt-3 max-w-sm" value={video.progress} />}
                  </div>
                  <ArrowRight className="size-4 shrink-0 text-muted transition-colors group-hover:text-accent-2" />
                </Link>
                <Button
                  variant="danger"
                  size="sm"
                  onClick={() => handleDelete(video.id)}
                  loading={deletingId === video.id}
                  disabled={active}
                  aria-label="Hapus"
                >
                  <Trash2 className="size-3.5" />
                </Button>
              </Card>
            );
          })}
        </div>
      )}
    </div>
  );
}
