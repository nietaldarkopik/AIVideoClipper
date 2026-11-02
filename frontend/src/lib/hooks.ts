"use client";

import useSWR, { SWRConfiguration } from "swr";
import { api } from "@/lib/api";
import { useAuthStore } from "@/store/auth";
import type {
  AssistantConfig,
  AssistantConversation,
  AssistantConversationSummary,
  CartoonVideo,
  Paginated,
} from "@/lib/types";

const fetcher = <T,>(path: string) => api.get<T>(path);

export function useApi<T>(path: string | null, config?: SWRConfiguration) {
  const token = useAuthStore((s) => s.token);
  return useSWR<T>(token && path ? path : null, fetcher, config);
}

export const CARTOON_ACTIVE_STATUSES = ["pending", "storyboarding", "rendering", "assembling"];

export function useCartoonVideos() {
  return useApi<Paginated<CartoonVideo>>("/cartoon-videos?per_page=50", {
    refreshInterval: (latest?: Paginated<CartoonVideo>) =>
      latest?.data.some((v) => CARTOON_ACTIVE_STATUSES.includes(v.status)) ? 3000 : 0,
  });
}

/** One cartoon video with its scenes; polls every 3s while a job is working on it. */
export function useCartoonVideo(id: number) {
  return useApi<{ data: CartoonVideo }>(`/cartoon-videos/${id}`, {
    refreshInterval: (latest?: { data: CartoonVideo }) =>
      latest && CARTOON_ACTIVE_STATUSES.includes(latest.data.status) ? 3000 : 0,
  });
}

export function useAssistantConfig() {
  return useApi<AssistantConfig>("/assistant/config", { revalidateOnFocus: false });
}

export function useAssistantConversations() {
  return useApi<Paginated<AssistantConversationSummary>>("/assistant/conversations?per_page=50", {
    // The list only changes when the user acts or a run finishes; refresh it while any
    // conversation is busy so titles/ordering follow along.
    refreshInterval: (latest?: Paginated<AssistantConversationSummary>) =>
      latest?.data.some((c) => c.status === "running") ? 4000 : 0,
  });
}

/** One conversation; polls every 2s ONLY while the assistant is running. */
export function useAssistantConversation(id: number | null) {
  return useApi<{ data: AssistantConversation }>(id ? `/assistant/conversations/${id}` : null, {
    refreshInterval: (latest?: { data: AssistantConversation }) => (latest?.data.status === "running" ? 2000 : 0),
  });
}
