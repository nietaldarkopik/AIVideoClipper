"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { Clapperboard } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { cartoonApi, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";

/**
 * Starts a cartoon explainer video from a research result and jumps to its page,
 * where the storyboard appears once the (cheap) storyboard job finishes. Nothing
 * paid is generated until the user presses Render there.
 */
export function CreateCartoonVideoButton({
  sourceType,
  sourceId,
  disabled,
  title,
  variant = "secondary",
  size,
}: {
  sourceType: "content_idea" | "content_brief";
  sourceId: number;
  disabled?: boolean;
  title?: string;
  variant?: "primary" | "secondary";
  size?: "sm" | "md";
}) {
  const router = useRouter();
  const [busy, setBusy] = useState(false);

  async function handleClick() {
    setBusy(true);
    try {
      const res = await cartoonApi.create({ source_type: sourceType, source_id: sourceId });
      router.push(`/cartoon-videos/${res.data.id}`);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Gagal membuat video kartun.", "danger");
      setBusy(false);
    }
  }

  return (
    <Button variant={variant} size={size} onClick={handleClick} loading={busy} disabled={disabled} title={title}>
      <Clapperboard className="size-4" />
      Buat Video Kartun
    </Button>
  );
}
