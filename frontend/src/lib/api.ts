"use client";

import { useAuthStore } from "@/store/auth";

const CONFIGURED_API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";
const LOCAL_API_PORT = process.env.NEXT_PUBLIC_LOCAL_API_PORT ?? "8000";

// localhost, 127.x, ::1 and the private LAN ranges (10.x, 192.168.x, 172.16-31.x).
function isLocalHostname(hostname: string): boolean {
  if (hostname === "localhost" || hostname === "[::1]" || hostname.endsWith(".local")) return true;
  const m = hostname.match(/^(\d{1,3})\.(\d{1,3})\.\d{1,3}\.\d{1,3}$/);
  if (!m) return false;
  const [a, b] = [Number(m[1]), Number(m[2])];
  return a === 127 || a === 10 || (a === 192 && b === 168) || (a === 172 && b >= 16 && b <= 31);
}

/**
 * The API base URL, resolved when a request is made (never at import time, so server rendering
 * and the browser can't disagree).
 *
 * NEXT_PUBLIC_API_URL is baked into the build and points at the public domain. When the app is
 * opened directly on this machine or its LAN, talk to the API on that same host (port 8000)
 * instead — otherwise the local frontend would still call the public domain and stop working
 * the moment DNS or the reverse proxy for it is down. The public domain keeps using the
 * configured URL.
 */
function apiUrl(): string {
  if (typeof window !== "undefined" && isLocalHostname(window.location.hostname)) {
    return `${window.location.protocol}//${window.location.hostname}:${LOCAL_API_PORT}/api`;
  }
  return CONFIGURED_API_URL;
}

export class ApiError extends Error {
  status: number;
  errors?: Record<string, string[]>;

  constructor(message: string, status: number, errors?: Record<string, string[]>) {
    super(message);
    this.status = status;
    this.errors = errors;
  }
}

function getToken(): string | null {
  try {
    return useAuthStore.getState().token;
  } catch {
    return null;
  }
}

async function request<T>(
  path: string,
  options: RequestInit & { skipAuth?: boolean } = {}
): Promise<T> {
  const token = options.skipAuth ? null : getToken();
  const isFormData = options.body instanceof FormData;

  const headers: Record<string, string> = {
    Accept: "application/json",
    ...(isFormData ? {} : { "Content-Type": "application/json" }),
    ...(options.headers as Record<string, string> | undefined),
  };
  if (token) headers.Authorization = `Bearer ${token}`;

  const res = await fetch(`${apiUrl()}${path}`, { ...options, headers });

  if (res.status === 401) {
    useAuthStore.getState().logout();
  }

  if (!res.ok) {
    let message = `Request failed (${res.status})`;
    let errors: Record<string, string[]> | undefined;
    try {
      const body = await res.json();
      message = body.message ?? message;
      errors = body.errors;
    } catch {
      // ignore non-JSON error bodies
    }
    throw new ApiError(message, res.status, errors);
  }

  if (res.status === 204) return undefined as T;
  return res.json() as Promise<T>;
}

export const api = {
  get: <T>(path: string, options?: RequestInit & { skipAuth?: boolean }) => request<T>(path, options),
  post: <T>(path: string, body?: unknown, options: RequestInit & { skipAuth?: boolean } = {}) =>
    request<T>(path, {
      method: "POST",
      body: body instanceof FormData ? body : body !== undefined ? JSON.stringify(body) : undefined,
      ...options,
    }),
  patch: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: "PATCH", body: body !== undefined ? JSON.stringify(body) : undefined }),
  del: <T>(path: string) => request<T>(path, { method: "DELETE" }),
};

export function apiOrigin(): string {
  return apiUrl().replace(/\/api\/?$/, "");
}

// --- Cartoon explainer videos ---
// Every mutation returns the refreshed video (with scenes), except updateScene and
// delete, so callers can feed the result straight into the SWR cache.
export const cartoonApi = {
  create: (body: { source_type: "content_idea" | "content_brief"; source_id: number; voice?: string }) =>
    api.post<{ data: import("@/lib/types").CartoonVideo }>("/cartoon-videos", body),
  update: (id: number, body: { title?: string; voice?: string | null; character_description?: string | null }) =>
    api.patch<{ data: import("@/lib/types").CartoonVideo }>(`/cartoon-videos/${id}`, body),
  updateScene: (
    id: number,
    sceneId: number,
    body: { narration?: string; visual_prompt?: string; motion_prompt?: string | null }
  ) => api.patch<{ data: import("@/lib/types").CartoonVideo }>(`/cartoon-videos/${id}/scenes/${sceneId}`, body),
  regenerateStoryboard: (id: number) =>
    api.post<{ data: import("@/lib/types").CartoonVideo }>(`/cartoon-videos/${id}/regenerate-storyboard`),
  render: (id: number) => api.post<{ data: import("@/lib/types").CartoonVideo }>(`/cartoon-videos/${id}/render`),
  cancel: (id: number) => api.post<{ data: import("@/lib/types").CartoonVideo }>(`/cartoon-videos/${id}/cancel`),
  remove: (id: number) => api.del<{ message: string }>(`/cartoon-videos/${id}`),
};

// --- AI assistant ---
// Conversation-returning calls hand back the refreshed conversation so callers can
// write it straight into the SWR cache instead of waiting for the next poll.
export const assistantApi = {
  uploadAttachments: (files: File[]) => {
    const form = new FormData();
    files.forEach((f) => form.append("files[]", f));
    return api.post<{ data: import("@/lib/types").AssistantAttachment[] }>("/assistant/attachments", form);
  },
  sendMessage: (body: { conversation_id?: number | null; content: string; attachment_ids: number[] }) =>
    api.post<{ data: import("@/lib/types").AssistantConversation }>("/assistant/messages", body),
  decide: (id: number, decisions: Record<number, "approve" | "reject">) =>
    api.post<{ data: import("@/lib/types").AssistantConversation }>(`/assistant/conversations/${id}/decide`, { decisions }),
  stop: (id: number) => api.post<{ data: import("@/lib/types").AssistantConversation }>(`/assistant/conversations/${id}/stop`),
  rename: (id: number, title: string) =>
    api.patch<{ data: import("@/lib/types").AssistantConversationSummary }>(`/assistant/conversations/${id}`, { title }),
  remove: (id: number) => api.del<{ message: string }>(`/assistant/conversations/${id}`),
};
