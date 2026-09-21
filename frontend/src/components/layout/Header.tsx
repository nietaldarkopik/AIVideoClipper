"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { Button } from "@/components/ui/Button";

export function Header() {
  const pathname = usePathname();
  const isHome = pathname === "/";

  return (
    <header className="flex items-center justify-between px-6 py-5 sm:px-10">
      <Link href="/" className="flex items-center gap-2">
        <Image src="/logo.png" alt="Existcode" width={32} height={32} className="size-8 rounded-lg" />
        <span className="text-sm font-semibold">Existcode</span>
      </Link>
      <nav className="flex items-center gap-4">
        {!isHome && (
          <Link href="/" className="text-sm text-muted hover:text-foreground">
            Home
          </Link>
        )}
        <Link href="/login" className="text-sm text-muted hover:text-foreground">
          Log in
        </Link>
        <Link href="/register">
          <Button size="sm">Get started</Button>
        </Link>
      </nav>
    </header>
  );
}
