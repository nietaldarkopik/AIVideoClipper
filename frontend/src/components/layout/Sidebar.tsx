"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { clsx } from "clsx";
import {
  LayoutGrid,
  FolderKanban,
  LayoutTemplate,
  Image as ImageIcon,
  TrendingUp,
  Scissors,
  Share2,
  CalendarClock,
  Settings,
  CreditCard,
  ShieldCheck,
  Film,
  Bot,
  Rss,
  Lightbulb,
  Radar,
} from "lucide-react";
import { useAuthStore } from "@/store/auth";
import { LogOut } from "lucide-react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";

const primaryNav = [
  { href: "/dashboard", label: "Dashboard", icon: LayoutGrid },
  { href: "/projects", label: "Projects", icon: FolderKanban },
  { href: "/batches", label: "Batch Autobot", icon: Bot },
  { href: "/channels", label: "Channels", icon: Rss },
  { href: "/templates", label: "Templates", icon: LayoutTemplate },
  { href: "/cover-templates", label: "Cover Templates", icon: ImageIcon },
  { href: "/trending", label: "Trending", icon: TrendingUp },
  { href: "/content-briefs", label: "Riset Konten", icon: Lightbulb },
  // Content Research Engine. Distinct from "Channels" above (watched YouTube
  // upload feeds) — these are brand/persona channels the engine researches for.
  { href: "/research", label: "Content Research", icon: Radar },
  { href: "/clips", label: "AI Clips", icon: Scissors },
  { href: "/social-accounts", label: "Social Accounts", icon: Share2 },
  { href: "/scheduler", label: "Scheduler", icon: CalendarClock },
];

const secondaryNav = [
  { href: "/settings", label: "Settings", icon: Settings },
  { href: "/billing", label: "Billing", icon: CreditCard },
];

export function Sidebar() {
  const pathname = usePathname();
  const user = useAuthStore((s) => s.user);
  const logout = useAuthStore((s) => s.logout);
  const router = useRouter();

  async function handleLogout() {
    try {
      await api.post("/auth/logout");
    } catch {
      // ignore — we're logging out client-side regardless
    }
    logout();
    router.push("/login");
  }

  return (
    <aside className="hidden w-60 shrink-0 flex-col border-r border-border-subtle bg-surface md:flex">
      <div className="flex items-center gap-2 px-5 py-5">
        <div className="flex size-8 items-center justify-center rounded-lg bg-gradient-to-br from-accent to-accent-2">
          <Film className="size-4 text-white" />
        </div>
        <span className="text-sm font-semibold">AI Video Clipper</span>
      </div>

      <nav className="flex-1 space-y-0.5 px-3">
        {primaryNav.map((item) => {
          const active = pathname === item.href || pathname.startsWith(item.href + "/");
          return (
            <Link
              key={item.href}
              href={item.href}
              className={clsx(
                "flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors",
                active
                  ? "bg-accent/15 text-accent-2"
                  : "text-muted hover:bg-white/5 hover:text-foreground"
              )}
            >
              <item.icon className="size-4" />
              {item.label}
            </Link>
          );
        })}

        {user?.role === "admin" && (
          <Link
            href="/admin"
            className={clsx(
              "flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors",
              pathname.startsWith("/admin")
                ? "bg-accent/15 text-accent-2"
                : "text-muted hover:bg-white/5 hover:text-foreground"
            )}
          >
            <ShieldCheck className="size-4" />
            Admin
          </Link>
        )}

        <div className="my-3 border-t border-border-subtle" />

        {secondaryNav.map((item) => {
          const active = pathname === item.href;
          return (
            <Link
              key={item.href}
              href={item.href}
              className={clsx(
                "flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors",
                active
                  ? "bg-accent/15 text-accent-2"
                  : "text-muted hover:bg-white/5 hover:text-foreground"
              )}
            >
              <item.icon className="size-4" />
              {item.label}
            </Link>
          );
        })}
      </nav>

      {user && (
        <div className="border-t border-border-subtle p-4">
          <div className="flex items-center gap-2.5">
            <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-surface-elevated text-xs font-semibold">
              {user.name.slice(0, 2).toUpperCase()}
            </div>
            <div className="min-w-0 flex-1">
              <p className="truncate text-xs font-medium">{user.name}</p>
              <p className="truncate text-[11px] text-muted">{user.email}</p>
            </div>
            <button
              onClick={handleLogout}
              title="Log out"
              className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
            >
              <LogOut className="size-4" />
            </button>
          </div>
        </div>
      )}
    </aside>
  );
}
