"use client";

import { Modal } from "@/components/ui/Modal";

export function MediaPreviewModal({
  open,
  onClose,
  title,
  type,
  src,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  type: "image" | "video";
  src: string | null;
}) {
  return (
    <Modal open={open} onClose={onClose} title={title} className="max-w-2xl">
      {src ? (
        type === "video" ? (
          <video src={src} controls autoPlay loop muted playsInline className="max-h-[75vh] w-full rounded-xl" />
        ) : (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={src} alt={title} className="max-h-[75vh] w-full rounded-xl object-contain" />
        )
      ) : (
        <p className="p-6 text-center text-sm text-muted">Nothing generated yet.</p>
      )}
    </Modal>
  );
}
