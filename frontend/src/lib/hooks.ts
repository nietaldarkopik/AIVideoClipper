"use client";

import useSWR, { SWRConfiguration } from "swr";
import { api } from "@/lib/api";
import { useAuthStore } from "@/store/auth";

const fetcher = <T,>(path: string) => api.get<T>(path);

export function useApi<T>(path: string | null, config?: SWRConfiguration) {
  const token = useAuthStore((s) => s.token);
  return useSWR<T>(token && path ? path : null, fetcher, config);
}
