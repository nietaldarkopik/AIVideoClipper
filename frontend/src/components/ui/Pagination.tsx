"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import { Select } from "@/components/ui/Input";

/**
 * Page navigation + per-page picker for any endpoint that returns Laravel's
 * paginator meta (see Paginated<T>). Renders a compact window of page numbers
 * around the current one rather than every page, so a list hundreds of pages
 * long stays usable.
 */
export function Pagination({
  page,
  lastPage,
  total,
  perPage,
  perPageOptions = [25, 50, 100, 200],
  onPageChange,
  onPerPageChange,
  itemLabel = "items",
}: {
  page: number;
  lastPage: number;
  total: number;
  perPage: number;
  perPageOptions?: number[];
  onPageChange: (page: number) => void;
  onPerPageChange: (perPage: number) => void;
  itemLabel?: string;
}) {
  const from = total === 0 ? 0 : (page - 1) * perPage + 1;
  const to = Math.min(page * perPage, total);

  // A window of at most 5 page buttons centred on the current page, clamped to
  // the real range at both ends.
  const windowSize = 5;
  let start = Math.max(1, page - Math.floor(windowSize / 2));
  const end = Math.min(lastPage, start + windowSize - 1);
  start = Math.max(1, end - windowSize + 1);
  const pages = Array.from({ length: end - start + 1 }, (_, i) => start + i);

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle px-4 py-3 text-xs">
      <div className="flex items-center gap-2 text-muted">
        <span>
          Showing <span className="text-foreground">{from}</span>–<span className="text-foreground">{to}</span> of{" "}
          <span className="text-foreground">{total}</span> {itemLabel}
        </span>
      </div>

      <div className="flex items-center gap-3">
        <label className="flex items-center gap-1.5 text-muted">
          Per page
          <Select
            value={perPage}
            onChange={(e) => onPerPageChange(Number(e.target.value))}
            className="w-auto py-1 text-xs"
          >
            {perPageOptions.map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </Select>
        </label>

        <div className="flex items-center gap-1">
          <button
            type="button"
            onClick={() => onPageChange(page - 1)}
            disabled={page <= 1}
            className="rounded-lg p-1.5 text-muted enabled:hover:bg-white/5 enabled:hover:text-foreground disabled:opacity-40 enabled:cursor-pointer"
            title="Previous page"
          >
            <ChevronLeft className="size-4" />
          </button>

          {start > 1 && <span className="px-1 text-muted">…</span>}
          {pages.map((p) => (
            <button
              key={p}
              type="button"
              onClick={() => onPageChange(p)}
              className={
                "min-w-7 rounded-lg px-2 py-1 cursor-pointer " +
                (p === page ? "bg-accent text-white" : "text-muted hover:bg-white/5 hover:text-foreground")
              }
            >
              {p}
            </button>
          ))}
          {end < lastPage && <span className="px-1 text-muted">…</span>}

          <button
            type="button"
            onClick={() => onPageChange(page + 1)}
            disabled={page >= lastPage}
            className="rounded-lg p-1.5 text-muted enabled:hover:bg-white/5 enabled:hover:text-foreground disabled:opacity-40 enabled:cursor-pointer"
            title="Next page"
          >
            <ChevronRight className="size-4" />
          </button>
        </div>
      </div>
    </div>
  );
}
