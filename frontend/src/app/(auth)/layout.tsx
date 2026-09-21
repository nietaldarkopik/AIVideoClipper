import { Header } from "@/components/layout/Header";
import { Footer } from "@/components/layout/Footer";

export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen w-full flex-col bg-background">
      <Header />
      <div className="relative flex flex-1 items-center justify-center px-4 py-12">
        <div className="pointer-events-none absolute inset-0 overflow-hidden">
          <div className="absolute left-1/2 top-0 h-[500px] w-[800px] -translate-x-1/2 rounded-full bg-accent/10 blur-[120px]" />
        </div>
        <div className="relative w-full max-w-sm">{children}</div>
      </div>
      <Footer />
    </div>
  );
}
