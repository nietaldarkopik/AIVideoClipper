"use client";

import { Fragment, ReactNode } from "react";
import { formatDayHeading } from "@/lib/format";

/**
 * Groups a list of dated items into day sections with a heading ("Hari Ini",
 * "Kemarin", full date...).
 *
 * Exists specifically so research results read as ACCUMULATING day over day
 * rather than being replaced: without a visible date grouping, a new day's
 * research is indistinguishable from yesterday's simply being overwritten,
 * especially once items are ranked by score rather than recency. Every day's
 * ideas stay listed under their own heading — older days are never hidden or
 * dropped, only sorted below newer ones.
 */
export function GroupedByDate<T>({
  items,
  dateOf,
  keyOf,
  renderItem,
  renderGroupCount,
}: {
  items: T[];
  dateOf: (item: T) => string | null | undefined;
  keyOf: (item: T) => string | number;
  renderItem: (item: T) => ReactNode;
  /** e.g. (count) => `${count} ide` — shown next to the date heading. */
  renderGroupCount?: (count: number) => string;
}) {
  const groups: { key: string; items: T[] }[] = [];

  for (const item of items) {
    const key = dateOf(item) ?? "";
    const existing = groups.find((g) => g.key === key);
    if (existing) {
      existing.items.push(item);
    } else {
      groups.push({ key, items: [item] });
    }
  }

  return (
    <div className="space-y-6">
      {groups.map((group) => (
        <Fragment key={group.key}>
          <div className="sticky top-0 z-10 -mx-1 flex items-center gap-2 bg-background/90 px-1 py-1.5 backdrop-blur">
            <h2 className="text-sm font-semibold">{formatDayHeading(group.key)}</h2>
            {renderGroupCount && <span className="text-xs text-muted">{renderGroupCount(group.items.length)}</span>}
            <div className="h-px flex-1 bg-border-subtle" />
          </div>
          <ul className="space-y-2">
            {group.items.map((item) => (
              <li key={keyOf(item)}>{renderItem(item)}</li>
            ))}
          </ul>
        </Fragment>
      ))}
    </div>
  );
}
