"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, Check, Trash2, X } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { useToastStore } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/Card";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { Textarea } from "@/components/ui/Input";
import { PriorityPill, ScoreGrid } from "@/components/research/ScoreBar";
import { SourceEvidence } from "@/components/research/SourceEvidence";
import { formatRelativeTime } from "@/lib/format";
import type { ContentIdea } from "@/lib/types";

export default function ContentIdeaDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params.id;
  const router = useRouter();
  const push = useToastStore((s) => s.push);

  const { data, isLoading, mutate } = useApi<{ data: ContentIdea }>(`/content-ideas/${id}`);
  const [busy, setBusy] = useState(false);
  const [notes, setNotes] = useState<string | null>(null);

  async function act(action: "select" | "reject") {
    setBusy(true);
    try {
      await api.post(`/content-ideas/${id}/${action}`);
      push(action === "select" ? "Ide dipilih." : "Ide ditolak.", "success");
      mutate();
    } catch (error) {
      push(error instanceof ApiError ? error.message : "Gagal memperbarui ide.", "danger");
    } finally {
      setBusy(false);
    }
  }

  async function saveNotes() {
    setBusy(true);
    try {
      await api.patch(`/content-ideas/${id}`, { notes });
      push("Catatan disimpan.", "success");
      mutate();
    } catch (error) {
      push(error instanceof ApiError ? error.message : "Gagal menyimpan catatan.", "danger");
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (!confirm("Hapus ide ini?")) return;
    try {
      await api.del(`/content-ideas/${id}`);
      push("Ide dihapus.", "success");
      router.push("/research/ideas");
    } catch (error) {
      push(error instanceof ApiError ? error.message : "Gagal menghapus ide.", "danger");
    }
  }

  if (isLoading || !data) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-20" />
        <Skeleton className="h-64" />
      </div>
    );
  }

  const idea = data.data;

  return (
    <div className="space-y-6">
      <div>
        <Link
          href="/research/ideas"
          className="inline-flex items-center gap-1.5 text-xs text-muted hover:text-foreground"
        >
          <ArrowLeft className="size-3.5" />
          Semua ide
        </Link>

        <div className="mt-2 flex flex-wrap items-start justify-between gap-3">
          <div className="flex items-start gap-3">
            <PriorityPill value={idea.scores.priority} />
            <div>
              <h1 className="text-lg font-semibold">{idea.title}</h1>
              <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted">
                <StatusBadge status={idea.status} />
                {idea.channel && (
                  <Link href={`/research/channels/${idea.channel.id}`} className="hover:text-foreground">
                    {idea.channel.name}
                  </Link>
                )}
                <span>· {idea.research_date ?? formatRelativeTime(idea.created_at)}</span>
              </p>
            </div>
          </div>

          <div className="flex gap-2">
            {idea.status !== "rejected" && (
              <Button variant="secondary" onClick={() => act("reject")} loading={busy}>
                <X className="size-4" />
                Tolak
              </Button>
            )}
            {idea.status === "idea" && (
              <Button onClick={() => act("select")} loading={busy}>
                <Check className="size-4" />
                Pilih Ide Ini
              </Button>
            )}
          </div>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          <Card>
            <CardHeader>
              <CardTitle>Ringkasan Ide</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <Fact label="Topik" value={idea.topic} />
              {idea.short_description && <Fact label="Deskripsi Singkat" value={idea.short_description} />}
              {idea.content_angle && <Fact label="Sudut Pandang" value={idea.content_angle} />}
              {idea.why_this_topic && <Fact label="Kenapa Topik Ini" value={idea.why_this_topic} />}
              {idea.target_audience && <Fact label="Target Audiens" value={idea.target_audience} />}

              {idea.alternative_titles.length > 0 && (
                <div>
                  <p className="text-xs text-muted">Alternatif Judul</p>
                  <ul className="mt-1 space-y-1">
                    {idea.alternative_titles.map((title) => (
                      <li key={title} className="text-sm">
                        • {title}
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {idea.keywords.length > 0 && (
                <div>
                  <p className="mb-1.5 text-xs text-muted">Kata Kunci</p>
                  <div className="flex flex-wrap gap-1">
                    {idea.keywords.map((keyword) => (
                      <Badge key={keyword} tone="muted" className="text-[10px]">
                        {keyword}
                      </Badge>
                    ))}
                  </div>
                </div>
              )}

              <div className="grid gap-4 sm:grid-cols-2">
                <Fact label="Tipe Konten" value={idea.suggested_content_type ?? "—"} />
                <Fact label="Format" value={idea.suggested_format ?? "—"} />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex items-center justify-between">
              <CardTitle>Bukti Riset</CardTitle>
              {idea.research_run_id && (
                <Link
                  href={`/research/runs/${idea.research_run_id}`}
                  className="text-xs text-accent-2 hover:underline"
                >
                  Lihat riset lengkap
                </Link>
              )}
            </CardHeader>
            <CardContent className="space-y-3">
              {idea.source_summary && <p className="text-xs text-muted">{idea.source_summary}</p>}
              <SourceEvidence sources={idea.sources ?? []} />
            </CardContent>
          </Card>
        </div>

        <div className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Skor</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <ScoreGrid scores={idea.scores} />
              <p className="text-[11px] leading-relaxed text-muted">
                Trend, relevansi, kesegaran, engagement dan lintas-sumber dihitung dari data riset yang
                benar-benar diambil — bukan ditaksir oleh AI.
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Catatan</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              <Textarea
                rows={5}
                value={notes ?? idea.notes ?? ""}
                onChange={(e) => setNotes(e.target.value)}
                placeholder="Catatan produksi, arahan, referensi..."
              />
              <Button size="sm" variant="secondary" onClick={saveNotes} loading={busy} className="w-full">
                Simpan Catatan
              </Button>
            </CardContent>
          </Card>

          <Button variant="danger" className="w-full" onClick={remove}>
            <Trash2 className="size-4" />
            Hapus Ide
          </Button>
        </div>
      </div>
    </div>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs text-muted">{label}</p>
      <p className="mt-0.5 whitespace-pre-wrap text-sm">{value}</p>
    </div>
  );
}
