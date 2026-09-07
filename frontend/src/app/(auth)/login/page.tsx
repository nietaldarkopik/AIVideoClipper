"use client";

import { useEffect, useState, FormEvent, Suspense } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { Card, CardContent } from "@/components/ui/Card";
import { Input, Label } from "@/components/ui/Input";
import { Button } from "@/components/ui/Button";
import { TikTokLoginButton } from "@/components/auth/TikTokLoginButton";
import { api, ApiError } from "@/lib/api";
import { useAuthStore } from "@/store/auth";
import { toast } from "@/store/toast";
import type { User } from "@/lib/types";

function LoginPageInner() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const setAuth = useAuthStore((s) => s.setAuth);
  const [email, setEmail] = useState("demo@clipper.test");
  const [password, setPassword] = useState("password");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [resumingTikTok, setResumingTikTok] = useState(false);

  // Lands here after a "Continue with TikTok" round trip
  // (AuthController::tiktokCallback always redirects back with
  // ?tiktok_token=<sanctum token> or ?error=<message>).
  useEffect(() => {
    const tiktokToken = searchParams.get("tiktok_token");
    const oauthError = searchParams.get("error");

    if (!tiktokToken && !oauthError) return;

    if (oauthError) {
      toast(oauthError, "danger");
      router.replace("/login");
      return;
    }

    setResumingTikTok(true);
    (async () => {
      try {
        // AuthController::me() returns UserResource as the top-level response,
        // which Laravel auto-wraps in { data: ... } — unlike /auth/login and
        // /auth/register, which nest it manually under "user" (unwrapped).
        const { data: user } = await api.get<{ data: User }>("/auth/me", {
          headers: { Authorization: `Bearer ${tiktokToken}` },
        });
        setAuth(tiktokToken!, user);
        router.push("/dashboard");
      } catch (err) {
        toast(err instanceof ApiError ? err.message : "TikTok login failed.", "danger");
        setResumingTikTok(false);
        router.replace("/login");
      }
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams]);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      const res = await api.post<{ user: User; token: string }>(
        "/auth/login",
        { email, password },
        { skipAuth: true }
      );
      setAuth(res.token, res.user);
      router.push("/dashboard");
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <Card>
      <CardContent className="pt-6">
        <h1 className="text-lg font-semibold">Welcome back</h1>
        <p className="mt-1 text-sm text-muted">Sign in to keep clipping.</p>

        <div className="mt-6">
          <TikTokLoginButton label={resumingTikTok ? "Signing you in…" : "Continue with TikTok"} />
        </div>

        <div className="my-5 flex items-center gap-3 text-xs text-muted">
          <div className="h-px flex-1 bg-border-subtle" />
          or
          <div className="h-px flex-1 bg-border-subtle" />
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <Label htmlFor="email">Email</Label>
            <Input
              id="email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
          </div>
          <div>
            <Label htmlFor="password">Password</Label>
            <Input
              id="password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </div>
          {error && <p className="text-sm text-danger">{error}</p>}
          <Button type="submit" className="w-full" loading={loading}>
            Sign in
          </Button>
        </form>

        <p className="mt-5 text-center text-xs text-muted">
          Demo account prefilled — admin@clipper.test / password for admin access.
        </p>
        <p className="mt-3 text-center text-sm text-muted">
          Don&apos;t have an account?{" "}
          <Link href="/register" className="font-medium text-accent-2 hover:underline">
            Sign up
          </Link>
        </p>
      </CardContent>
    </Card>
  );
}

export default function LoginPage() {
  return (
    <Suspense fallback={null}>
      <LoginPageInner />
    </Suspense>
  );
}
