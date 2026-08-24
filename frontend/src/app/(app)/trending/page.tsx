"use client";

import { useState } from "react";
import { TrendingUp } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { Select } from "@/components/ui/Input";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { TrendingCard } from "@/components/trending/TrendingCard";
import type { TrendingItem, TrendingPlatformInfo } from "@/lib/types";

export default function TrendingPage() {
  const [platform, setPlatform] = useState("");

  const { data: platformsRes } = useApi<{ platforms: TrendingPlatformInfo[] }>("/trending/platforms");
  const { data, isLoading } = useApi<{ data: TrendingItem[] }>(
    `/trending${platform ? `?platform=${platform}` : ""}`
  );

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Trending</h1>
          <p className="mt-1 text-sm text-muted">
            Content ideas from across platforms — pick one to clip.
          </p>
        </div>
        <Select value={platform} onChange={(e) => setPlatform(e.target.value)} className="w-auto">
          <option value="">All platforms</option>
          {platformsRes?.platforms.map((p) => (
            <option key={p.key} value={p.key}>
              {p.label}
              {p.is_mocked ? " (sample)" : ""}
            </option>
          ))}
        </Select>
      </div>

      {isLoading || !data ? (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} className="h-64" />
          ))}
        </div>
      ) : data.data.length === 0 ? (
        <EmptyState icon={<TrendingUp className="size-6" />} title="No trending items found" />
      ) : (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          {data.data.map((item) => (
            <TrendingCard key={`${item.platform}-${item.external_id}`} item={item} />
          ))}
        </div>
      )}
    </div>
  );
}
