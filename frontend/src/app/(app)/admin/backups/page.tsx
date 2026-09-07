"use client";

import { useState } from "react";
import { mutate } from "swr";
import { DatabaseBackup, Download, Trash2 } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, apiOrigin, ApiError } from "@/lib/api";
import { useAuthStore } from "@/store/auth";
import { toast } from "@/store/toast";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { formatRelativeTime } from "@/lib/format";

interface BackupFile {
  filename: string;
  size: number;
  created_at: string;
}

function formatBytes(bytes: number): string {
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

const backupsKey = "/admin/backups";

export default function AdminBackupsPage() {
  const { data, isLoading } = useApi<{ data: BackupFile[] }>(backupsKey);
  const [creating, setCreating] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);

  async function handleCreate() {
    setCreating(true);
    try {
      await api.post(backupsKey);
      await mutate(backupsKey);
      toast("Database backup created.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to create backup.", "danger");
    } finally {
      setCreating(false);
    }
  }

  async function handleDownload(filename: string) {
    setBusy(filename);
    try {
      const token = useAuthStore.getState().token;
      const res = await fetch(`${apiOrigin()}/api/admin/backups/${encodeURIComponent(filename)}/download`, {
        headers: { Authorization: `Bearer ${token}` },
      });
      if (!res.ok) throw new Error("Download failed");
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = filename;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      toast("Failed to download backup.", "danger");
    } finally {
      setBusy(null);
    }
  }

  async function handleDelete(filename: string) {
    if (!confirm(`Delete ${filename}? This can't be undone.`)) return;
    setBusy(filename);
    try {
      await api.del(`/admin/backups/${encodeURIComponent(filename)}`);
      await mutate(backupsKey);
      toast("Backup deleted.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to delete backup.", "danger");
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex items-start justify-between gap-4 rounded-2xl border border-border-subtle bg-surface-elevated p-4">
        <div className="text-sm text-muted">
          <p className="font-medium text-foreground">Database backups</p>
          <p className="mt-1">
            A plain-SQL dump of the app database (pg_dump) — small enough to download here. Restore on another
            server with <code className="rounded bg-black/20 px-1 py-0.5">php artisan restore:database &lt;file&gt;</code>.
          </p>
          <p className="mt-2">
            Media files (source videos, rendered clips, covers — routinely hundreds of GB) are backed up separately
            on the server itself:{" "}
            <code className="rounded bg-black/20 px-1 py-0.5">php artisan backup:files</code>, restored with{" "}
            <code className="rounded bg-black/20 px-1 py-0.5">php artisan restore:files &lt;file&gt;</code>. Too
            large for a browser download, so there's no button for it here — run{" "}
            <code className="rounded bg-black/20 px-1 py-0.5">php artisan backup:run</code> on the server to do both
            at once when migrating.
          </p>
        </div>
        <Button onClick={handleCreate} loading={creating} className="shrink-0">
          <DatabaseBackup className="size-4" />
          Create Backup
        </Button>
      </div>

      {isLoading || !data ? (
        <Skeleton className="h-60" />
      ) : data.data.length === 0 ? (
        <EmptyState
          icon={<DatabaseBackup className="size-6" />}
          title="No backups yet"
          description="Create one before migrating this app to another server."
        />
      ) : (
        <Card className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-border-subtle text-left text-xs text-muted">
                <th className="px-5 py-3 font-medium">File</th>
                <th className="px-5 py-3 font-medium">Size</th>
                <th className="px-5 py-3 font-medium">Created</th>
                <th className="px-5 py-3 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-subtle">
              {data.data.map((b) => (
                <tr key={b.filename}>
                  <td className="px-5 py-3 font-mono text-xs">{b.filename}</td>
                  <td className="px-5 py-3 text-muted">{formatBytes(b.size)}</td>
                  <td className="px-5 py-3 text-muted">{formatRelativeTime(b.created_at)}</td>
                  <td className="px-5 py-3">
                    <div className="flex items-center justify-end gap-1.5">
                      <button
                        onClick={() => handleDownload(b.filename)}
                        disabled={busy === b.filename}
                        title="Download"
                        className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer disabled:opacity-50"
                      >
                        <Download className="size-3.5" />
                      </button>
                      <button
                        onClick={() => handleDelete(b.filename)}
                        disabled={busy === b.filename}
                        title="Delete"
                        className="rounded-lg p-1.5 text-muted hover:bg-danger/10 hover:text-danger cursor-pointer disabled:opacity-50"
                      >
                        <Trash2 className="size-3.5" />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}
    </div>
  );
}
