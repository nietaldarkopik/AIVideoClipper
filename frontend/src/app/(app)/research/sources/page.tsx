"use client";

import { useState } from "react";
import { AlertTriangle, CheckCircle2, KeyRound, Plug, PlugZap, Radio } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { useAuthStore } from "@/store/auth";
import { useToastStore } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { formatRelativeTime } from "@/lib/format";
import type { ResearchSource } from "@/lib/types";

interface TestResult {
  ok: boolean;
  configured: boolean;
  message: string;
  latency_ms: number | null;
}

/**
 * Settings > Research Sources.
 *
 * The registry is global (shared by every user's channels), so enabling,
 * disabling and testing is admin-only — non-admins get a read-only view of what
 * is available and whether it is healthy.
 */
export default function ResearchSourcesPage() {
  const user = useAuthStore((s) => s.user);
  const isAdmin = user?.role === "admin";
  const push = useToastStore((s) => s.push);

  const { data, isLoading, mutate } = useApi<{ data: ResearchSource[] }>("/research/sources");
  const [testing, setTesting] = useState<number | null>(null);
  const [results, setResults] = useState<Record<number, TestResult>>({});

  async function toggle(source: ResearchSource) {
    try {
      await api.patch(`/admin/research/sources/${source.id}`, { enabled: !source.enabled });
      push(`${source.name} ${source.enabled ? "dinonaktifkan" : "diaktifkan"}.`, "success");
      mutate();
    } catch (error) {
      push(error instanceof ApiError ? error.message : "Gagal memperbarui sumber.", "danger");
    }
  }

  async function test(source: ResearchSource) {
    setTesting(source.id);
    try {
      const result = await api.post<TestResult>(`/admin/research/sources/${source.id}/test`);
      setResults((current) => ({ ...current, [source.id]: result }));
      push(result.ok ? `${source.name}: koneksi OK.` : `${source.name}: ${result.message}`, result.ok ? "success" : "danger");
      // A passing test resets the failure circuit, so the row's health must refresh.
      mutate();
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Test koneksi gagal.";
      setResults((current) => ({ ...current, [source.id]: { ok: false, configured: true, message, latency_ms: null } }));
      push(message, "danger");
    } finally {
      setTesting(null);
    }
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Sumber Riset</h1>
        <p className="mt-1 text-sm text-muted">
          Daftar provider yang tersedia untuk semua channel. Sebagian besar jalan tanpa API key.
          Pemilihan sumber per channel diatur di halaman channel masing-masing.
        </p>
      </div>

      {!isAdmin && (
        <Card className="border-warning/30 p-3">
          <p className="text-xs text-muted">
            Hanya admin yang bisa mengubah atau menguji sumber global. Kamu tetap bisa memilih sumber
            mana yang dipakai tiap channel-mu.
          </p>
        </Card>
      )}

      {isLoading || !data ? (
        <div className="space-y-2">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
      ) : (
        <div className="space-y-2">
          {data.data.map((source) => {
            const result = results[source.id];

            return (
              <Card key={source.id} className="p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <h2 className="text-sm font-semibold">{source.name}</h2>
                      <Badge tone={source.enabled ? "success" : "muted"} className="text-[10px]">
                        {source.enabled ? "aktif" : "nonaktif"}
                      </Badge>
                      {!source.registered && (
                        <Badge tone="danger" className="text-[10px]">
                          provider tidak terdaftar
                        </Badge>
                      )}
                      {source.requires_credentials && (
                        <Badge tone={source.is_configured ? "accent" : "warning"} className="text-[10px]">
                          <KeyRound className="size-3" />
                          {source.is_configured ? "kredensial terpasang" : "perlu kredensial"}
                        </Badge>
                      )}
                      {source.health.circuit_open && (
                        <Badge tone="danger" className="text-[10px]">
                          dilewati otomatis
                        </Badge>
                      )}
                    </div>

                    {source.description && (
                      <p className="mt-1.5 text-sm text-muted">{source.description}</p>
                    )}

                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                      <span>tipe: {source.type}</span>
                      <span>
                        {source.health.last_success_at
                          ? `sukses terakhir ${formatRelativeTime(source.health.last_success_at)}`
                          : "belum pernah sukses"}
                      </span>
                      {source.health.consecutive_failures > 0 && (
                        <span className="text-warning">
                          {source.health.consecutive_failures} kegagalan berturut-turut
                        </span>
                      )}
                    </div>

                    {source.health.last_error && (
                      <p className="mt-2 flex items-start gap-1.5 text-xs text-warning">
                        <AlertTriangle className="mt-0.5 size-3 shrink-0" />
                        <span className="line-clamp-2">{source.health.last_error}</span>
                      </p>
                    )}

                    {result && (
                      <p
                        className={
                          result.ok
                            ? "mt-2 flex items-start gap-1.5 text-xs text-success"
                            : "mt-2 flex items-start gap-1.5 text-xs text-danger"
                        }
                      >
                        {result.ok ? (
                          <CheckCircle2 className="mt-0.5 size-3 shrink-0" />
                        ) : (
                          <AlertTriangle className="mt-0.5 size-3 shrink-0" />
                        )}
                        <span className="line-clamp-3">{result.message}</span>
                      </p>
                    )}
                  </div>

                  {isAdmin && (
                    <div className="flex shrink-0 gap-2">
                      <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => test(source)}
                        loading={testing === source.id}
                        disabled={!source.registered}
                      >
                        <PlugZap className="size-3.5" />
                        Test
                      </Button>
                      <Button
                        size="sm"
                        variant={source.enabled ? "ghost" : "primary"}
                        onClick={() => toggle(source)}
                      >
                        <Plug className="size-3.5" />
                        {source.enabled ? "Nonaktifkan" : "Aktifkan"}
                      </Button>
                    </div>
                  )}
                </div>
              </Card>
            );
          })}

          {data.data.length === 0 && (
            <Card className="p-8 text-center">
              <Radio className="mx-auto size-8 text-muted" />
              <p className="mt-2 text-sm text-muted">
                Belum ada sumber riset terdaftar. Jalankan <code>php artisan db:seed</code>.
              </p>
            </Card>
          )}
        </div>
      )}
    </div>
  );
}
