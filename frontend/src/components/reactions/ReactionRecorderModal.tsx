"use client";

import { useEffect, useRef, useState } from "react";
import { Circle, Square, Video, RotateCcw, Sparkles } from "lucide-react";
import { Modal } from "@/components/ui/Modal";
import { Button } from "@/components/ui/Button";
import { Label, Select } from "@/components/ui/Input";
import { ReactionLayoutPicker } from "@/components/reactions/ReactionLayoutPicker";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { formatDuration } from "@/lib/format";
import type { Clip, ReactionLayout, Template } from "@/lib/types";

type Step = "layout" | "record" | "review";

export function ReactionRecorderModal({
  open,
  onClose,
  startTime,
  endTime,
  sourceVideoUrl,
  submitUrl,
  onSuccess,
}: {
  open: boolean;
  onClose: () => void;
  /** Seconds into the source video where the moment to react to starts/ends. */
  startTime: number;
  endTime: number;
  sourceVideoUrl: string | null;
  /** Where to POST the recording — either `/clip-candidates/{id}/reaction` (creates a
   *  new Clip) or `/clips/{id}/reaction` (re-renders an existing one in place). */
  submitUrl: string;
  onSuccess: (clip: Clip) => void;
}) {
  const { data: templatesData } = useApi<{ data: Template[] }>(open ? "/templates" : null);

  const [step, setStep] = useState<Step>("layout");
  const [layout, setLayout] = useState<ReactionLayout>("pip_bottom_right");
  const [templateId, setTemplateId] = useState("");
  const [aspectRatio, setAspectRatio] = useState("9:16");
  const [recording, setRecording] = useState(false);
  const [recordedBlob, setRecordedBlob] = useState<Blob | null>(null);
  const [cameraError, setCameraError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const sourceVideoRef = useRef<HTMLVideoElement>(null);
  const webcamVideoRef = useRef<HTMLVideoElement>(null);
  const reviewVideoRef = useRef<HTMLVideoElement>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const recorderRef = useRef<MediaRecorder | null>(null);
  const chunksRef = useRef<Blob[]>([]);
  const reviewUrlRef = useRef<string | null>(null);

  function reset() {
    setStep("layout");
    setLayout("pip_bottom_right");
    setTemplateId("");
    setAspectRatio("9:16");
    setRecording(false);
    setRecordedBlob(null);
    setCameraError(null);
    setSubmitting(false);
    stopStream();
  }

  function stopStream() {
    streamRef.current?.getTracks().forEach((t) => t.stop());
    streamRef.current = null;
  }

  function handleClose() {
    if (submitting || recording) return;
    reset();
    onClose();
  }

  async function ensureCamera() {
    if (streamRef.current) {
      // The <video> element remounts fresh each time we return to this step
      // (e.g. after a Retake), so its srcObject needs re-attaching even though
      // the underlying stream is already acquired.
      if (webcamVideoRef.current) webcamVideoRef.current.srcObject = streamRef.current;
      return streamRef.current;
    }

    if (!navigator.mediaDevices?.getUserMedia) {
      setCameraError(
        "This browser doesn't support camera access here (requires a secure context — localhost or HTTPS)."
      );
      return null;
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
      streamRef.current = stream;
      if (webcamVideoRef.current) webcamVideoRef.current.srcObject = stream;
      return stream;
    } catch (err) {
      console.error("getUserMedia failed:", err);
      const name = err instanceof DOMException ? err.name : "";
      const message =
        name === "NotAllowedError"
          ? "Camera/microphone access was denied. Check the site permissions in your browser's address bar and allow access."
          : name === "NotFoundError"
            ? "No camera or microphone was found on this device."
            : name === "NotReadableError"
              ? "Your camera/microphone is already in use by another app."
              : "Couldn't access your camera/microphone. Check browser permissions and try again.";
      setCameraError(message);
      return null;
    }
  }

  function goToRecordStep() {
    setCameraError(null);
    setStep("record");
  }

  // Acquire the camera only once the record step's <video> element has actually
  // mounted — calling getUserMedia synchronously right after setStep("record")
  // races React's render, so webcamVideoRef.current can still be null when the
  // stream resolves.
  useEffect(() => {
    if (step === "record") ensureCamera();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [step]);

  function stopRecording() {
    if (recorderRef.current?.state === "recording") recorderRef.current.stop();
    sourceVideoRef.current?.pause();
    setRecording(false);
  }

  async function startRecording() {
    const stream = await ensureCamera();
    if (!stream || !sourceVideoRef.current) return;

    chunksRef.current = [];
    const recorder = new MediaRecorder(stream, { mimeType: "video/webm" });
    recorder.ondataavailable = (e) => {
      if (e.data.size > 0) chunksRef.current.push(e.data);
    };
    recorder.onstop = () => {
      const blob = new Blob(chunksRef.current, { type: "video/webm" });
      setRecordedBlob(blob);
      setStep("review");
    };
    recorderRef.current = recorder;

    sourceVideoRef.current.currentTime = startTime;
    recorder.start();
    setRecording(true);
    await sourceVideoRef.current.play();
  }

  function handleSourceTimeUpdate() {
    if (recording && sourceVideoRef.current && sourceVideoRef.current.currentTime >= endTime) {
      stopRecording();
    }
  }

  function handleRetake() {
    setRecordedBlob(null);
    setStep("record");
  }

  async function handleSubmit() {
    if (!recordedBlob) return;
    setSubmitting(true);
    try {
      const form = new FormData();
      form.append("webcam", recordedBlob, "reaction.webm");
      form.append("layout", layout);
      if (templateId) form.append("template_id", templateId);
      form.append("aspect_ratio", aspectRatio);

      const res = await api.post<{ data: Clip }>(submitUrl, form);
      toast("Reaction clip queued for rendering.", "success");
      handleClose();
      onSuccess(res.data);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Something went wrong.", "danger");
      setSubmitting(false);
    }
  }

  // Play back the recorded take once we land on the review step.
  useEffect(() => {
    if (step !== "review" || !recordedBlob || !reviewVideoRef.current) return;
    if (reviewUrlRef.current) URL.revokeObjectURL(reviewUrlRef.current);
    const url = URL.createObjectURL(recordedBlob);
    reviewUrlRef.current = url;
    reviewVideoRef.current.src = url;
    return () => {
      if (reviewUrlRef.current) URL.revokeObjectURL(reviewUrlRef.current);
      reviewUrlRef.current = null;
    };
  }, [step, recordedBlob]);

  useEffect(() => {
    if (!open) reset();
    // Release the camera whenever the modal is closed/unmounted.
    return () => stopStream();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  return (
    <Modal open={open} onClose={handleClose} title="React to this Moment" className="max-w-2xl">
      {step === "layout" && (
        <div className="space-y-4">
          <p className="text-sm text-muted">
            Pick a layout for your reaction, then record yourself watching this clip
            (
            {formatDuration(startTime)} → {formatDuration(endTime)}
            ).
          </p>
          <ReactionLayoutPicker value={layout} onChange={setLayout} />

          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label htmlFor="template">Template</Label>
              <Select id="template" value={templateId} onChange={(e) => setTemplateId(e.target.value)}>
                <option value="">No template (default captions)</option>
                {templatesData?.data.map((t) => (
                  <option key={t.id} value={t.id}>
                    {t.name} — {t.aspect_ratio}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label htmlFor="aspect">Aspect Ratio</Label>
              <Select id="aspect" value={aspectRatio} onChange={(e) => setAspectRatio(e.target.value)}>
                <option value="9:16">9:16 — TikTok / Reels / Shorts</option>
                <option value="1:1">1:1 — Square</option>
                <option value="16:9">16:9 — Landscape</option>
              </Select>
            </div>
          </div>

          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={handleClose}>
              Cancel
            </Button>
            <Button onClick={goToRecordStep} disabled={!sourceVideoUrl}>
              <Video className="size-4" />
              Continue to Recording
            </Button>
          </div>
        </div>
      )}

      {step === "record" && (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <p className="mb-1.5 text-xs font-medium text-muted">Source clip</p>
              <video
                ref={sourceVideoRef}
                src={sourceVideoUrl ?? undefined}
                onTimeUpdate={handleSourceTimeUpdate}
                className="aspect-video w-full rounded-xl bg-black"
                playsInline
              />
            </div>
            <div>
              <p className="mb-1.5 text-xs font-medium text-muted">Your webcam</p>
              <video
                ref={webcamVideoRef}
                autoPlay
                muted
                playsInline
                className="aspect-video w-full rounded-xl bg-black object-cover"
              />
            </div>
          </div>

          {cameraError && <p className="text-xs text-danger">{cameraError}</p>}
          <p className="text-xs text-muted">
            Tip: wear headphones so the source clip's audio doesn't bleed into your mic. Recording
            starts the clip from the top and stops automatically when it ends.
          </p>

          <div className="flex justify-between gap-2">
            <Button variant="ghost" onClick={() => setStep("layout")} disabled={recording}>
              Back
            </Button>
            {recording ? (
              <Button variant="danger" onClick={stopRecording}>
                <Square className="size-4" />
                Stop Recording
              </Button>
            ) : (
              <Button onClick={startRecording} disabled={!!cameraError}>
                <Circle className="size-4 fill-current" />
                Start Recording
              </Button>
            )}
          </div>
        </div>
      )}

      {step === "review" && (
        <div className="space-y-4">
          <p className="mb-1.5 text-xs font-medium text-muted">Your take</p>
          <video ref={reviewVideoRef} controls className="aspect-video w-full rounded-xl bg-black" />

          <div className="flex justify-between gap-2">
            <Button variant="ghost" onClick={handleRetake} disabled={submitting}>
              <RotateCcw className="size-4" />
              Retake
            </Button>
            <Button onClick={handleSubmit} loading={submitting}>
              <Sparkles className="size-4" />
              Use This Take
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
