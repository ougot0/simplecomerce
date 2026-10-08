import "server-only";
import { appMode } from "@/lib/env";
import { FileStore } from "./file-store";
import { SupabaseStore } from "./supabase-store";
import type { Store } from "./types";

let store: Store | null = null;

export function getStore(): Store {
  if (!store) store = appMode() === "demo" ? new FileStore() : new SupabaseStore();
  return store;
}

export type * from "./types";
