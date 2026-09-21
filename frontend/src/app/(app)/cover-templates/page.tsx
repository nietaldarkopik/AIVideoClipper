"use client";

import { useState } from "react";
import { Plus, Image as ImageIcon } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { useAuthStore } from "@/store/auth";
import { Button } from "@/components/ui/Button";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { CoverTemplateCard } from "@/components/cover-templates/CoverTemplateCard";
import { NewCoverTemplateModal } from "@/components/cover-templates/NewCoverTemplateModal";
import type { CoverTemplate } from "@/lib/types";

export default function CoverTemplatesPage() {
  const user = useAuthStore((s) => s.user);
  const [modalOpen, setModalOpen] = useState(false);

  const { data, isLoading } = useApi<{ data: CoverTemplate[] }>("/cover-templates");

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Cover Templates</h1>
          <p className="mt-1 text-sm text-muted">
            Clickbait-style thumbnail/cover designs — pick one per clip or as a channel default.
          </p>
        </div>
        {user?.role === "admin" && (
          <Button onClick={() => setModalOpen(true)}>
            <Plus className="size-4" />
            New Cover Template
          </Button>
        )}
      </div>

      {isLoading || !data ? (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} className="h-64" />
          ))}
        </div>
      ) : data.data.length === 0 ? (
        <EmptyState icon={<ImageIcon className="size-6" />} title="No cover templates yet" />
      ) : (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          {data.data.map((coverTemplate) => (
            <CoverTemplateCard key={coverTemplate.id} coverTemplate={coverTemplate} />
          ))}
        </div>
      )}

      <NewCoverTemplateModal open={modalOpen} onClose={() => setModalOpen(false)} />
    </div>
  );
}
