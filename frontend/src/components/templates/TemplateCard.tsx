import Link from "next/link";
import { LayoutTemplate } from "lucide-react";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import type { Template } from "@/lib/types";

export function TemplateCard({ template }: { template: Template }) {
  return (
    <Link href={`/templates/${template.id}`}>
      <Card className="group overflow-hidden transition-colors hover:border-accent/40">
        <div
          className="relative flex aspect-[9/16] max-h-56 w-full items-center justify-center overflow-hidden bg-gradient-to-br from-surface-elevated to-surface"
        >
          {template.thumbnail_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={template.thumbnail_url} alt={template.name} className="h-full w-full object-cover" />
          ) : (
            <LayoutTemplate className="size-8 text-muted" />
          )}
          <div className="absolute right-2 top-2">
            <Badge tone="muted">{template.aspect_ratio}</Badge>
          </div>
        </div>
        <div className="p-4">
          <h3 className="truncate text-sm font-semibold">{template.name}</h3>
          <p className="mt-1 line-clamp-2 text-xs text-muted">{template.description}</p>
          {template.category && (
            <Badge tone="accent" className="mt-2">
              {template.category.name}
            </Badge>
          )}
        </div>
      </Card>
    </Link>
  );
}
