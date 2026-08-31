"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { mutate } from "swr";
import { Lightbulb } from "lucide-react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Label, Textarea, Input } from "@/components/ui/Input";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import type { ContentBrief } from "@/lib/types";

export interface ContentBriefPrefill {
  topic: string;
  region_code?: string;
  source_platform?: string;
  source_trending_title?: string;
  source_trending_url?: string;
}

export function NewContentBriefModal({
  open,
  onClose,
  prefill,
}: {
  open: boolean;
  onClose: () => void;
  prefill?: ContentBriefPrefill | null;
}) {
  const router = useRouter();
  const [topic, setTopic] = useState(prefill?.topic ?? "");
  const [regionCode, setRegionCode] = useState(prefill?.region_code ?? "");
  const [submitting, setSubmitting] = useState(false);

  function resetAndClose() {
    setTopic("");
    setRegionCode("");
    onClose();
  }

  async function handleSubmit() {
    if (!topic.trim()) {
      toast("Masukkan topik terlebih dahulu.", "danger");
      return;
    }

    setSubmitting(true);
    try {
      const res = await api.post<{ data: ContentBrief }>("/content-briefs", {
        topic: topic.trim(),
        region_code: regionCode || undefined,
        source_platform: prefill?.source_platform,
        source_trending_title: prefill?.source_trending_title,
        source_trending_url: prefill?.source_trending_url,
      });
      await mutate((key) => typeof key === "string" && key.startsWith("/content-briefs"));
      toast("Riset konten dimulai.", "success");
      resetAndClose();
      router.push(`/content-briefs/${res.data.id}`);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Gagal memulai riset konten.", "danger");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal open={open} onClose={resetAndClose} title="Riset Konten Baru" className="max-w-xl">
      <div className="space-y-4">
        <div>
          <Label htmlFor="brief_topic">Topik</Label>
          <Textarea
            id="brief_topic"
            rows={3}
            value={topic}
            onChange={(e) => setTopic(e.target.value)}
            placeholder="Contoh: Kenaikan harga emas dunia minggu ini"
          />
          <p className="mt-1.5 text-xs text-muted">
            Bisa topik trending yang dipilih, atau ketik sendiri topik apa saja. AI akan mencari
            informasi terkait, menyusun naskah video Bahasa Indonesia, dan mencari video relevan.
          </p>
        </div>

        <div>
          <Label htmlFor="brief_region">Region (opsional)</Label>
          <Input
            id="brief_region"
            value={regionCode}
            onChange={(e) => setRegionCode(e.target.value)}
            placeholder="Contoh: ID, US"
            maxLength={10}
          />
        </div>
      </div>

      <div className="mt-6 flex justify-end gap-2">
        <Button variant="ghost" onClick={resetAndClose} disabled={submitting}>
          Batal
        </Button>
        <Button onClick={handleSubmit} loading={submitting}>
          <Lightbulb className="size-4" />
          Mulai Riset
        </Button>
      </div>
    </Modal>
  );
}
