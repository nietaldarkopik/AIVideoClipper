"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { Plus, Bot, ArrowRight, Square, CheckSquare, StopCircle, Trash2, X, Search } from "lucide-react";
import { mutate } from "swr";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Input, Select } from "@/components/ui/Input";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { StatusBadge } from "@/components/ui/Badge";
import { ProgressBar } from "@/components/ui/ProgressBar";
import { Pagination } from "@/components/ui/Pagination";
import { formatRelativeTime } from "@/lib/format";
import { NewBatchModal } from "@/components/batches/NewBatchModal";
import type { Paginated, VideoBatch } from "@/lib/types";

const ACTIVE_STATUSES = ["pending", "running"];

const STATUS_OPTIONS = [
  { value: "", label: "All statuses" },
  { value: "pending", label: "Pending" },
  { value: "running", label: "Running" },
  { value: "completed", label: "Completed" },
  { value: "completed_with_errors", label: "Completed with errors" },
  { value: "failed", label: "Failed" },
  { value: "cancelled", label: "Cancelled" },
];

export default function BatchesPage() {
  const [modalOpen, setModalOpen] = useState(false);
  const [selected, setSelected] = useState<number[]>([]);
  const [stoppingId, setStoppingId] = useState<number | null>(null);
  const [bulkStopping, setBulkStopping] = useState(false);
  const [bulkDeleting, setBulkDeleting] = useState(false);
  const [status, setStatus] = useState("");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);

  function resetListing() {
    setPage(1);
    setSelected([]);
  }

  const key = useMemo(() => {
    const params = new URLSearchParams({ per_page: String(perPage), page: String(page) });
    if (status) params.set("status", status);
    if (search) params.set("search", search);
    return `/video-batches?${params.toString()}`;
  }, [status, search, page, perPage]);

  const { data, isLoading } = useApi<Paginated<VideoBatch>>(key, {
    refreshInterval: (latest?: Paginated<VideoBatch>) =>
      latest?.data.some((b: VideoBatch) => ACTIVE_STATUSES.includes(b.status)) ? 3000 : 0,
  });

  function handleSearchSubmit(e: React.FormEvent) {
    e.preventDefault();
    resetListing();
    setSearch(searchInput);
  }

  function clearSearch() {
    setSearchInput("");
    setSearch("");
    resetListing();
  }

  function toggle(id: number) {
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  }

  const allSelected = (data?.data.length ?? 0) > 0 && (data?.data.every((b) => selected.includes(b.id)) ?? false);

  function toggleSelectAll() {
    if (!data) return;
    setSelected(allSelected ? [] : data.data.map((b) => b.id));
  }

  async function handleStop(id: number) {
    if (!confirm("Stop this batch? Videos already in progress will finish their current step, then the rest are skipped.")) {
      return;
    }
    setStoppingId(id);
    try {
      await api.post(`/video-batches/${id}/cancel`);
      await mutate(key);
      toast("Batch stopped.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to stop batch.", "danger");
    } finally {
      setStoppingId(null);
    }
  }

  async function handleBulkStop() {
    if (selected.length === 0) return;
    if (!confirm(`Stop ${selected.length} batch(es)? Videos already in progress will finish their current step, then the rest are skipped.`)) return;
    setBulkStopping(true);
    try {
      const res = await api.post<{ message: string; cancelled_count: number }>("/video-batches/bulk-cancel", {
        batch_ids: selected,
      });
      await mutate(key);
      toast(res.message, "success");
      setSelected([]);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to stop batches.", "danger");
    } finally {
      setBulkStopping(false);
    }
  }

  async function handleBulkDelete() {
    if (selected.length === 0) return;
    if (!confirm(`Delete ${selected.length} batch(es)? This cannot be undone. Still-running batches will be skipped — stop them first.`)) return;
    setBulkDeleting(true);
    try {
      const res = await api.post<{ message: string; deleted_count: number; skipped_count: number }>(
        "/video-batches/bulk-delete",
        { batch_ids: selected }
      );
      await mutate(key);
      toast(res.message, res.skipped_count > 0 ? "default" : "success");
      setSelected([]);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to delete batches.", "danger");
    } finally {
      setBulkDeleting(false);
    }
  }

  const selectedHasActive = data?.data.some((b) => selected.includes(b.id) && ACTIVE_STATUSES.includes(b.status)) ?? false;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Batch Autobot</h1>
          <p className="mt-1 text-sm text-muted">
            Paste a list of video URLs — each one gets imported, transcribed, clipped, and
            published automatically, one at a time.
          </p>
        </div>
        <Button onClick={() => setModalOpen(true)}>
          <Plus className="size-4" />
          New Batch
        </Button>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <form onSubmit={handleSearchSubmit} className="flex items-center gap-1.5">
          <Input
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            placeholder="Search batches by name..."
            className="w-56"
          />
          <Button type="submit" size="sm" variant="secondary">
            <Search className="size-3.5" />
          </Button>
          {search && (
            <Button type="button" size="sm" variant="ghost" onClick={clearSearch}>
              <X className="size-3.5" />
            </Button>
          )}
        </form>
        <Select
          value={status}
          onChange={(e) => {
            setStatus(e.target.value);
            resetListing();
          }}
          className="w-48"
        >
          {STATUS_OPTIONS.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </Select>
      </div>

      {isLoading || !data ? (
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
      ) : data.data.length === 0 ? (
        <EmptyState
          icon={<Bot className="size-6" />}
          title={status || search ? "No matching batches" : "No batches yet"}
          description={
            status || search
              ? "Try a different search or status filter."
              : "Start a batch to automate a whole list of videos end-to-end."
          }
          action={
            !status && !search ? (
              <Button size="sm" onClick={() => setModalOpen(true)}>
                <Plus className="size-4" />
                New Batch
              </Button>
            ) : undefined
          }
        />
      ) : (
        <>
          <button
            type="button"
            onClick={toggleSelectAll}
            className="flex items-center gap-1.5 text-xs text-muted hover:text-foreground cursor-pointer"
          >
            {allSelected ? <CheckSquare className="size-3.5" /> : <Square className="size-3.5" />}
            {allSelected ? "Unselect all" : `Select all ${data.data.length} on this page`}
          </button>

          {selected.length > 0 && (
            <div className="flex items-center justify-between rounded-xl border border-accent/30 bg-accent/5 px-4 py-2.5">
              <span className="text-sm">{selected.length} batch(es) selected</span>
              <div className="flex items-center gap-2">
                <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
                  <X className="size-3.5" />
                  Clear
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={handleBulkStop}
                  loading={bulkStopping}
                  disabled={!selectedHasActive}
                >
                  <StopCircle className="size-3.5" />
                  Stop
                </Button>
                <Button size="sm" variant="danger" onClick={handleBulkDelete} loading={bulkDeleting}>
                  <Trash2 className="size-3.5" />
                  Delete
                </Button>
              </div>
            </div>
          )}

          <div className="space-y-3">
            {data.data.map((batch) => (
              <Card
                key={batch.id}
                className="group flex items-center gap-4 p-5 transition-colors hover:border-accent/40"
              >
                <button
                  type="button"
                  onClick={() => toggle(batch.id)}
                  className="shrink-0 text-muted hover:text-foreground cursor-pointer"
                >
                  {selected.includes(batch.id) ? (
                    <CheckSquare className="size-4" />
                  ) : (
                    <Square className="size-4" />
                  )}
                </button>

                <Link href={`/batches/${batch.id}`} className="flex min-w-0 flex-1 items-center justify-between gap-4">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2.5">
                      <h3 className="truncate text-sm font-semibold">
                        {batch.name || `Batch #${batch.id}`}
                      </h3>
                      <StatusBadge status={batch.status} />
                    </div>
                    <p className="mt-1 text-xs text-muted">
                      {batch.completed_items}/{batch.total_items} videos done
                      {batch.failed_items > 0 && ` · ${batch.failed_items} failed`}
                      {" · "}
                      Started {formatRelativeTime(batch.created_at)}
                    </p>
                    <ProgressBar
                      className="mt-3 max-w-sm"
                      value={batch.total_items ? (batch.completed_items / batch.total_items) * 100 : 0}
                      tone={batch.failed_items > 0 ? "danger" : "accent"}
                    />
                  </div>
                  <ArrowRight className="size-4 shrink-0 text-muted transition-colors group-hover:text-accent-2" />
                </Link>

                {ACTIVE_STATUSES.includes(batch.status) && (
                  <Button
                    size="sm"
                    variant="danger"
                    onClick={() => handleStop(batch.id)}
                    loading={stoppingId === batch.id}
                    className="shrink-0"
                  >
                    <StopCircle className="size-3.5" />
                    Stop
                  </Button>
                )}
              </Card>
            ))}
          </div>

          {data.meta && data.meta.last_page > 0 && (
            <Pagination
              page={data.meta.current_page}
              lastPage={data.meta.last_page}
              total={data.meta.total}
              perPage={perPage}
              itemLabel="batches"
              onPageChange={(p) => {
                setPage(p);
                setSelected([]);
              }}
              onPerPageChange={(n) => {
                setPerPage(n);
                resetListing();
              }}
            />
          )}
        </>
      )}

      <NewBatchModal open={modalOpen} onClose={() => setModalOpen(false)} />
    </div>
  );
}
