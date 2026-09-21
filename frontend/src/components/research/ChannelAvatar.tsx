import { clsx } from "clsx";
import type { ContentChannel } from "@/lib/types";

/**
 * A content channel's logo, falling back to its initials.
 *
 * A plain <img> rather than next/image: these are remote platform CDN URLs
 * (yt3.ggpht.com and friends) that would each need a next.config remote pattern
 * entry, and the optimiser buys nothing for an image already served at the exact
 * size it's displayed.
 */
export function ChannelAvatar({
  channel,
  className,
}: {
  channel: Pick<ContentChannel, "name" | "avatar_url">;
  className?: string;
}) {
  const base = clsx("shrink-0 overflow-hidden rounded-full bg-surface-elevated", className);

  if (!channel.avatar_url) {
    return (
      <div className={clsx(base, "flex items-center justify-center text-xs font-semibold text-muted")}>
        {channel.name.slice(0, 2).toUpperCase()}
      </div>
    );
  }

  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img src={channel.avatar_url} alt="" className={clsx(base, "object-cover")} />
  );
}
