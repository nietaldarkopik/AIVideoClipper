"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { mutate } from "swr";
import { Plus } from "lucide-react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input, Label, Select, Textarea } from "@/components/ui/Input";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import type { Template, TemplateCategory } from "@/lib/types";

export function NewTemplateModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const router = useRouter();
  const { data: categoriesRes } = useApi<{ data: TemplateCategory[] }>(open ? "/template-categories" : null);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [aspectRatio, setAspectRatio] = useState("9:16");
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    if (!name.trim()) {
      toast("Template name is required.", "danger");
      return;
    }
    setSubmitting(true);
    try {
      const res = await api.post<{ data: Template }>("/admin/templates", {
        name,
        description,
        template_category_id: categoryId ? Number(categoryId) : null,
        aspect_ratio: aspectRatio,
      });
      await mutate((key) => typeof key === "string" && key.startsWith("/templates"));
      toast("Template created.", "success");
      onClose();
      router.push(`/templates/${res.data.id}`);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to create template.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} onClose={onClose} title="New Template">
      <div className="space-y-4">
        <div>
          <Label htmlFor="name">Name</Label>
          <Input id="name" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Bold Captions" />
        </div>
        <div>
          <Label htmlFor="description">Description</Label>
          <Textarea id="description" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>
        <div className="grid grid-cols-2 gap-4">
          <div>
            <Label htmlFor="category">Category</Label>
            <Select id="category" value={categoryId} onChange={(e) => setCategoryId(e.target.value)}>
              <option value="">Uncategorized</option>
              {categoriesRes?.data.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label htmlFor="aspect">Aspect Ratio</Label>
            <Select id="aspect" value={aspectRatio} onChange={(e) => setAspectRatio(e.target.value)}>
              <option value="9:16">9:16</option>
              <option value="1:1">1:1</option>
              <option value="16:9">16:9</option>
            </Select>
          </div>
        </div>
      </div>
      <div className="mt-6 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>
          Cancel
        </Button>
        <Button onClick={handleSubmit} loading={submitting}>
          <Plus className="size-4" />
          Create Template
        </Button>
      </div>
    </Modal>
  );
}
