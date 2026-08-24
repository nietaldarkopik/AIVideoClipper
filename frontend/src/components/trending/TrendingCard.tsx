"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { mutate } from "swr";
import { Eye, Heart, MessageCircle, Scissors } from "lucide-react";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { createProjectFromUrl } from "@/lib/createProjectFromUrl";
import { ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import type { TrendingItem } from "@/lib/types";

const compactNumber = new Intl.NumberFormat("en", { notation: "compact" });

const PLATFORM_LABEL: Record<TrendingItem["platform"], string> = {
  youtube: "YouTube",
  facebook: "Facebook",
  tiktok: "TikTok",
  instagram: "Instagram",
  twitter: "X (Twitter)",
};

export function TrendingCard({ item }: { item: TrendingItem }) {
  const router = useRouter();
  const [creating, setCreating] = useState(false);

  async function handleCreateProject() {
    setCreating(true);
    try {
      const project = await createProjectFromUrl(item.source_url, item.title);
      await mutate("/projects");
      await mutate("/dashboard");
      toast("Import started.", "success");
      router.push(`/projects/${project.id}`);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Something went wrong.", "danger");
      setCreating(false);
    }
  }

  return (
    <Card className="overflow-hidden">
      <div className="relative flex aspect-video w-full items-center justify-center overflow-hidden bg-gradient-to-br from-surface-elevated to-surface">
        {item.thumbnail_url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={item.thumbnail_url} alt={item.title} className="h-full w-full object-cover" />
        ) : (
          <Scissors className="size-8 text-muted" />
        )}
        <div className="absolute left-2 top-2 flex gap-1.5">
          <Badge tone="accent">{PLATFORM_LABEL[item.platform]}</Badge>
          {item.is_mock && <Badge tone="muted">Sample</Badge>}
        </div>
      </div>
      <div className="p-4">
        <h3 className="line-clamp-2 text-sm font-semibold">{item.title}</h3>
        {item.author_name && <p className="mt-1 truncate text-xs text-muted">{item.author_name}</p>}

        <div className="mt-3 flex items-center gap-3 text-xs text-muted">
          <span className="flex items-center gap-1">
            <Eye className="size-3.5" />
            {compactNumber.format(item.view_count)}
          </span>
          <span className="flex items-center gap-1">
            <Heart className="size-3.5" />
            {compactNumber.format(item.like_count)}
          </span>
          <span className="flex items-center gap-1">
            <MessageCircle className="size-3.5" />
            {compactNumber.format(item.comment_count)}
          </span>
        </div>

        <Button onClick={handleCreateProject} loading={creating} className="mt-4 w-full" size="sm">
          <Scissors className="size-3.5" />
          Create Clip Project
        </Button>
      </div>
    </Card>
  );
}
