"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import {
  Scissors,
  Download,
  X,
  Search,
  RefreshCw,
  ImageUp,
  LayoutGrid,
  LayoutList,
  ArrowUp,
  ArrowDown,
  ArrowUpDown,
  PlayCircle,
  Trash2,
  Share2,
  CheckSquare,
  Square,
  CalendarClock,
} from "lucide-react";
import { mutate } from "swr";
import { useApi } from "@/lib/hooks";
import { api, ApiError, apiOrigin } from "@/lib/api";
import { toast } from "@/store/toast";
import { useAuthStore } from "@/store/auth";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Input, Select } from "@/components/ui/Input";
import { StatusBadge, Badge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { Pagination } from "@/components/ui/Pagination";
import { ClipCard } from "@/components/clips/ClipCard";
import { ClipVideoModal } from "@/components/clips/ClipVideoModal";
import { ClipMoveChannelModal } from "@/components/clips/ClipMoveChannelModal";
import { ClipScheduleModal } from "@/components/clips/ClipScheduleModal";
import { PLATFORM_LABELS, PLATFORMS } from "@/components/social/platforms";
import { formatDuration, formatRelativeTime } from "@/lib/format";
import type { Clip, Paginated, Project, SocialAccount } from "@/lib/types";

const STATUS_OPTIONS = [
  { value: "", label: "All statuses" },
  { value: "completed", label: "Completed" },
  { value: "rendering", label: "Rendering" },
  { value: "queued", label: "Queued" },
  { value: "failed", label: "Failed" },
];

const PUBLISHED_OPTIONS = [
  { value: "", label: "All clips" },
  { value: "1", label: "Published" },
  { value: "0", label: "Not published" },
  // Stricter than "Not published": zero SocialPost rows of any status — never
  // even assigned a destination account, vs. merely scheduled/failed.
  { value: "unlinked", label: "Not linked to any account" },
];

const ASPECT_RATIO_OPTIONS = [
  { value: "", label: "All aspect ratios" },
  { value: "9:16", label: "9:16 — Vertical" },
  { value: "1:1", label: "1:1 — Square" },
  { value: "16:9", label: "16:9 — Landscape" },
];

// Mirrors what ClipController::index() accepts as sort_by.
type SortKey = "created_at" | "title" | "status" | "duration" | "rendered_at";

/**
 * A column header that sorts the table server-side. Module scope, not inside
 * the page component — see the identical note on scheduler/page.tsx's own
 * SortableHeader: a component redeclared every render remounts its subtree
 * and drops focus mid-interaction.
 */
function SortableHeader({
  label,
  column,
  sortBy,
  sortDir,
  onSort,
}: {
  label: string;
  column: SortKey;
  sortBy: SortKey;
  sortDir: "asc" | "desc";
  onSort: (column: SortKey) => void;
}) {
  const active = sortBy === column;

  return (
    <th className="px-4 py-3 font-medium">
      <button
        type="button"
        onClick={() => onSort(column)}
        className={
          "group flex cursor-pointer items-center gap-1 " + (active ? "text-foreground" : "hover:text-foreground")
        }
      >
        {label}
        {active ? (
          sortDir === "asc" ? <ArrowUp className="size-3" /> : <ArrowDown className="size-3" />
        ) : (
          <ArrowUpDown className="size-3 opacity-0 transition-opacity group-hover:opacity-60" />
        )}
      </button>
    </th>
  );
}

export default function AllClipsPage() {
  const [view, setView] = useState<"grid" | "table">("grid");
  const [status, setStatus] = useState("");
  const [published, setPublished] = useState("");
  const [platform, setPlatform] = useState("");
  const [socialAccountId, setSocialAccountId] = useState("");
  const [scheduledPlatform, setScheduledPlatform] = useState("");
  const [scheduledSocialAccountId, setScheduledSocialAccountId] = useState("");
  const [scheduledFrom, setScheduledFrom] = useState("");
  const [scheduledTo, setScheduledTo] = useState("");
  const [sourceChannel, setSourceChannel] = useState("");
  const [aspectRatio, setAspectRatio] = useState("");
  const [projectFilter, setProjectFilter] = useState("");
  const [selected, setSelected] = useState<number[]>([]);
  const [moveChannelOpen, setMoveChannelOpen] = useState(false);
  const [scheduleOpen, setScheduleOpen] = useState(false);
  const [previewClip, setPreviewClip] = useState<Clip | null>(null);
  const [exporting, setExporting] = useState(false);
  const [regenerating, setRegenerating] = useState(false);
  const [regeneratingCovers, setRegeneratingCovers] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");

  // Server-side sort is table-only (a card grid has no sortable columns to
  // click), but both views are now fully paginated — each keeps its own
  // page/perPage so switching views (or the browser back button) doesn't leave
  // one view's page number applied to the other's, very different, row counts.
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [gridPage, setGridPage] = useState(1);
  const [gridPerPage, setGridPerPage] = useState(60);
  const [sortBy, setSortBy] = useState<SortKey>("created_at");
  const [sortDir, setSortDir] = useState<"asc" | "desc">("desc");

  const { data: projectsRes } = useApi<Paginated<Project>>("/projects?per_page=100");
  const { data: accountsRes } = useApi<{ data: SocialAccount[] }>("/social-accounts");
  const { data: sourceChannelsRes } = useApi<{ data: string[] }>("/clips/source-channels");

  // Accounts for the currently selected platform only — this is what the
  // second ("which channel/page") dropdown offers. Cleared whenever platform
  // changes so a stale account id from a different platform can't linger.
  const accountsForPlatform = (accountsRes?.data ?? []).filter((a) => a.platform === platform);
  const accountsForScheduledPlatform = (accountsRes?.data ?? []).filter((a) => a.platform === scheduledPlatform);

  const isSearching = search.trim() !== "";

  const key = useMemo(() => {
    if (isSearching) return `/clips/search?q=${encodeURIComponent(search.trim())}`;

    const params = new URLSearchParams(
      view === "table"
        ? { per_page: String(perPage), page: String(page), sort_by: sortBy, sort_dir: sortDir }
        : { per_page: String(gridPerPage), page: String(gridPage) }
    );
    if (status) params.set("status", status);
    if (published) params.set("published", published);
    if (platform) params.set("platform", platform);
    if (socialAccountId) params.set("social_account_id", socialAccountId);
    if (scheduledPlatform) params.set("scheduled_platform", scheduledPlatform);
    if (scheduledSocialAccountId) params.set("scheduled_social_account_id", scheduledSocialAccountId);
    if (scheduledFrom) params.set("scheduled_from", scheduledFrom);
    if (scheduledTo) params.set("scheduled_to", scheduledTo);
    if (sourceChannel) params.set("source_channel", sourceChannel);
    if (aspectRatio) params.set("aspect_ratio", aspectRatio);
    if (projectFilter) params.set("project_id", projectFilter);
    return `/clips?${params.toString()}`;
  }, [
    isSearching,
    search,
    view,
    page,
    perPage,
    gridPage,
    gridPerPage,
    sortBy,
    sortDir,
    status,
    published,
    platform,
    socialAccountId,
    scheduledPlatform,
    scheduledSocialAccountId,
    scheduledFrom,
    scheduledTo,
    sourceChannel,
    aspectRatio,
    projectFilter,
  ]);

  const { data, isLoading } = useApi<Paginated<Clip>>(key, {
    refreshInterval: (latest?: Paginated<Clip>) =>
      !isSearching && latest?.data.some((c: Clip) => c.status === "queued" || c.status === "rendering") ? 2500 : 0,
  });

  // Every control that changes WHAT the table lists (filter, sort, view, page
  // size) resets both the page and the selection — staying on page 12 of a
  // now-smaller result renders empty, and a bulk action acting on stale
  // checkboxes would silently touch the wrong rows. Done inline in each
  // handler rather than an effect watching the filters (an effect just for
  // syncing state to state costs an extra render and trips
  // react-hooks/set-state-in-effect).
  function resetListing() {
    setPage(1);
    setGridPage(1);
    setSelected([]);
  }

  function handleSort(column: SortKey) {
    resetListing();
    if (sortBy === column) {
      setSortDir(sortDir === "asc" ? "desc" : "asc");
      return;
    }
    setSortBy(column);
    setSortDir(column === "title" ? "asc" : "desc");
  }

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

  const allOnPageSelected = (data?.data.length ?? 0) > 0 && (data?.data.every((c) => selected.includes(c.id)) ?? false);

  function toggleSelectAll() {
    if (!data) return;
    setSelected(allOnPageSelected ? [] : data.data.map((c) => c.id));
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

  async function handleBulkRegenerate() {
    if (selected.length === 0) return;
    if (!confirm(`Re-render ${selected.length} clip(s)? This replaces their current rendered output.`)) return;
    setRegenerating(true);
    try {
      const res = await api.post<{ message: string; regenerated_count: number; skipped_count: number }>(
        "/clips/bulk-regenerate",
        { clip_ids: selected }
      );
      await mutate(key);
      toast(res.message, "success");
      setSelected([]);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to regenerate clips.", "danger");
    } finally {
      setRegenerating(false);
    }
  }

  async function handleBulkRegenerateCovers() {
    if (selected.length === 0) return;
    setRegeneratingCovers(true);
    try {
      const res = await api.post<{ message: string; regenerated_count: number; skipped_count: number }>(
        "/clips/bulk-generate-cover",
        { clip_ids: selected }
      );
      await mutate(key);
      toast(res.message, "success");
      setSelected([]);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to regenerate covers.", "danger");
    } finally {
      setRegeneratingCovers(false);
    }
  }

  async function handleBulkDelete() {
    if (selected.length === 0) return;
    if (!confirm(`Delete ${selected.length} clip(s)? This cannot be undone.`)) return;
    setDeleting(true);
    try {
      const res = await api.post<{ message: string; deleted_count: number }>("/clips/bulk-delete", {
        clip_ids: selected,
      });
      await mutate(key);
      toast(res.message, "success");
      setSelected([]);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to delete clips.", "danger");
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">AI Clips</h1>
          <p className="mt-1 text-sm text-muted">Every clip generated across all your projects.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
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
            value={projectFilter}
            onChange={(e) => {
              setProjectFilter(e.target.value);
              resetListing();
            }}
            className="w-auto"
            disabled={isSearching}
          >
            <option value="">All projects</option>
            {(projectsRes?.data ?? []).map((p) => (
              <option key={p.id} value={p.id}>
                {p.title}
              </option>
            ))}
          </Select>
          <Select
            value={platform}
            onChange={(e) => {
              setPlatform(e.target.value);
              setSocialAccountId("");
              resetListing();
            }}
            className="w-auto"
            disabled={isSearching}
            title="Filter clips by which platform they were published to"
          >
            <option value="">Published to: All</option>
            {PLATFORMS.map((p) => (
              <option key={p} value={p}>
                {`Published to: ${PLATFORM_LABELS[p] ?? p}`}
              </option>
            ))}
          </Select>
          {platform && (
            <Select
              value={socialAccountId}
              onChange={(e) => {
                setSocialAccountId(e.target.value);
                resetListing();
              }}
              className="w-auto"
              disabled={isSearching}
              title={`Narrow down to one connected ${PLATFORM_LABELS[platform] ?? platform} account`}
            >
              <option value="">
                {accountsForPlatform.length === 0 ? "No accounts connected" : "All accounts"}
              </option>
              {accountsForPlatform.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.account_name}
                </option>
              ))}
            </Select>
          )}
          <Select
            value={scheduledPlatform}
            onChange={(e) => {
              setScheduledPlatform(e.target.value);
              setScheduledSocialAccountId("");
              resetListing();
            }}
            className="w-auto"
            disabled={isSearching}
            title="Filter clips by which platform they are scheduled to be published to next"
          >
            <option value="">Scheduled to: All</option>
            {PLATFORMS.map((p) => (
              <option key={p} value={p}>
                {`Scheduled to: ${PLATFORM_LABELS[p] ?? p}`}
              </option>
            ))}
          </Select>
          {scheduledPlatform && (
            <Select
              value={scheduledSocialAccountId}
              onChange={(e) => {
                setScheduledSocialAccountId(e.target.value);
                resetListing();
              }}
              className="w-auto"
              disabled={isSearching}
              title={`Narrow down to one connected ${PLATFORM_LABELS[scheduledPlatform] ?? scheduledPlatform} account`}
            >
              <option value="">
                {accountsForScheduledPlatform.length === 0 ? "No accounts connected" : "All accounts"}
              </option>
              {accountsForScheduledPlatform.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.account_name}
                </option>
              ))}
            </Select>
          )}
          <div className="flex items-center gap-1.5" title="Filter clips by their scheduled publish date">
            <Input
              type="date"
              value={scheduledFrom}
              onChange={(e) => {
                setScheduledFrom(e.target.value);
                resetListing();
              }}
              disabled={isSearching}
              className="w-auto"
            />
            <span className="text-xs text-muted">to</span>
            <Input
              type="date"
              value={scheduledTo}
              onChange={(e) => {
                setScheduledTo(e.target.value);
                resetListing();
              }}
              disabled={isSearching}
              className="w-auto"
            />
            {(scheduledFrom || scheduledTo) && (
              <Button
                type="button"
                size="sm"
                variant="ghost"
                onClick={() => {
                  setScheduledFrom("");
                  setScheduledTo("");
                  resetListing();
                }}
              >
                <X className="size-3.5" />
              </Button>
            )}
          </div>
          <Select
            value={sourceChannel}
            onChange={(e) => {
              setSourceChannel(e.target.value);
              resetListing();
            }}
            className="w-auto"
            disabled={isSearching}
            title="Filter clips by the source video's channel/uploader"
          >
            <option value="">Source channel: All</option>
            {(sourceChannelsRes?.data ?? []).map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </Select>
          <Select
            value={aspectRatio}
            onChange={(e) => {
              setAspectRatio(e.target.value);
              resetListing();
            }}
            className="w-auto"
            disabled={isSearching}
          >
            {ASPECT_RATIO_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <Select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              resetListing();
            }}
            className="w-auto"
            disabled={isSearching}
          >
            {STATUS_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <Select
            value={published}
            onChange={(e) => {
              setPublished(e.target.value);
              resetListing();
            }}
            className="w-auto"
            disabled={isSearching}
          >
            {PUBLISHED_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
          <div className="flex items-center rounded-lg border border-border-subtle p-0.5">
            <button
              type="button"
              onClick={() => {
                setView("grid");
                resetListing();
              }}
              title="Grid view"
              className={`rounded-md p-1.5 cursor-pointer ${view === "grid" ? "bg-accent text-accent-foreground" : "text-muted hover:text-foreground"}`}
            >
              <LayoutGrid className="size-3.5" />
            </button>
            <button
              type="button"
              onClick={() => {
                setView("table");
                resetListing();
              }}
              title="Table view"
              className={`rounded-md p-1.5 cursor-pointer ${view === "table" ? "bg-accent text-accent-foreground" : "text-muted hover:text-foreground"}`}
            >
              <LayoutList className="size-3.5" />
            </button>
          </div>
        </div>
      </div>

      {isSearching && (
        <p className="text-xs text-muted">
          Showing semantic search results for &quot;{search}&quot; — other filters are ignored while searching.
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
            <Button size="sm" variant="outline" onClick={handleBulkRegenerateCovers} loading={regeneratingCovers}>
              <ImageUp className="size-3.5" />
              Regenerate Covers
            </Button>
            <Button size="sm" variant="outline" onClick={handleBulkRegenerate} loading={regenerating}>
              <RefreshCw className="size-3.5" />
              Regenerate Clips
            </Button>
            <Button size="sm" variant="outline" onClick={() => setMoveChannelOpen(true)}>
              <Share2 className="size-3.5" />
              Move to Channel
            </Button>
            <Button size="sm" variant="outline" onClick={() => setScheduleOpen(true)}>
              <CalendarClock className="size-3.5" />
              Set Schedule
            </Button>
            <Button size="sm" onClick={handleExportZip} loading={exporting}>
              <Download className="size-3.5" />
              Export as ZIP
            </Button>
            <Button size="sm" variant="danger" onClick={handleBulkDelete} loading={deleting}>
              <Trash2 className="size-3.5" />
              Delete
            </Button>
          </div>
        </div>
      )}

      {isLoading || !data ? (
        view === "table" ? (
          <Skeleton className="h-96" />
        ) : (
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            {Array.from({ length: 10 }).map((_, i) => (
              <Skeleton key={i} className="h-64" />
            ))}
          </div>
        )
      ) : data.data.length === 0 ? (
        <EmptyState
          icon={<Scissors className="size-6" />}
          title={isSearching ? "No matching clips" : "No clips yet"}
          description={
            isSearching
              ? "Try a different search, or check back after new clips finish embedding."
              : "Generate clips from a project to see them here, or adjust the filters above."
          }
        />
      ) : view === "grid" ? (
        <>
          <button
            type="button"
            onClick={toggleSelectAll}
            className="mb-3 flex items-center gap-1.5 text-xs text-muted hover:text-foreground cursor-pointer"
          >
            {allOnPageSelected ? <CheckSquare className="size-3.5" /> : <Square className="size-3.5" />}
            {allOnPageSelected ? "Unselect all" : `Select all ${data.data.length} clips on this page`}
          </button>
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

          {data.meta && data.meta.last_page > 0 && (
            <Pagination
              page={data.meta.current_page}
              lastPage={data.meta.last_page}
              total={data.meta.total}
              perPage={gridPerPage}
              perPageOptions={[24, 60, 120, 240]}
              itemLabel="clips"
              onPageChange={(p) => {
                setGridPage(p);
                setSelected([]);
              }}
              onPerPageChange={(n) => {
                setGridPerPage(n);
                resetListing();
              }}
            />
          )}
        </>
      ) : (
        <Card className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b border-border-subtle text-xs text-muted">
                <th className="w-8 px-4 py-3">
                  <input
                    type="checkbox"
                    checked={allOnPageSelected}
                    onChange={toggleSelectAll}
                    className="size-4 rounded accent-accent cursor-pointer"
                    title="Select all clips on this page"
                  />
                </th>
                <SortableHeader label="Clip" column="title" sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
                <th className="px-4 py-3 font-medium">Project</th>
                <th className="px-4 py-3 font-medium">Template</th>
                <th className="px-4 py-3 font-medium">Aspect Ratio</th>
                <SortableHeader label="Status" column="status" sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
                <SortableHeader label="Duration" column="duration" sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
                <SortableHeader label="Rendered" column="rendered_at" sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
                <SortableHeader label="Created" column="created_at" sortBy={sortBy} sortDir={sortDir} onSort={handleSort} />
              </tr>
            </thead>
            <tbody className="divide-y divide-border-subtle">
              {data.data.map((clip) => (
                <tr key={clip.id} className="hover:bg-white/5">
                  <td className="px-4 py-3">
                    <input
                      type="checkbox"
                      checked={selected.includes(clip.id)}
                      onChange={() => toggle(clip.id)}
                      className="size-4 rounded accent-accent cursor-pointer"
                    />
                  </td>
                  <td className="px-4 py-3">
                    {/* Thumbnail plays the video; the title text is the separate
                        link to the edit page — two distinct affordances rather
                        than one ambiguous click target. */}
                    <div className="flex items-center gap-2.5 min-w-0">
                      <button
                        type="button"
                        onClick={() => setPreviewClip(clip)}
                        disabled={!clip.url}
                        title={clip.url ? "Play video" : undefined}
                        className="group relative size-9 shrink-0 cursor-pointer disabled:cursor-not-allowed"
                      >
                        {clip.thumbnail_url ? (
                          // eslint-disable-next-line @next/next/no-img-element
                          <img src={clip.thumbnail_url} alt="" className="size-9 rounded-lg object-cover" />
                        ) : (
                          <div className="flex size-9 items-center justify-center rounded-lg bg-surface-elevated">
                            <Scissors className="size-3.5 text-muted" />
                          </div>
                        )}
                        {clip.url && (
                          <div className="absolute inset-0 flex items-center justify-center rounded-lg bg-black/0 opacity-0 transition-all group-hover:bg-black/40 group-hover:opacity-100">
                            <PlayCircle className="size-4 text-white" />
                          </div>
                        )}
                      </button>
                      <Link href={`/clips/${clip.id}`} className="truncate text-sm hover:underline">
                        {clip.title ?? `Clip #${clip.id}`}
                      </Link>
                    </div>
                  </td>
                  <td className="px-4 py-3 text-muted">
                    {clip.project_title ? (
                      <Link href={`/projects/${clip.project_id}`} className="hover:underline">
                        {clip.project_title}
                      </Link>
                    ) : (
                      `Project #${clip.project_id}`
                    )}
                  </td>
                  <td className="px-4 py-3 text-muted">{clip.template?.name ?? "—"}</td>
                  <td className="px-4 py-3 text-muted">{clip.aspect_ratio}</td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-1.5">
                      <StatusBadge status={clip.status} />
                      {clip.is_published && <Badge tone="success">Published</Badge>}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-muted">{formatDuration(clip.duration)}</td>
                  <td className="px-4 py-3 text-muted">{clip.rendered_at ? formatRelativeTime(clip.rendered_at) : "—"}</td>
                  <td className="px-4 py-3 text-muted">{formatRelativeTime(clip.created_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>

          {data.meta && data.meta.last_page > 0 && (
            <Pagination
              page={data.meta.current_page}
              lastPage={data.meta.last_page}
              total={data.meta.total}
              perPage={perPage}
              onPageChange={(p) => {
                setPage(p);
                setSelected([]);
              }}
              onPerPageChange={(n) => {
                setPerPage(n);
                resetListing();
              }}
              itemLabel="clips"
            />
          )}
        </Card>
      )}

      <ClipVideoModal clip={previewClip} onClose={() => setPreviewClip(null)} />
      <ClipMoveChannelModal
        open={moveChannelOpen}
        clipIds={selected}
        accounts={accountsRes?.data ?? []}
        onClose={() => setMoveChannelOpen(false)}
        onMoved={() => {
          setSelected([]);
          mutate(key);
        }}
      />
      <ClipScheduleModal
        open={scheduleOpen}
        clipIds={selected}
        accounts={accountsRes?.data ?? []}
        onClose={() => setScheduleOpen(false)}
        onScheduled={() => {
          setSelected([]);
          mutate(key);
        }}
      />
    </div>
  );
}
