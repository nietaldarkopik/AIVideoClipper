"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { mutate } from "swr";
import { ArrowDown, ArrowLeftRight, ArrowUp, ArrowUpDown, ArrowUpToLine, CalendarClock, CalendarDays, ImageUp, LayoutList, Pencil, PlayCircle, Plus, RotateCcw, Shuffle, Trash2, Zap } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Select } from "@/components/ui/Input";
import { StatusBadge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { Pagination } from "@/components/ui/Pagination";
import { PLATFORM_LABELS, PLATFORMS } from "@/components/social/platforms";
import { NewScheduleModal } from "@/components/scheduler/NewScheduleModal";
import { EditScheduleModal } from "@/components/scheduler/EditScheduleModal";
import { ClipPreviewModal } from "@/components/scheduler/ClipPreviewModal";
import { RescheduleAllModal } from "@/components/scheduler/RescheduleAllModal";
import { MoveChannelModal } from "@/components/scheduler/MoveChannelModal";
import { SchedulerCalendar } from "@/components/scheduler/SchedulerCalendar";
import { SchedulerWeekView } from "@/components/scheduler/SchedulerWeekView";
import type { Paginated, Project, SocialAccount, SocialPost } from "@/lib/types";

// Mirrors what SocialPostController::index() accepts as sort_by.
type SortKey = "scheduled_at" | "published_at" | "status" | "channel";

/**
 * A column header that sorts the table server-side. Defined at module scope
 * rather than inside the page component on purpose: the page re-renders every
 * 20s on its own SWR refresh, and a component redeclared each render remounts
 * its whole subtree — which would drop keyboard focus off a header mid-use.
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

export default function SchedulerPage() {
  const [view, setView] = useState<"table" | "month" | "week">("table");
  const [projectFilter, setProjectFilter] = useState("");
  const [platformFilter, setPlatformFilter] = useState("");
  const [accountFilter, setAccountFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [newOpen, setNewOpen] = useState(false);
  const [editingPost, setEditingPost] = useState<SocialPost | null>(null);
  const [previewPost, setPreviewPost] = useState<SocialPost | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  // Reported by SchedulerWeekView/SchedulerCalendar (see their onRangeChange)
  // so the fetch below can be scoped to exactly what's on screen — otherwise a
  // fixed-size fetch can silently omit posts that belong in the displayed
  // week/month once the account has enough total rows, which looks like
  // "items disappeared" even though nothing was actually lost.
  const [visibleRange, setVisibleRange] = useState<{ from: string; to: string } | null>(null);
  const [rescheduleAllOpen, setRescheduleAllOpen] = useState(false);
  const [moveChannelOpen, setMoveChannelOpen] = useState(false);
  // Table-only: checked rows for "Move to Channel". Empty means "use whatever
  // the current filters narrow the table down to" instead — see
  // handleOpenMoveChannel().
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  // Table-only pagination. Week/Month don't need it: their fetch is already
  // bounded by the visible date range (see visibleRange above), whereas the
  // table has no inherent range and used to just take the first 500 rows —
  // silently hiding everything past that once an account had enough history.
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  // Table-only, and applied server-side: the table pages through thousands of
  // rows, so sorting has to happen before pagination or it would only reorder
  // the 25 rows already on screen.
  const [sortBy, setSortBy] = useState<SortKey>("scheduled_at");
  const [sortDir, setSortDir] = useState<"asc" | "desc">("asc");

  const { data: projectsRes } = useApi<Paginated<Project>>("/projects?per_page=100");
  const { data: accountsRes } = useApi<{ data: SocialAccount[] }>("/social-accounts");
  const accountsByPlatform = useMemo(() => {
    const grouped: Record<string, SocialAccount[]> = {};
    for (const a of accountsRes?.data ?? []) (grouped[a.platform] ??= []).push(a);
    return grouped;
  }, [accountsRes]);

  const key = useMemo(() => {
    // Table pages through the full result set (sorted by scheduled_at
    // server-side — see SocialPostController::index()). Week/Month instead
    // fetch one bounded date range in full, so they never truncate regardless
    // of how large the account's total history grows.
    const params = new URLSearchParams(
      view === "table"
        ? { per_page: String(perPage), page: String(page), sort_by: sortBy, sort_dir: sortDir }
        : { per_page: "300" }
    );
    if (projectFilter) params.set("project_id", projectFilter);
    if (platformFilter) params.set("platform", platformFilter);
    if (accountFilter) params.set("social_account_id", accountFilter);
    if (statusFilter) params.set("status", statusFilter);
    if (view !== "table" && visibleRange) {
      params.set("scheduled_from", visibleRange.from);
      params.set("scheduled_to", visibleRange.to);
    }
    return `/social-posts?${params.toString()}`;
  }, [view, page, perPage, sortBy, sortDir, projectFilter, platformFilter, accountFilter, statusFilter, visibleRange]);

  // Called by every control that changes WHAT the table lists (filters, sort,
  // page size, view). Two things have to reset together:
  //   - the page, because staying on page 12 of a result that now has 3 pages
  //     just renders an empty table;
  //   - the selection, because every bulk action acts on the rows currently in
  //     front of you, so stale checkboxes would show a count those actions
  //     won't actually honour.
  // Done here in the handlers rather than in an effect watching the filters:
  // syncing state to state through an effect costs an extra render and trips
  // react-hooks/set-state-in-effect.
  function resetListing() {
    setPage(1);
    setSelectedIds(new Set());
  }

  const { data, isLoading } = useApi<Paginated<SocialPost>>(key, { refreshInterval: 20000 });
  const posts = useMemo(() => {
    const rows = data?.data ?? [];

    // The table's order comes from the server (see SocialPostController::index's
    // sort_by/sort_dir) — re-sorting here would silently undo it and only ever
    // reorder the current page anyway. Week/Month fetch one whole date range
    // instead of a page, so sorting those client-side is free and keeps their
    // day columns in time order.
    return view === "table"
      ? rows
      : [...rows].sort((a, b) => (a.scheduled_at ?? "").localeCompare(b.scheduled_at ?? ""));
  }, [data, view]);

  function refresh() {
    mutate(key);
  }

  async function handlePublishNow(postId: number) {
    setBusyId(postId);
    try {
      await api.post(`/social-posts/${postId}/publish-now`);
      refresh();
      toast("Publishing now.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to publish now.", "danger");
    } finally {
      setBusyId(null);
    }
  }

  async function handleRetry(postId: number) {
    setBusyId(postId);
    try {
      await api.post(`/social-posts/${postId}/retry`);
      refresh();
      toast("Retry queued.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to retry.", "danger");
    } finally {
      setBusyId(null);
    }
  }

  async function handleRetryThumbnail(postId: number) {
    setBusyId(postId);
    try {
      await api.post(`/social-posts/${postId}/retry-thumbnail`);
      refresh();
      toast("Reuploading thumbnail.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to reupload thumbnail.", "danger");
    } finally {
      setBusyId(null);
    }
  }

  async function handleCancel(postId: number) {
    if (!confirm("Cancel this scheduled post?")) return;
    setBusyId(postId);
    try {
      await api.del(`/social-posts/${postId}`);
      refresh();
      toast("Schedule cancelled.", "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to cancel.", "danger");
    } finally {
      setBusyId(null);
    }
  }

  // Recomputes scheduled_at via the same stagger/day-cap logic the auto-
  // scheduler uses, on the post's current channel — the one-click "move this
  // good clip sooner without spamming the platform" action from the table.
  async function handleRegenerate(postId: number) {
    setBusyId(postId);
    try {
      const res = await api.post<{ data: SocialPost }>(`/social-posts/${postId}/regenerate-schedule`);
      refresh();
      const when = res.data.scheduled_at
        ? new Date(res.data.scheduled_at).toLocaleString([], { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" })
        : "soon";
      toast(`Rescheduled for ${when}.`, "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to regenerate schedule.", "danger");
    } finally {
      setBusyId(null);
    }
  }

  // Drag-and-drop from the week view: sets an EXACT scheduled_at (unlike
  // Regenerate, which picks the next stagger-safe slot) — the same PATCH
  // endpoint the Edit modal uses. Optimistically moves the chip immediately so
  // the drag feels instant, then reconciles with the server; a rejected time
  // (e.g. dropped in the past) snaps back via the revalidated mutate().
  async function handleReschedule(post: SocialPost, newScheduledAtIso: string) {
    mutate(
      key,
      (current: Paginated<SocialPost> | undefined) =>
        current
          ? {
              ...current,
              data: current.data.map((p) =>
                p.id === post.id ? { ...p, scheduled_at: newScheduledAtIso, status: "scheduled" as const } : p
              ),
            }
          : current,
      false
    );
    try {
      await api.patch(`/social-posts/${post.id}`, { scheduled_at: newScheduledAtIso });
      mutate(key);
      toast("Rescheduled.", "success");
    } catch (err) {
      mutate(key);
      toast(err instanceof ApiError ? err.message : "Failed to reschedule.", "danger");
    }
  }

  // "Reschedule All" opens RescheduleAllModal (max/day, random gap range,
  // daily posting-hours window) rather than firing immediately — see that
  // component for the actual bulk-reschedule call. Only non-locked posts
  // currently in view (respecting whatever filters/date-range are active) are
  // eligible; published/in-flight ones are excluded up front so the modal's
  // "Reschedule N posts" count matches what the backend will actually touch.
  const eligibleForBulk = useMemo(
    () => posts.filter((p) => !["published", "uploading", "publishing"].includes(p.status)),
    [posts]
  );

  function handleOpenRescheduleAll() {
    if (eligibleForBulk.length === 0) {
      toast("Nothing eligible to reschedule in the current view.", "danger");
      return;
    }
    setRescheduleAllOpen(true);
  }

  // "Move to Channel" prefers whatever's checked in the table (a deliberate,
  // possibly-mixed hand-pick of posts) — falls back to the current filtered
  // view (eligibleForBulk) when nothing's checked, same "act on what's in
  // front of you" default as Reschedule All.
  const usingSelectionForMove = selectedIds.size > 0;
  const moveChannelPostIds = useMemo(
    () => (usingSelectionForMove ? eligibleForBulk.filter((p) => selectedIds.has(p.id)) : eligibleForBulk).map((p) => p.id),
    [usingSelectionForMove, eligibleForBulk, selectedIds]
  );

  function handleOpenMoveChannel() {
    if (moveChannelPostIds.length === 0) {
      toast(
        usingSelectionForMove ? "None of the selected posts are eligible to move." : "Nothing eligible to move in the current view.",
        "danger"
      );
      return;
    }
    setMoveChannelOpen(true);
  }

  function toggleSelected(id: number) {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  const allEligibleSelected = eligibleForBulk.length > 0 && eligibleForBulk.every((p) => selectedIds.has(p.id));

  function toggleSelectAll() {
    setSelectedIds(allEligibleSelected ? new Set() : new Set(eligibleForBulk.map((p) => p.id)));
  }

  // "Reschedule All" only ever touches `posts` (whatever the active
  // project/platform/channel/status filters narrowed the table/calendar down
  // to) — correct behavior (lets someone reschedule just one channel on
  // purpose), but silent about it. A leftover filter from earlier browsing
  // makes a bulk reschedule quietly single-channel, which reads as "different
  // channels never land on the same day" even though the underlying
  // stagger/day-cap algorithm treats every channel independently and happily
  // interleaves them when given the chance. Surfaced in the modal instead.
  const activeFilterLabels = useMemo(() => {
    const labels: string[] = [];
    if (projectFilter) {
      const project = (projectsRes?.data ?? []).find((p) => String(p.id) === projectFilter);
      labels.push(`Project: ${project?.title ?? projectFilter}`);
    }
    if (platformFilter) labels.push(`Platform: ${PLATFORM_LABELS[platformFilter] ?? platformFilter}`);
    if (accountFilter) {
      const account = (accountsRes?.data ?? []).find((a) => String(a.id) === accountFilter);
      labels.push(`Channel: ${account?.account_name ?? accountFilter}`);
    }
    if (statusFilter) labels.push(`Status: ${statusFilter}`);
    return labels;
  }, [projectFilter, platformFilter, accountFilter, statusFilter, projectsRes, accountsRes]);

  const [deletingBulk, setDeletingBulk] = useState(false);

  async function handleBulkDelete() {
    const idsToDelete = usingSelectionForMove ? Array.from(selectedIds) : eligibleForBulk.map((p) => p.id);
    if (idsToDelete.length === 0) {
      toast("Nothing eligible to delete.", "danger");
      return;
    }
    if (!confirm(`Are you sure you want to delete ${idsToDelete.length} scheduled post(s)?`)) return;

    setDeletingBulk(true);
    try {
      const res = await api.post<{ message: string; deleted_count: number }>("/social-posts/bulk-delete", {
        social_post_ids: idsToDelete,
      });
      setSelectedIds(new Set());
      refresh();
      toast(res.message, "success");
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to bulk delete schedules.", "danger");
    } finally {
      setDeletingBulk(false);
    }
  }

  // Clicking the active column flips its direction; a new column starts from
  // the direction that's actually useful for it — dates read newest-first,
  // names and statuses read A-Z.
  function handleSort(column: SortKey) {
    // Reordering makes the current page number meaningless (page 12 of the old
    // order has nothing to do with page 12 of the new one), so this resets too.
    resetListing();

    if (sortBy === column) {
      setSortDir(sortDir === "asc" ? "desc" : "asc");
      return;
    }
    setSortBy(column);
    setSortDir(column === "published_at" ? "desc" : "asc");
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Scheduler</h1>
          <p className="mt-1 text-sm text-muted">All scheduled clip publishes, across every project and channel.</p>
        </div>
        <div className="flex items-center gap-2">
          {(selectedIds.size > 0 || eligibleForBulk.length > 0) && (
            <Button variant="danger" onClick={handleBulkDelete} loading={deletingBulk}>
              <Trash2 className="size-4" />
              {selectedIds.size > 0 ? `Delete ${selectedIds.size}` : "Delete All Visible"}
            </Button>
          )}
          <Button variant="outline" onClick={handleOpenMoveChannel}>
            <ArrowLeftRight className="size-4" />
            {selectedIds.size > 0 ? `Move ${selectedIds.size} to Channel` : "Move to Channel"}
          </Button>
          <Button variant="outline" onClick={handleOpenRescheduleAll}>
            <Shuffle className="size-4" />
            Reschedule All
          </Button>
          <Button onClick={() => setNewOpen(true)}>
            <Plus className="size-4" />
            New Schedule
          </Button>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex items-center gap-2 rounded-lg bg-surface-elevated p-0.5 w-fit">
          <button
            onClick={() => { setView("table"); resetListing(); }}
            className={
              "flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs cursor-pointer " +
              (view === "table" ? "bg-accent text-white" : "text-muted hover:text-foreground")
            }
          >
            <LayoutList className="size-3.5" />
            Table
          </button>
          <button
            onClick={() => { setView("month"); resetListing(); }}
            className={
              "flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs cursor-pointer " +
              (view === "month" ? "bg-accent text-white" : "text-muted hover:text-foreground")
            }
          >
            <CalendarClock className="size-3.5" />
            Month
          </button>
          <button
            onClick={() => { setView("week"); resetListing(); }}
            className={
              "flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs cursor-pointer " +
              (view === "week" ? "bg-accent text-white" : "text-muted hover:text-foreground")
            }
          >
            <CalendarDays className="size-3.5" />
            Week
          </button>
        </div>

        <Select value={projectFilter} onChange={(e) => { setProjectFilter(e.target.value); resetListing(); }} className="w-auto">
          <option value="">All projects</option>
          {(projectsRes?.data ?? []).map((p) => (
            <option key={p.id} value={p.id}>
              {p.title}
            </option>
          ))}
        </Select>

        <Select value={platformFilter} onChange={(e) => { setPlatformFilter(e.target.value); resetListing(); }} className="w-auto">
          <option value="">All platforms</option>
          {PLATFORMS.map((p) => (
            <option key={p} value={p}>
              {PLATFORM_LABELS[p] ?? p}
            </option>
          ))}
        </Select>

        <Select value={accountFilter} onChange={(e) => { setAccountFilter(e.target.value); resetListing(); }} className="w-auto">
          <option value="">All specific channels</option>
          {Object.entries(accountsByPlatform).map(([platform, accounts]) => (
            <optgroup key={platform} label={PLATFORM_LABELS[platform] ?? platform}>
              {accounts.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.account_name}
                </option>
              ))}
            </optgroup>
          ))}
        </Select>

        <Select value={statusFilter} onChange={(e) => { setStatusFilter(e.target.value); resetListing(); }} className="w-auto">
          <option value="">All statuses</option>
          {["ready", "scheduled", "publishing", "published", "failed", "retrying", "cancelled"].map((s) => (
            <option key={s} value={s}>
              {s[0].toUpperCase() + s.slice(1)}
            </option>
          ))}
        </Select>
      </div>

      {isLoading || !data ? (
        <div className="space-y-3">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-16" />
          ))}
        </div>
      ) : view === "month" ? (
        // Rendered even with zero posts (unlike the table branch below) — the
        // calendar owns its own date navigation, so it needs to mount and
        // report its visible range (onRangeChange) before the fetch can even
        // be scoped to it; gating this behind "posts.length === 0" would
        // deadlock an initially-empty result and never let it self-correct.
        <Card className="p-5">
          <SchedulerCalendar posts={posts} onSelectPost={setEditingPost} onRangeChange={(from, to) => setVisibleRange({ from, to })} />
        </Card>
      ) : view === "week" ? (
        <Card className="p-5">
          <SchedulerWeekView
            posts={posts}
            onSelectPost={setEditingPost}
            onReschedule={handleReschedule}
            onRangeChange={(from, to) => setVisibleRange({ from, to })}
          />
        </Card>
      ) : posts.length === 0 ? (
        // Past page 1 an empty result means the rows moved/were deleted out from
        // under this page, not that there's nothing scheduled — offering "New
        // Schedule" there would be misleading, and without a way back the table
        // would look permanently empty.
        page > 1 ? (
          <EmptyState
            icon={<CalendarClock className="size-6" />}
            title="Nothing on this page"
            description="These posts were rescheduled or removed. Go back to the first page to see what's left."
            action={
              <Button size="sm" onClick={() => setPage(1)}>
                Back to first page
              </Button>
            }
          />
        ) : (
          <EmptyState
            icon={<CalendarClock className="size-6" />}
            title="No schedules yet"
            description="Schedule a finished clip to publish to one or more channels at a set time."
            action={
              <Button size="sm" onClick={() => setNewOpen(true)}>
                <Plus className="size-4" />
                New Schedule
              </Button>
            }
          />
        )
      ) : (
        <Card className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead>
              <tr className="border-b border-border-subtle text-xs text-muted">
                <th className="w-8 px-4 py-3">
                  <input
                    type="checkbox"
                    checked={allEligibleSelected}
                    onChange={toggleSelectAll}
                    className="size-4 rounded accent-accent cursor-pointer"
                    title="Select all eligible posts in view"
                  />
                </th>
                <th className="px-4 py-3 font-medium">Clip</th>
                <th className="px-4 py-3 font-medium">Project</th>
                {(
                  [
                    ["Channel", "channel"],
                    ["Status", "status"],
                    ["Publish at", "scheduled_at"],
                    ["Published at", "published_at"],
                  ] as const
                ).map(([label, column]) => (
                  <SortableHeader
                    key={column}
                    label={label}
                    column={column}
                    sortBy={sortBy}
                    sortDir={sortDir}
                    onSort={handleSort}
                  />
                ))}
                <th className="px-4 py-3 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border-subtle">
              {posts.map((post) => {
                const locked = ["published", "uploading", "publishing"].includes(post.status);
                return (
                <tr key={post.id} className="hover:bg-white/5">
                  <td className="px-4 py-3">
                    {!locked && (
                      <input
                        type="checkbox"
                        checked={selectedIds.has(post.id)}
                        onChange={() => toggleSelected(post.id)}
                        className="size-4 rounded accent-accent cursor-pointer"
                      />
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2.5 min-w-0">
                      <button
                        type="button"
                        onClick={() => setPreviewPost(post)}
                        title="Preview clip"
                        disabled={!post.clip?.url}
                        className="group relative size-9 shrink-0 cursor-pointer disabled:cursor-not-allowed"
                      >
                        {post.clip?.thumbnail_url ? (
                          // eslint-disable-next-line @next/next/no-img-element
                          <img src={post.clip.thumbnail_url} alt="" className="size-9 rounded-lg object-cover" />
                        ) : (
                          <div className="size-9 rounded-lg bg-surface-elevated" />
                        )}
                        {post.clip?.url && (
                          <div className="absolute inset-0 flex items-center justify-center rounded-lg bg-black/0 opacity-0 transition-all group-hover:bg-black/40 group-hover:opacity-100">
                            <PlayCircle className="size-4 text-white" />
                          </div>
                        )}
                      </button>
                      <Link href={`/clips/${post.clip_id}`} className="truncate text-sm hover:underline">
                        {post.clip?.title ?? post.title ?? `Clip #${post.clip_id}`}
                      </Link>
                    </div>
                  </td>
                  <td className="px-4 py-3 text-muted">
                    {post.clip ? (
                      <Link href={`/projects/${post.clip.project_id}`} className="hover:underline">
                        {post.clip.project_title ?? `Project #${post.clip.project_id}`}
                      </Link>
                    ) : (
                      "--"
                    )}
                  </td>
                  <td className="px-4 py-3">
                    {PLATFORM_LABELS[post.platform] ?? post.platform}
                    {post.social_account && (
                      <span className="text-muted"> — {post.social_account.account_name}</span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <StatusBadge status={post.status} />
                  </td>
                  <td className="px-4 py-3 text-muted">
                    {post.scheduled_at
                      ? new Date(post.scheduled_at).toLocaleString([], {
                          month: "short",
                          day: "numeric",
                          hour: "2-digit",
                          minute: "2-digit",
                        })
                      : "--"}
                  </td>
                  <td className="px-4 py-3 text-muted">
                    {post.published_at
                      ? new Date(post.published_at).toLocaleString([], {
                          month: "short",
                          day: "numeric",
                          hour: "2-digit",
                          minute: "2-digit",
                        })
                      : "--"}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex items-center justify-end gap-1.5">
                      {!["published", "uploading", "publishing"].includes(post.status) && (
                        <button
                          onClick={() => setEditingPost(post)}
                          title="Edit schedule"
                          className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
                        >
                          <Pencil className="size-3.5" />
                        </button>
                      )}
                      {post.status === "scheduled" && (
                        <button
                          onClick={() => handlePublishNow(post.id)}
                          disabled={busyId === post.id}
                          title="Publish now"
                          className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer disabled:opacity-50"
                        >
                          <Zap className="size-3.5" />
                        </button>
                      )}
                      {post.status === "failed" && (
                        <button
                          onClick={() => handleRetry(post.id)}
                          disabled={busyId === post.id}
                          title="Retry"
                          className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer disabled:opacity-50"
                        >
                          <RotateCcw className="size-3.5" />
                        </button>
                      )}
                      {post.platform === "youtube" && post.status === "published" && post.thumbnail_status !== "uploaded" && (
                        <button
                          onClick={() => handleRetryThumbnail(post.id)}
                          disabled={busyId === post.id}
                          title={
                            post.thumbnail_error ??
                            (post.thumbnail_status === "failed"
                              ? "Thumbnail upload failed — reupload"
                              : "Reupload thumbnail")
                          }
                          className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer disabled:opacity-50"
                        >
                          <ImageUp className="size-3.5" />
                        </button>
                      )}
                      {!["published", "uploading", "publishing"].includes(post.status) && (
                        <button
                          onClick={() => handleRegenerate(post.id)}
                          disabled={busyId === post.id}
                          title="Regenerate schedule (move to next available slot)"
                          className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer disabled:opacity-50"
                        >
                          <ArrowUpToLine className="size-3.5" />
                        </button>
                      )}
                      {!["published", "uploading", "publishing"].includes(post.status) && (
                        <button
                          onClick={() => handleCancel(post.id)}
                          disabled={busyId === post.id}
                          title="Cancel"
                          className="rounded-lg p-1.5 text-muted hover:bg-danger/10 hover:text-danger cursor-pointer disabled:opacity-50"
                        >
                          <Trash2 className="size-3.5" />
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
                );
              })}
            </tbody>
          </table>

          {data?.meta && data.meta.last_page > 0 && (
            <Pagination
              page={data.meta.current_page}
              lastPage={data.meta.last_page}
              total={data.meta.total}
              perPage={perPage}
              onPageChange={(p) => { setPage(p); setSelectedIds(new Set()); }}
              onPerPageChange={(n) => { setPerPage(n); resetListing(); }}
              itemLabel="scheduled posts"
            />
          )}
        </Card>
      )}

      <NewScheduleModal open={newOpen} onClose={() => setNewOpen(false)} onCreated={refresh} />
      <EditScheduleModal post={editingPost} onClose={() => setEditingPost(null)} onSaved={refresh} />
      <ClipPreviewModal post={previewPost} onClose={() => setPreviewPost(null)} />
      <RescheduleAllModal
        open={rescheduleAllOpen}
        postIds={eligibleForBulk.map((p) => p.id)}
        activeFilterLabels={activeFilterLabels}
        onClose={() => setRescheduleAllOpen(false)}
        onRescheduled={refresh}
      />
      <MoveChannelModal
        open={moveChannelOpen}
        postIds={moveChannelPostIds}
        usingSelection={usingSelectionForMove}
        accounts={accountsRes?.data ?? []}
        onClose={() => setMoveChannelOpen(false)}
        onMoved={() => {
          setSelectedIds(new Set());
          refresh();
        }}
      />
    </div>
  );
}
