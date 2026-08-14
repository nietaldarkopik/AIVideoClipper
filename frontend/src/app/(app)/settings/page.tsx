"use client";

import { useAuthStore } from "@/store/auth";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PublishingProfiles } from "@/components/social/PublishingProfiles";

export default function SettingsPage() {
  const user = useAuthStore((s) => s.user);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Settings</h1>
        <p className="mt-1 text-sm text-muted">Your account and publishing preferences.</p>
      </div>

      <Card className="p-5">
        <h3 className="text-sm font-semibold">Account</h3>
        <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <p className="text-xs text-muted">Name</p>
            <p className="mt-1 text-sm">{user?.name}</p>
          </div>
          <div>
            <p className="text-xs text-muted">Email</p>
            <p className="mt-1 text-sm">{user?.email}</p>
          </div>
          <div>
            <p className="text-xs text-muted">Role</p>
            <Badge tone={user?.role === "admin" ? "accent" : "default"} className="mt-1 capitalize">
              {user?.role}
            </Badge>
          </div>
        </div>
      </Card>

      <PublishingProfiles />
    </div>
  );
}
