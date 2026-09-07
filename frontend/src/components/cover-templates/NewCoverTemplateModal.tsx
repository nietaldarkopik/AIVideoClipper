"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { mutate } from "swr";
import { Plus } from "lucide-react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input, Label, Select, Textarea } from "@/components/ui/Input";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import type { CoverTemplate } from "@/lib/types";

export function NewCoverTemplateModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const router = useRouter();
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [aspectRatio, setAspectRatio] = useState<"9:16" | "1:1" | "16:9">("9:16");
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit() {
    if (!name.trim()) {
      toast("Cover template name is required.", "danger");
      return;
    }
    setSubmitting(true);
    try {
      const res = await api.post<{ data: CoverTemplate }>("/admin/cover-templates", {
        name,
        description,
        aspect_ratio: aspectRatio,
      });
      await mutate((key) => typeof key === "string" && key.startsWith("/cover-templates"));
      toast("Cover template created.", "success");
      onClose();
      router.push(`/cover-templates/${res.data.id}`);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to create cover template.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} onClose={onClose} title="New Cover Template">
      <div className="space-y-4">
        <div>
          <Label htmlFor="name">Name</Label>
          <Input id="name" value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Bold Bottom Bar" />
        </div>
        <div>
          <Label htmlFor="description">Description</Label>
          <Textarea id="description" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>
        <div>
          <Label htmlFor="aspect">Aspect Ratio</Label>
          <Select id="aspect" value={aspectRatio} onChange={(e) => setAspectRatio(e.target.value as typeof aspectRatio)}>
            <option value="9:16">9:16 — Reels / TikTok / Shorts</option>
            <option value="16:9">16:9 — YouTube / Facebook</option>
            <option value="1:1">1:1 — Square</option>
          </Select>
        </div>
      </div>
      <div className="mt-6 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={submitting}>
          Cancel
        </Button>
        <Button onClick={handleSubmit} loading={submitting}>
          <Plus className="size-4" />
          Create Cover Template
        </Button>
      </div>
    </Modal>
  );
}
