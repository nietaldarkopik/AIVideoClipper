"use client";

import { useAuthStore } from "@/store/auth";

const API_URL = process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api";

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

  const res = await fetch(`${API_URL}${path}`, { ...options, headers });

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
  return API_URL.replace(/\/api\/?$/, "");
}

/**
 * Absolute URL for a path stored on the backend's "media" disk (a layer's
 * image_path/audio_path, a waveform sidecar, ...). Built from the API origin
 * rather than the backend's own app.url, so it stays correct when the frontend
 * talks to a different host than the one Laravel thinks it's serving from.
 * The /api/media/{path} route is public, so these work in a plain <img>/<audio>
 * with no bearer token attached.
 */
export function mediaUrl(path: string | null | undefined): string | null {
  if (!path) return null;
  return `${apiOrigin()}/api/media/${path.replace(/^\/+/, "")}`;
}
