import { api } from "@/lib/api";
import type { Project, ProjectOrigin } from "@/lib/types";

/**
 * Where this project came from, so a clip's eventual published performance can be
 * traced back to the thing that suggested it. `id` is omitted for trending items,
 * which are fetched live from each platform and have no local row to point at.
 */
export interface ProjectOriginRef {
  type: ProjectOrigin;
  id?: number;
}

export async function createProjectFromUrl(
  sourceUrl: string,
  title: string,
  origin?: ProjectOriginRef
): Promise<Project> {
  const project = await api.post<{ data: Project }>("/projects", {
    title,
    origin_type: origin?.type,
    origin_id: origin?.id,
  });
  await api.post(`/projects/${project.data.id}/videos`, { url: sourceUrl });
  return project.data;
}
