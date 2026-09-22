import Link from "next/link";
import { Scissors, Share2, CalendarClock } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { Header } from "@/components/layout/Header";
import { Footer } from "@/components/layout/Footer";

const features = [
  {
    icon: Scissors,
    title: "AI-powered clipping",
    description: "Upload a long-form video and let AI find and cut the moments worth sharing.",
  },
  {
    icon: Share2,
    title: "Publish everywhere",
    description: "Connect TikTok, YouTube, Facebook, and Instagram, then publish clips directly from the app.",
  },
  {
    icon: CalendarClock,
    title: "Schedule & track",
    description: "Queue posts ahead of time and follow how each clip performs after it goes out.",
  },
];

export default function Home() {
  return (
    <div className="flex min-h-screen w-full flex-col bg-background">
      <Header />

      <main className="flex-1">
        <section className="mx-auto max-w-3xl px-6 py-20 text-center sm:px-10">
          <h1 className="text-3xl font-semibold text-foreground sm:text-4xl">
            Turn long videos into social-ready clips, automatically
          </h1>
          <p className="mt-4 text-base text-muted">
            ClipWize uses AI to find the best moments in your videos, cut them into
            short clips, and publish them to TikTok, YouTube, Facebook, and Instagram.
          </p>
          <div className="mt-8 flex items-center justify-center gap-3">
            <Link href="/register">
              <Button size="lg">Get started</Button>
            </Link>
            <Link href="/login">
              <Button size="lg" variant="secondary">
                Log in
              </Button>
            </Link>
          </div>
        </section>

        <section className="mx-auto grid max-w-4xl gap-6 px-6 pb-20 sm:grid-cols-3 sm:px-10">
          {features.map((feature) => (
            <div
              key={feature.title}
              className="rounded-xl border border-border-subtle bg-surface p-5"
            >
              <feature.icon className="size-5 text-accent" />
              <h2 className="mt-3 text-sm font-semibold text-foreground">{feature.title}</h2>
              <p className="mt-1.5 text-sm text-muted">{feature.description}</p>
            </div>
          ))}
        </section>
      </main>

      <Footer />
    </div>
  );
}
