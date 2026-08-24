"use client";

import { useState } from "react";
import { Plus, Rss } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { Button } from "@/components/ui/Button";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { ChannelWatchCard } from "@/components/channels/ChannelWatchCard";
import { NewChannelWatchModal } from "@/components/channels/NewChannelWatchModal";
import type { ChannelWatch } from "@/lib/types";

export default function ChannelsPage() {
  const [modalOpen, setModalOpen] = useState(false);
  const { data, isLoading } = useApi<{ data: ChannelWatch[] }>("/channel-watches", { refreshInterval: 15000 });

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Channels</h1>
          <p className="mt-1 text-sm text-muted">
            Watch YouTube channels for new uploads — every new video gets clipped and
            published automatically, unattended.
          </p>
        </div>
        <Button onClick={() => setModalOpen(true)}>
          <Plus className="size-4" />
          Watch Channel
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
          icon={<Rss className="size-6" />}
          title="No channels watched yet"
          description="Add a channel and its new uploads will be clipped and published automatically."
          action={
            <Button size="sm" onClick={() => setModalOpen(true)}>
              <Plus className="size-4" />
              Watch Channel
            </Button>
          }
        />
      ) : (
        <div className="space-y-3">
          {data.data.map((watch) => (
            <ChannelWatchCard key={watch.id} watch={watch} />
          ))}
        </div>
      )}

      <NewChannelWatchModal open={modalOpen} onClose={() => setModalOpen(false)} />
    </div>
  );
}
