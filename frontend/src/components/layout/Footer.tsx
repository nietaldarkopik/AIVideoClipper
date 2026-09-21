import Link from "next/link";

export function Footer() {
  return (
    <footer className="shrink-0 border-t border-border-subtle px-6 py-4 sm:px-10">
      <div className="mx-auto flex max-w-4xl flex-col items-center justify-between gap-2 text-xs text-muted sm:flex-row">
        <span>© {new Date().getFullYear()} Existcode</span>
        <div className="flex items-center gap-4">
          <Link href="/privacy-policy" className="hover:text-foreground">
            Privacy Policy
          </Link>
          <Link href="/terms-of-service" className="hover:text-foreground">
            Terms of Service
          </Link>
        </div>
      </div>
    </footer>
  );
}
