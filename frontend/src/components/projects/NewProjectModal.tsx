"use client";

import { useState, useRef, DragEvent } from "react";
import { useRouter } from "next/navigation";
import { mutate } from "swr";
import { Upload, Link2, FileVideo, X, Globe } from "lucide-react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Input, Label } from "@/components/ui/Input";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import type { Project } from "@/lib/types";

type Tab = "upload" | "url";

export function NewProjectModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const router = useRouter();
  const [tab, setTab] = useState<Tab>("upload");
  const [files, setFiles] = useState<File[]>([]);
  const [url, setUrl] = useState("");
  const [dragging, setDragging] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [progressLabel, setProgressLabel] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  function reset() {
    setFiles([]);
    setUrl("");
    setSubmitting(false);
    setProgressLabel(null);
    setTab("upload");
  }

  function handleClose() {
    if (submitting) return;
    reset();
    onClose();
  }

  function addFiles(list: FileList | null) {
    if (!list) return;
    setFiles((prev) => [...prev, ...Array.from(list).filter((f) => f.type.startsWith("video/"))]);
  }

  async function createProjectFromFile(file: File) {
    const title = file.name.replace(/\.[^/.]+$/, "");
    const project = await api.post<{ data: Project }>("/projects", { title });
    const form = new FormData();
    form.append("file", file);
    await api.post(`/projects/${project.data.id}/videos`, form);
    return project.data;
  }

  async function createProjectFromUrl(sourceUrl: string) {
    const project = await api.post<{ data: Project }>("/projects", {
      title: "Imported Video",
    });
    await api.post(`/projects/${project.data.id}/videos`, { url: sourceUrl });
    return project.data;
  }

  async function handleSubmit() {
    setSubmitting(true);
    try {
      if (tab === "upload") {
        if (files.length === 0) {
          toast("Choose at least one video file.", "danger");
          setSubmitting(false);
          return;
        }
        let firstProject: Project | null = null;
        for (let i = 0; i < files.length; i++) {
          setProgressLabel(`Uploading ${i + 1} of ${files.length}...`);
          const project = await createProjectFromFile(files[i]);
          if (!firstProject) firstProject = project;
        }
        await mutate("/projects");
        await mutate("/dashboard");
        toast(files.length > 1 ? `${files.length} projects created.` : "Project created.", "success");
        handleClose();
        if (firstProject) router.push(`/projects/${firstProject.id}`);
      } else {
        if (!url.trim()) {
          toast("Paste a video URL.", "danger");
          setSubmitting(false);
          return;
        }
        setProgressLabel("Starting import...");
        const project = await createProjectFromUrl(url.trim());
        await mutate("/projects");
        await mutate("/dashboard");
        toast("Import started.", "success");
        handleClose();
        router.push(`/projects/${project.id}`);
      }
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Something went wrong.", "danger");
      setSubmitting(false);
      setProgressLabel(null);
    }
  }

  return (
    <Modal open={open} onClose={handleClose} title="New Project">
      <div className="mb-4 flex gap-1 rounded-xl bg-surface p-1">
        <button
          onClick={() => setTab("upload")}
          className={`flex-1 rounded-lg py-2 text-sm font-medium transition-colors cursor-pointer ${
            tab === "upload" ? "bg-surface-elevated text-foreground" : "text-muted"
          }`}
        >
          Upload Video
        </button>
        <button
          onClick={() => setTab("url")}
          className={`flex-1 rounded-lg py-2 text-sm font-medium transition-colors cursor-pointer ${
            tab === "url" ? "bg-surface-elevated text-foreground" : "text-muted"
          }`}
        >
          Import from URL
        </button>
      </div>

      {tab === "upload" ? (
        <div className="space-y-3">
          <div
            onDragOver={(e: DragEvent) => {
              e.preventDefault();
              setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={(e: DragEvent) => {
              e.preventDefault();
              setDragging(false);
              addFiles(e.dataTransfer.files);
            }}
            onClick={() => inputRef.current?.click()}
            className={`flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-6 py-10 text-center transition-colors ${
              dragging ? "border-accent bg-accent/5" : "border-border-subtle hover:border-accent/40"
            }`}
          >
            <Upload className="size-6 text-muted" />
            <p className="text-sm font-medium">Drag & drop video files</p>
            <p className="text-xs text-muted">or click to browse — multiple files supported</p>
            <input
              ref={inputRef}
              type="file"
              accept="video/*"
              multiple
              className="hidden"
              onChange={(e) => addFiles(e.target.files)}
            />
          </div>

          {files.length > 0 && (
            <ul className="max-h-40 space-y-1.5 overflow-y-auto">
              {files.map((f, i) => (
                <li
                  key={i}
                  className="flex items-center gap-2 rounded-lg bg-surface px-3 py-2 text-xs"
                >
                  <FileVideo className="size-3.5 shrink-0 text-accent-2" />
                  <span className="flex-1 truncate">{f.name}</span>
                  <button
                    onClick={() => setFiles((prev) => prev.filter((_, idx) => idx !== i))}
                    className="text-muted hover:text-danger cursor-pointer"
                  >
                    <X className="size-3.5" />
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      ) : (
        <div>
          <Label htmlFor="url">Video URL</Label>
          <Input
            id="url"
            placeholder="https://www.youtube.com/watch?v=..."
            value={url}
            onChange={(e) => setUrl(e.target.value)}
          />
          <p className="mt-2 flex items-center gap-1.5 text-xs text-muted">
            <Globe className="size-3.5" />
            Supports YouTube, TikTok, Instagram, Facebook, X/Twitter, Vimeo, and direct video links.
          </p>
        </div>
      )}

      {progressLabel && <p className="mt-3 text-xs text-accent-2">{progressLabel}</p>}

      <div className="mt-6 flex justify-end gap-2">
        <Button variant="ghost" onClick={handleClose} disabled={submitting}>
          Cancel
        </Button>
        <Button onClick={handleSubmit} loading={submitting}>
          {tab === "upload" ? <Upload className="size-4" /> : <Link2 className="size-4" />}
          {tab === "upload" ? "Upload & Create" : "Import & Create"}
        </Button>
      </div>
    </Modal>
  );
}
