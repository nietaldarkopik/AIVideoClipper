"use client";

import { useApi } from "@/lib/hooks";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Skeleton } from "@/components/ui/Skeleton";
import { formatRelativeTime } from "@/lib/format";
import type { Paginated, User } from "@/lib/types";

interface AdminUser extends User {
  projects_count?: number;
}

export default function AdminUsersPage() {
  const { data, isLoading } = useApi<Paginated<AdminUser>>("/admin/users?per_page=50");

  if (isLoading || !data) return <Skeleton className="h-80" />;

  return (
    <Card className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-border-subtle text-left text-xs text-muted">
            <th className="px-5 py-3 font-medium">Name</th>
            <th className="px-5 py-3 font-medium">Email</th>
            <th className="px-5 py-3 font-medium">Role</th>
            <th className="px-5 py-3 font-medium">Projects</th>
            <th className="px-5 py-3 font-medium">Joined</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-border-subtle">
          {data.data.map((user) => (
            <tr key={user.id}>
              <td className="px-5 py-3 font-medium">{user.name}</td>
              <td className="px-5 py-3 text-muted">{user.email}</td>
              <td className="px-5 py-3">
                <Badge tone={user.role === "admin" ? "accent" : "default"} className="capitalize">
                  {user.role}
                </Badge>
              </td>
              <td className="px-5 py-3 text-muted">{user.projects_count ?? 0}</td>
              <td className="px-5 py-3 text-muted">{formatRelativeTime(user.created_at)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </Card>
  );
}
