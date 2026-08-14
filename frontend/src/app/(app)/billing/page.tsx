import { CreditCard } from "lucide-react";
import { EmptyState } from "@/components/ui/EmptyState";

export default function BillingPage() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold">Billing</h1>
        <p className="mt-1 text-sm text-muted">Manage your plan and usage.</p>
      </div>
      <EmptyState
        icon={<CreditCard className="size-6" />}
        title="Billing coming soon"
        description="This workspace is running on the free local development plan — no billing is wired up yet."
      />
    </div>
  );
}
