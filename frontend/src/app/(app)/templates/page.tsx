"use client";

import { useState } from "react";
import { Plus, LayoutTemplate } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { useAuthStore } from "@/store/auth";
import { Button } from "@/components/ui/Button";
import { Select } from "@/components/ui/Input";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { TemplateCard } from "@/components/templates/TemplateCard";
import { NewTemplateModal } from "@/components/templates/NewTemplateModal";
import type { Template, TemplateCategory } from "@/lib/types";

export default function TemplatesPage() {
  const user = useAuthStore((s) => s.user);
  const [categoryId, setCategoryId] = useState("");
  const [modalOpen, setModalOpen] = useState(false);

  const { data: categoriesRes } = useApi<{ data: TemplateCategory[] }>("/template-categories");
  const { data, isLoading } = useApi<{ data: Template[] }>(
    `/templates${categoryId ? `?category_id=${categoryId}` : ""}`
  );

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Templates</h1>
          <p className="mt-1 text-sm text-muted">Editing presets AI applies automatically to your clips.</p>
        </div>
        <div className="flex items-center gap-2">
          <Select value={categoryId} onChange={(e) => setCategoryId(e.target.value)} className="w-auto">
            <option value="">All categories</option>
            {categoriesRes?.data.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </Select>
          {user?.role === "admin" && (
            <Button onClick={() => setModalOpen(true)}>
              <Plus className="size-4" />
              New Template
            </Button>
          )}
        </div>
      </div>

      {isLoading || !data ? (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} className="h-64" />
          ))}
        </div>
      ) : data.data.length === 0 ? (
        <EmptyState icon={<LayoutTemplate className="size-6" />} title="No templates in this category" />
      ) : (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          {data.data.map((template) => (
            <TemplateCard key={template.id} template={template} />
          ))}
        </div>
      )}

      <NewTemplateModal open={modalOpen} onClose={() => setModalOpen(false)} />
    </div>
  );
}
