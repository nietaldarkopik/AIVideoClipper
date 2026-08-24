import { api } from "@/lib/api";
import type { Project } from "@/lib/types";

export async function createProjectFromUrl(sourceUrl: string, title: string): Promise<Project> {
  const project = await api.post<{ data: Project }>("/projects", { title });
  await api.post(`/projects/${project.data.id}/videos`, { url: sourceUrl });
  return project.data;
}
