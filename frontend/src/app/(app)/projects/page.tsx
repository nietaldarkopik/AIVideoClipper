"use client";

import { useMemo, useState, Suspense } from "react";
import { useSearchParams } from "next/navigation";
import { Plus, FolderKanban, Search, X, Trash2, CheckSquare, Square } from "lucide-react";
import { mutate } from "swr";
import { useApi } from "@/lib/hooks";
import { api, ApiError } from "@/lib/api";
import { toast } from "@/store/toast";
import { Button } from "@/components/ui/Button";
import { Input, Select } from "@/components/ui/Input";
import { Skeleton } from "@/components/ui/Skeleton";
import { EmptyState } from "@/components/ui/EmptyState";
import { Pagination } from "@/components/ui/Pagination";
import { ProjectCard } from "@/components/projects/ProjectCard";
import { NewProjectModal } from "@/components/projects/NewProjectModal";
import type { Paginated, Project } from "@/lib/types";

const STATUS_OPTIONS = [
  { value: "", label: "All statuses" },
  { value: "draft", label: "Draft" },
  { value: "uploading", label: "Uploading" },
  { value: "processing", label: "Processing" },
  { value: "transcribing", label: "Transcribing" },
  { value: "analyzing", label: "Analyzing" },
  { value: "generating_clips", label: "Generating clips" },
  { value: "rendering", label: "Rendering" },
  { value: "completed", label: "Completed" },
  { value: "failed", label: "Failed" },
];

// One dropdown rather than a field/direction pair: a card grid has no column
// headers to click, and every useful combination is a named choice anyway.
const SORT_OPTIONS = [
  { value: "last_edited_at:desc", label: "Last edited" },
  { value: "created_at:desc", label: "Newest first" },
  { value: "created_at:asc", label: "Oldest first" },
  { value: "title:asc", label: "Title A–Z" },
  { value: "clips_count:desc", label: "Most clips" },
];

function ProjectsPageInner() {
  const searchParams = useSearchParams();
  const [modalOpen, setModalOpen] = useState(searchParams.get("new") === "1");
  const [status, setStatus] = useState("");
  const [sort, setSort] = useState(SORT_OPTIONS[0].value);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(24);
  const [selected, setSelected] = useState<number[]>([]);
  const [deleting, setDeleting] = useState(false);

  // Anything that changes WHAT is listed also resets the page AND the selection
  // — staying on page 9 of a now-shorter result just renders an empty grid, and
  // a bulk delete acting on checkboxes ticked against a previous filter would
  // silently destroy the wrong projects.
  function resetListing() {
    setPage(1);
    setSelected([]);
  }

  const key = useMemo(() => {
    const [sortBy, sortDir] = sort.split(":");
    const params = new URLSearchParams({
      per_page: String(perPage),
      page: String(page),
      sort_by: sortBy,
      sort_dir: sortDir,
    });
    if (status) params.set("status", status);
    if (search) params.set("search", search);
    return `/projects?${params.toString()}`;
  }, [status, sort, search, page, perPage]);

  const { data, isLoading } = useApi<Paginated<Project>>(key);

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

  const allOnPageSelected =
    (data?.data.length ?? 0) > 0 && (data?.data.every((p) => selected.includes(p.id)) ?? false);

  function toggleSelectAll() {
    if (!data) return;
    setSelected(allOnPageSelected ? [] : data.data.map((p) => p.id));
  }

  async function handleBulkDelete() {
    if (selected.length === 0) return;
    if (
      !confirm(
        `Delete ${selected.length} project(s)?\n\nThis also deletes their videos, clips and files on disk, and cannot be undone. Projects still processing will be skipped.`
      )
    ) {
      return;
    }
    setDeleting(true);
    try {
      const res = await api.post<{ message: string; deleted_count: number; skipped_count: number }>(
        "/projects/bulk-delete",
        { project_ids: selected }
      );
      await mutate(key);
      toast(res.message, res.skipped_count > 0 ? "default" : "success");
      setSelected([]);
    } catch (err) {
      toast(err instanceof ApiError ? err.message : "Failed to delete projects.", "danger");
    } finally {
      setDeleting(false);
    }
  }

  const isFiltered = status !== "" || search !== "";

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold">Projects</h1>
          <p className="mt-1 text-sm text-muted">Every video you&apos;ve imported, in one place.</p>
        </div>
        <Button onClick={() => setModalOpen(true)}>
          <Plus className="size-4" />
          New Project
        </Button>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <form onSubmit={handleSearchSubmit} className="flex items-center gap-1.5">
          <Input
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            placeholder="Search projects by title..."
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
          className="w-auto"
        >
          {STATUS_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
        <Select
          value={sort}
          onChange={(e) => {
            setSort(e.target.value);
            resetListing();
          }}
          className="w-auto"
        >
          {SORT_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>
              {`Sort: ${o.label}`}
            </option>
          ))}
        </Select>
      </div>

      {isLoading || !data ? (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 6 }).map((_, i) => (
            <Skeleton key={i} className="h-56" />
          ))}
        </div>
      ) : data.data.length === 0 ? (
        <EmptyState
          icon={<FolderKanban className="size-6" />}
          title={isFiltered ? "No matching projects" : "No projects yet"}
          description={
            isFiltered
              ? "Try a different search or status filter."
              : "Upload a video or paste a URL to generate your first clips."
          }
          action={
            isFiltered ? undefined : (
              <Button size="sm" onClick={() => setModalOpen(true)}>
                <Plus className="size-4" />
                New Project
              </Button>
            )
          }
        />
      ) : (
        <>
          <button
            type="button"
            onClick={toggleSelectAll}
            className="flex items-center gap-1.5 text-xs text-muted hover:text-foreground cursor-pointer"
          >
            {allOnPageSelected ? <CheckSquare className="size-3.5" /> : <Square className="size-3.5" />}
            {allOnPageSelected ? "Unselect all" : `Select all ${data.data.length} on this page`}
          </button>

          {selected.length > 0 && (
            <div className="flex items-center justify-between rounded-xl border border-accent/30 bg-accent/5 px-4 py-2.5">
              <span className="text-sm">{selected.length} project(s) selected</span>
              <div className="flex items-center gap-2">
                <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
                  <X className="size-3.5" />
                  Clear
                </Button>
                <Button size="sm" variant="danger" onClick={handleBulkDelete} loading={deleting}>
                  <Trash2 className="size-3.5" />
                  Delete
                </Button>
              </div>
            </div>
          )}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {data.data.map((project) => (
              <div key={project.id} className="relative">
                <label className="absolute left-2 top-2 z-10 cursor-pointer rounded bg-black/40 p-1">
                  <input
                    type="checkbox"
                    checked={selected.includes(project.id)}
                    onChange={() => toggle(project.id)}
                    className="size-4 rounded accent-accent cursor-pointer"
                  />
                </label>
                <ProjectCard project={project} />
              </div>
            ))}
          </div>

          {data.meta && data.meta.last_page > 0 && (
            <Pagination
              page={data.meta.current_page}
              lastPage={data.meta.last_page}
              total={data.meta.total}
              perPage={perPage}
              perPageOptions={[12, 24, 48, 96]}
              itemLabel="projects"
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

      <NewProjectModal open={modalOpen} onClose={() => setModalOpen(false)} />
    </div>
  );
}

export default function ProjectsPage() {
  return (
    <Suspense fallback={null}>
      <ProjectsPageInner />
    </Suspense>
  );
}
