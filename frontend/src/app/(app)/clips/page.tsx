"use client";

import { useState } from "react";
import { Scissors, Download, X, Search } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { ApiError, apiOrigin } from "@/lib/api";
import { toast } from "@/store/toast";
import { useAuthStore } from "@/store/auth";
import { Button } from "@/components/ui/Button";
import { Input, Select } from "@/components/ui/Input";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { ClipCard } from "@/components/clips/ClipCard";
import type { Clip, Paginated } from "@/lib/types";

const STATUS_OPTIONS = [
  { value: "", label: "All statuses" },
  { value: "completed", label: "Completed" },
  { value: "rendering", label: "Rendering" },
  { value: "queued", label: "Queued" },
  { value: "failed", label: "Failed" },
];

export default function AllClipsPage() {
  const [status, setStatus] = useState("");
  const [selected, setSelected] = useState<number[]>([]);
  const [exporting, setExporting] = useState(false);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");

  const isSearching = search.trim() !== "";
  const key = isSearching
    ? `/clips/search?q=${encodeURIComponent(search.trim())}`
    : `/clips?per_page=60${status ? `&status=${status}` : ""}`;
  const { data, isLoading } = useApi<Paginated<Clip>>(key, {
    refreshInterval: (latest?: Paginated<Clip>) =>
      !isSearching && latest?.data.some((c: Clip) => c.status === "queued" || c.status === "rendering") ? 2500 : 0,
  });

  function handleSearchSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSearch(searchInput);
  }

  function clearSearch() {
    setSearchInput("");
    setSearch("");
  }

  function toggle(id: number) {
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  }

  async function handleExportZip() {
    if (selected.length === 0) return;
    setExporting(true);
    try {
      const token = useAuthStore.getState().token;
      const res = await fetch(`${apiOrigin()}/api/clips/export-zip`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          Authorization: `Bearer ${token}`,
        },
        body: JSON.stringify({ clip_ids: selected }),
      });
      if (!res.ok) throw new Error("Export failed");
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = "clips-export.zip";
      a.click();
      URL.revokeObjectURL(url);
      toast("Export ready.", "success");
      setSelected([]);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to export clips.", "danger");
    } finally {
      setExporting(false);
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">AI Clips</h1>
          <p className="mt-1 text-sm text-muted">Every clip generated across all your projects.</p>
        </div>
        <div className="flex items-center gap-2">
          <form onSubmit={handleSearchSubmit} className="flex items-center gap-1.5">
            <Input
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Search clips by content..."
              className="w-56"
            />
            <Button type="submit" size="sm" variant="secondary">
              <Search className="size-3.5" />
            </Button>
            {isSearching && (
              <Button type="button" size="sm" variant="ghost" onClick={clearSearch}>
                <X className="size-3.5" />
              </Button>
            )}
          </form>
          <Select
            value={status}
            onChange={(e) => setStatus(e.target.value)}
            className="w-auto"
            disabled={isSearching}
          >
            {STATUS_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
      </div>

      {isSearching && (
        <p className="text-xs text-muted">
          Showing semantic search results for &quot;{search}&quot; — status filter is ignored while searching.
        </p>
      )}

      {selected.length > 0 && (
        <div className="flex items-center justify-between rounded-xl border border-accent/30 bg-accent/5 px-4 py-2.5">
          <span className="text-sm">{selected.length} clip(s) selected</span>
          <div className="flex items-center gap-2">
            <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
              <X className="size-3.5" />
              Clear
            </Button>
            <Button size="sm" onClick={handleExportZip} loading={exporting}>
              <Download className="size-3.5" />
              Export as ZIP
            </Button>
          </div>
        </div>
      )}

      {isLoading || !data ? (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
          {Array.from({ length: 10 }).map((_, i) => (
            <Skeleton key={i} className="h-64" />
          ))}
        </div>
      ) : data.data.length === 0 ? (
        <EmptyState
          icon={<Scissors className="size-6" />}
          title={isSearching ? "No matching clips" : "No clips yet"}
          description={
            isSearching
              ? "Try a different search, or check back after new clips finish embedding."
              : "Generate clips from a project to see them here."
          }
        />
      ) : (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
          {data.data.map((clip) => (
            <div key={clip.id} className="relative">
              <label className="absolute left-2 top-2 z-10">
                <input
                  type="checkbox"
                  checked={selected.includes(clip.id)}
                  onChange={() => toggle(clip.id)}
                  className="size-4 rounded accent-accent"
                />
              </label>
              <ClipCard clip={clip} mutateKey={key} />
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
