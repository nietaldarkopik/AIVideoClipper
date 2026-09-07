"use client";

import { useState } from "react";
import Link from "next/link";
import { ArrowRight, CalendarClock, CalendarOff, Plus, Radar } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { ChannelFormModal } from "@/components/research/ChannelFormModal";
import { formatRelativeTime, formatUpcomingTime } from "@/lib/format";
import type { ContentChannel } from "@/lib/types";

export default function ResearchChannelsPage() {
  const { data, isLoading, mutate } = useApi<{ data: ContentChannel[] }>("/content-channels");
  const [modalOpen, setModalOpen] = useState(false);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Channel Riset</h1>
          <p className="mt-1 text-sm text-muted">
            Tiap channel punya niche, sumber riset dan jadwalnya sendiri. Menambah channel baru tidak
            perlu perubahan kode.
          </p>
        </div>
        <Button onClick={() => setModalOpen(true)}>
          <Plus className="size-4" />
          Tambah Channel
        </Button>
      </div>

      {isLoading || !data ? (
        <div className="grid gap-3 md:grid-cols-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-32" />
          ))}
        </div>
      ) : data.data.length === 0 ? (
        <EmptyState
          icon={<Radar className="size-8" />}
          title="Belum ada channel riset"
          description="Buat channel pertama — pilih niche, sumber riset dan jadwalnya, lalu sistem akan meriset otomatis setiap hari."
          action={
            <Button onClick={() => setModalOpen(true)}>
              <Plus className="size-4" />
              Tambah Channel
            </Button>
          }
        />
      ) : (
        <div className="grid gap-3 md:grid-cols-2">
          {data.data.map((channel) => (
            <Link key={channel.id} href={`/research/channels/${channel.id}`}>
              <Card className="h-full p-4 transition-colors hover:border-accent/40">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <h2 className="truncate text-sm font-semibold">{channel.name}</h2>
                      {!channel.is_active && <Badge tone="muted">nonaktif</Badge>}
                    </div>
                    <p className="mt-0.5 truncate text-xs text-muted">
                      {channel.platform?.name ?? "—"}
                      {channel.handle ? ` · ${channel.handle}` : ""}
                    </p>
                  </div>
                  <ArrowRight className="size-4 shrink-0 text-muted" />
                </div>

                <p className="mt-3 line-clamp-2 text-sm text-muted">
                  {channel.niche ?? "Niche belum diatur"}
                </p>

                {channel.sub_niches.length > 0 && (
                  <div className="mt-2 flex flex-wrap gap-1">
                    {channel.sub_niches.slice(0, 4).map((niche) => (
                      <Badge key={niche} tone="muted" className="text-[10px]">
                        {niche}
                      </Badge>
                    ))}
                    {channel.sub_niches.length > 4 && (
                      <Badge tone="muted" className="text-[10px]">
                        +{channel.sub_niches.length - 4}
                      </Badge>
                    )}
                  </div>
                )}

                <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
                  <span className="flex items-center gap-1.5">
                    {channel.scheduler_enabled ? (
                      <>
                        <CalendarClock className="size-3.5 text-accent-2" />
                        {channel.schedule_times.join(" · ")}
                      </>
                    ) : (
                      <>
                        <CalendarOff className="size-3.5" />
                        Scheduler nonaktif
                      </>
                    )}
                  </span>
                  <span>{channel.ideas_count ?? 0} ide</span>
                  <span>
                    {channel.last_research_at
                      ? `terakhir ${formatRelativeTime(channel.last_research_at)}`
                      : "belum pernah riset"}
                  </span>
                  {channel.scheduler_enabled && channel.next_research_at && (
                    <span>berikutnya {formatUpcomingTime(channel.next_research_at)}</span>
                  )}
                </div>
              </Card>
            </Link>
          ))}
        </div>
      )}

      <ChannelFormModal open={modalOpen} onClose={() => setModalOpen(false)} onSaved={() => mutate()} />
    </div>
  );
}
