"use client";

import { useEffect, useState } from "react";
import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
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
  Bot,
  Rss,
  Lightbulb,
  Radar,
  BarChart3,
  Menu,
  X,
  LogOut,
} from "lucide-react";
import { useAuthStore } from "@/store/auth";
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
  // Sits right after Scheduler on purpose: it's the other end of the same
  // pipeline — Scheduler is what goes out, Analytics is what came back.
  { href: "/analytics", label: "Analytics", icon: BarChart3 },
];

const secondaryNav = [
  { href: "/settings", label: "Settings", icon: Settings },
  { href: "/billing", label: "Billing", icon: CreditCard },
];

const linkClass = (active: boolean) =>
  clsx(
    "flex items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors",
    active ? "bg-accent/15 text-accent-2" : "text-muted hover:bg-white/5 hover:text-foreground"
  );

/**
 * The nav itself, shared by the desktop sidebar and the mobile drawer so the two
 * can't drift apart as pages get added.
 *
 * onNavigate lets the drawer close itself on a link tap: Next's client-side
 * navigation doesn't remount the layout, so without it the drawer would stay open
 * on top of the page the user just asked for.
 */
function NavLinks({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();
  const user = useAuthStore((s) => s.user);

  return (
    <nav className="flex-1 space-y-0.5 overflow-y-auto px-3">
      {primaryNav.map((item) => {
        const active = pathname === item.href || pathname.startsWith(item.href + "/");
        return (
          <Link
            key={item.href}
            href={item.href}
            onClick={onNavigate}
            aria-current={active ? "page" : undefined}
            className={linkClass(active)}
          >
            <item.icon className="size-4" />
            {item.label}
          </Link>
        );
      })}

      {user?.role === "admin" && (
        <Link
          href="/admin"
          onClick={onNavigate}
          aria-current={pathname.startsWith("/admin") ? "page" : undefined}
          className={linkClass(pathname.startsWith("/admin"))}
        >
          <ShieldCheck className="size-4" />
          Admin
        </Link>
      )}

      <div className="my-3 border-t border-border-subtle" />

      {secondaryNav.map((item) => (
        <Link
          key={item.href}
          href={item.href}
          onClick={onNavigate}
          aria-current={pathname === item.href ? "page" : undefined}
          className={linkClass(pathname === item.href)}
        >
          <item.icon className="size-4" />
          {item.label}
        </Link>
      ))}
    </nav>
  );
}

function BrandMark() {
  return (
    <div className="flex items-center gap-2">
      <Image src="/logo.png" alt="Existcode" width={32} height={32} className="size-8 rounded-lg" />
      <span className="text-sm font-semibold">Existcode</span>
    </div>
  );
}

function UserFooter() {
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

  if (!user) return null;

  return (
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
          aria-label="Log out"
          className="rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground cursor-pointer"
        >
          <LogOut className="size-4" />
        </button>
      </div>
    </div>
  );
}

export function Sidebar() {
  return (
    <aside className="hidden w-60 shrink-0 flex-col border-r border-border-subtle bg-surface md:flex">
      <div className="px-5 py-5">
        <BrandMark />
      </div>
      <NavLinks />
      <UserFooter />
    </aside>
  );
}

/**
 * Below the `md` breakpoint the sidebar above is hidden, and until this existed
 * nothing replaced it — the app rendered fine on a phone but had no way to reach
 * any other page. This is a top bar plus a slide-in drawer holding the same nav.
 */
export function MobileNav() {
  const [open, setOpen] = useState(false);
  const pathname = usePathname();

  // Escape closes, matching the app's Modal. Background scroll is locked while
  // open: without it the page behind scrolls under the user's finger while the
  // drawer stays put, which reads as a broken overlay.
  useEffect(() => {
    if (!open) return;

    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") setOpen(false);
    }
    document.addEventListener("keydown", onKey);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    return () => {
      document.removeEventListener("keydown", onKey);
      document.body.style.overflow = previousOverflow;
    };
  }, [open]);

  // Belt-and-braces alongside the per-link onNavigate below: the route can also
  // change from the drawer's own footer (log out) or a browser back gesture, and
  // neither of those runs a link handler. Adjusted during render rather than in
  // an effect — React's documented pattern for resetting state when a value
  // changes, and it avoids the extra committed frame where the drawer would still
  // be painted over the new page.
  const [lastPathname, setLastPathname] = useState(pathname);
  if (pathname !== lastPathname) {
    setLastPathname(pathname);
    setOpen(false);
  }

  return (
    <>
      <header className="flex items-center gap-3 border-b border-border-subtle bg-surface px-4 py-3 md:hidden">
        <button
          onClick={() => setOpen(true)}
          aria-label="Open navigation menu"
          aria-expanded={open}
          className="cursor-pointer rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground"
        >
          <Menu className="size-5" />
        </button>
        <BrandMark />
      </header>

      {open && (
        <div className="fixed inset-0 z-50 md:hidden">
          <div className="absolute inset-0 bg-black/60" onClick={() => setOpen(false)} aria-hidden="true" />
          <div
            role="dialog"
            aria-modal="true"
            aria-label="Navigation"
            className="absolute inset-y-0 left-0 flex w-64 max-w-[85%] flex-col border-r border-border-subtle bg-surface"
          >
            <div className="flex items-center justify-between px-5 py-5">
              <BrandMark />
              <button
                onClick={() => setOpen(false)}
                aria-label="Close navigation menu"
                className="cursor-pointer rounded-lg p-1.5 text-muted hover:bg-white/5 hover:text-foreground"
              >
                <X className="size-4" />
              </button>
            </div>
            <NavLinks onNavigate={() => setOpen(false)} />
            <UserFooter />
          </div>
        </div>
      )}
    </>
  );
}
