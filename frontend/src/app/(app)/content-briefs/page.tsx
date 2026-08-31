"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { Plus, Lightbulb, ArrowRight } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusBadge } from "@/components/ui/Badge";
import { ProgressBar } from "@/components/ui/ProgressBar";
import { formatRelativeTime } from "@/lib/format";
import { NewContentBriefModal, ContentBriefPrefill } from "@/components/content-briefs/NewContentBriefModal";
import type { Paginated, ContentBrief } from "@/lib/types";

const ACTIVE_STATUSES = ["pending", "searching", "fetching_sources", "generating_script", "finding_videos"];

function ContentBriefsPageInner() {
  const searchParams = useSearchParams();
  const prefillTopic = searchParams.get("topic");
  const [modalOpen, setModalOpen] = useState(!!prefillTopic);

  const prefill: ContentBriefPrefill | null = prefillTopic
    ? {
        topic: prefillTopic,
        source_platform: searchParams.get("source_platform") ?? undefined,
        source_trending_title: searchParams.get("source_trending_title") ?? undefined,
        source_trending_url: searchParams.get("source_trending_url") ?? undefined,
      }
    : null;

  const { data, isLoading } = useApi<Paginated<ContentBrief>>("/content-briefs?per_page=50", {
    refreshInterval: (latest?: Paginated<ContentBrief>) =>
      latest?.data.some((b) => ACTIVE_STATUSES.includes(b.status)) ? 3000 : 0,
  });

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Riset Konten</h1>
          <p className="mt-1 text-sm text-muted">
            Dari topik trending atau ide sendiri — AI mengumpulkan informasi, menyusun naskah
            video Bahasa Indonesia, dan mencari video-video relevan.
          </p>
        </div>
        <Button onClick={() => setModalOpen(true)}>
          <Plus className="size-4" />
          Riset Baru
        </Button>
      </div>

      {isLoading || !data ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
      ) : data.data.length === 0 ? (
        <EmptyState
          icon={<Lightbulb className="size-6" />}
          title="Belum ada riset konten"
          description="Mulai riset dari sebuah topik untuk mendapatkan naskah video dan video relevan."
          action={
            <Button size="sm" onClick={() => setModalOpen(true)}>
              <Plus className="size-4" />
              Riset Baru
            </Button>
          }
        />
      ) : (
        <div className="space-y-3">
          {data.data.map((brief) => (
            <Link key={brief.id} href={`/content-briefs/${brief.id}`}>
              <Card className="group flex items-center justify-between gap-4 p-5 transition-colors hover:border-accent/40">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2.5">
                    <h3 className="truncate text-sm font-semibold">{brief.topic}</h3>
                    <StatusBadge status={brief.status} />
                  </div>
                  <p className="mt-1 text-xs text-muted">
                    Dimulai {formatRelativeTime(brief.created_at)}
                    {brief.message && ` · ${brief.message}`}
                  </p>
                  {ACTIVE_STATUSES.includes(brief.status) && (
                    <ProgressBar className="mt-3 max-w-sm" value={brief.progress} />
                  )}
                </div>
                <ArrowRight className="size-4 shrink-0 text-muted transition-colors group-hover:text-accent-2" />
              </Card>
            </Link>
          ))}
        </div>
      )}

      <NewContentBriefModal open={modalOpen} onClose={() => setModalOpen(false)} prefill={prefill} />
    </div>
  );
}

export default function ContentBriefsPage() {
  return (
    <Suspense fallback={<Skeleton className="h-96" />}>
      <ContentBriefsPageInner />
    </Suspense>
  );
}
