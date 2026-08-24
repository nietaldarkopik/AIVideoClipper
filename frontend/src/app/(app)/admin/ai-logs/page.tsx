"use client";

import { useState } from "react";
import { useApi } from "@/lib/hooks";
import { Card } from "@/components/ui/Card";
import { Select } from "@/components/ui/Input";
import { StatusBadge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { Button } from "@/components/ui/Button";
import { formatRelativeTime } from "@/lib/format";
import { AiLogDetailModal } from "@/components/admin/AiLogDetailModal";
import type { AiRequestLog, Paginated } from "@/lib/types";

const CAPABILITIES = [
  { value: "", label: "All capabilities" },
  { value: "content_analysis", label: "Content Analysis" },
  { value: "transcription", label: "Transcription" },
  { value: "social_metadata", label: "Social Metadata" },
];

const PROVIDERS = [
  { value: "", label: "All providers" },
  { value: "ollama", label: "Ollama" },
  { value: "openai", label: "OpenAI" },
  { value: "claude", label: "Claude" },
  { value: "gemini", label: "Gemini" },
  { value: "nine_router", label: "9Router" },
  { value: "whisper_engine", label: "Whisper Engine" },
];

const STATUSES = [
  { value: "", label: "All statuses" },
  { value: "success", label: "Success" },
  { value: "failed", label: "Failed" },
];

export default function AdminAiLogsPage() {
  const [capability, setCapability] = useState("");
  const [provider, setProvider] = useState("");
  const [status, setStatus] = useState("");
  const [selected, setSelected] = useState<AiRequestLog | null>(null);

  const query = new URLSearchParams({
    per_page: "50",
    ...(capability ? { capability } : {}),
    ...(provider ? { provider } : {}),
    ...(status ? { status } : {}),
  }).toString();

  const { data, isLoading } = useApi<Paginated<AiRequestLog>>(`/admin/ai-request-logs?${query}`, {
    refreshInterval: 5000,
  });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-3">
        <Select value={capability} onChange={(e) => setCapability(e.target.value)} className="w-48">
          {CAPABILITIES.map((c) => (
            <option key={c.value} value={c.value}>
              {c.label}
            </option>
          ))}
        </Select>
        <Select value={provider} onChange={(e) => setProvider(e.target.value)} className="w-44">
          {PROVIDERS.map((p) => (
            <option key={p.value} value={p.value}>
              {p.label}
            </option>
          ))}
        </Select>
        <Select value={status} onChange={(e) => setStatus(e.target.value)} className="w-36">
          {STATUSES.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </Select>
      </div>

      {isLoading || !data ? (
        <Skeleton className="h-80" />
      ) : (
        <Card className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border-subtle text-left text-xs text-muted">
                <th className="px-5 py-3 font-medium">Capability</th>
                <th className="px-5 py-3 font-medium">Provider</th>
                <th className="px-5 py-3 font-medium">Model</th>
                <th className="px-5 py-3 font-medium">Status</th>
                <th className="px-5 py-3 font-medium">Duration</th>
                <th className="px-5 py-3 font-medium">Created</th>
                <th className="px-5 py-3 font-medium"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-subtle">
              {data.data.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-5 py-8 text-center text-xs text-muted">
                    No AI requests logged yet.
                  </td>
                </tr>
              ) : (
                data.data.map((log) => (
                  <tr key={log.id}>
                    <td className="px-5 py-3 capitalize">{log.capability.replace(/_/g, " ")}</td>
                    <td className="px-5 py-3 text-muted">{log.provider}</td>
                    <td className="max-w-40 truncate px-5 py-3 text-muted">{log.model ?? "--"}</td>
                    <td className="px-5 py-3">
                      <StatusBadge status={log.status} />
                    </td>
                    <td className="px-5 py-3 text-muted">
                      {log.duration_ms !== null ? `${(log.duration_ms / 1000).toFixed(2)}s` : "--"}
                    </td>
                    <td className="px-5 py-3 text-muted">{formatRelativeTime(log.created_at)}</td>
                    <td className="px-5 py-3 text-right">
                      <Button size="sm" variant="outline" onClick={() => setSelected(log)}>
                        View
                      </Button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </Card>
      )}

      <AiLogDetailModal log={selected} onClose={() => setSelected(null)} />
    </div>
  );
}
