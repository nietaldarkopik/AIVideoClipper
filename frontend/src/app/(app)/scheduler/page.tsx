"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { mutate } from "swr";
import { ArrowLeftRight, ArrowUpToLine, CalendarClock, CalendarDays, LayoutList, Pencil, PlayCircle, Plus, RotateCcw, Shuffle, Trash2, Zap } from "lucide-react";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Select } from "@/components/ui/Input";
import { StatusBadge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { PLATFORM_LABELS, PLATFORMS } from "@/components/social/platforms";
import { NewScheduleModal } from "@/components/scheduler/NewScheduleModal";
import { EditScheduleModal } from "@/components/scheduler/EditScheduleModal";
import { ClipPreviewModal } from "@/components/scheduler/ClipPreviewModal";
import { RescheduleAllModal } from "@/components/scheduler/RescheduleAllModal";
import { MoveChannelModal } from "@/components/scheduler/MoveChannelModal";
import { SchedulerCalendar } from "@/components/scheduler/SchedulerCalendar";
import { SchedulerWeekView } from "@/components/scheduler/SchedulerWeekView";
import type { Paginated, Project, SocialAccount, SocialPost } from "@/lib/types";

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

  const { data: projectsRes } = useApi<Paginated<Project>>("/projects?per_page=100");
  const { data: accountsRes } = useApi<{ data: SocialAccount[] }>("/social-accounts");
  const accountsByPlatform = useMemo(() => {
    const grouped: Record<string, SocialAccount[]> = {};
    for (const a of accountsRes?.data ?? []) (grouped[a.platform] ??= []).push(a);
    return grouped;
  }, [accountsRes]);

  const key = useMemo(() => {
    // Table has no inherent date range, so it's still a fixed-size fetch —
    // sorted by scheduled_at server-side now (see SocialPostController::index()),
    // so at least the soonest-upcoming posts are the ones that win the cap.
    // Week/Month ARE a bounded range, so scoping the fetch to it (once the
    // child has reported it) means those views never truncate regardless of
    // how large the account's total history grows.
    const params = new URLSearchParams({ per_page: view === "table" ? "500" : "300" });
    if (projectFilter) params.set("project_id", projectFilter);
    if (platformFilter) params.set("platform", platformFilter);
    if (accountFilter) params.set("social_account_id", accountFilter);
    if (statusFilter) params.set("status", statusFilter);
    if (view !== "table" && visibleRange) {
      params.set("scheduled_from", visibleRange.from);
      params.set("scheduled_to", visibleRange.to);
    }
    return `/social-posts?${params.toString()}`;
  }, [view, projectFilter, platformFilter, accountFilter, statusFilter, visibleRange]);

  const { data, isLoading } = useApi<Paginated<SocialPost>>(key, { refreshInterval: 20000 });
  const posts = useMemo(
    () => [...(data?.data ?? [])].sort((a, b) => (a.scheduled_at ?? "").localeCompare(b.scheduled_at ?? "")),
    [data]
  );

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

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Scheduler</h1>
          <p className="mt-1 text-sm text-muted">All scheduled clip publishes, across every project and channel.</p>
        </div>
        <div className="flex items-center gap-2">
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
            onClick={() => setView("table")}
            className={
              "flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs cursor-pointer " +
              (view === "table" ? "bg-accent text-white" : "text-muted hover:text-foreground")
            }
          >
            <LayoutList className="size-3.5" />
            Table
          </button>
          <button
            onClick={() => setView("month")}
            className={
              "flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs cursor-pointer " +
              (view === "month" ? "bg-accent text-white" : "text-muted hover:text-foreground")
            }
          >
            <CalendarClock className="size-3.5" />
            Month
          </button>
          <button
            onClick={() => setView("week")}
            className={
              "flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs cursor-pointer " +
              (view === "week" ? "bg-accent text-white" : "text-muted hover:text-foreground")
            }
          >
            <CalendarDays className="size-3.5" />
            Week
          </button>
        </div>

        <Select value={projectFilter} onChange={(e) => setProjectFilter(e.target.value)} className="w-auto">
          <option value="">All projects</option>
          {(projectsRes?.data ?? []).map((p) => (
            <option key={p.id} value={p.id}>
              {p.title}
            </option>
          ))}
        </Select>

        <Select value={platformFilter} onChange={(e) => setPlatformFilter(e.target.value)} className="w-auto">
          <option value="">All platforms</option>
          {PLATFORMS.map((p) => (
            <option key={p} value={p}>
              {PLATFORM_LABELS[p] ?? p}
            </option>
          ))}
        </Select>

        <Select value={accountFilter} onChange={(e) => setAccountFilter(e.target.value)} className="w-auto">
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

        <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="w-auto">
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
                <th className="px-4 py-3 font-medium">Channel</th>
                <th className="px-4 py-3 font-medium">Status</th>
                <th className="px-4 py-3 font-medium">Publish at</th>
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
