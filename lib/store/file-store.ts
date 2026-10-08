import "server-only";
import { promises as fs } from "node:fs";
import path from "node:path";
import { randomUUID } from "node:crypto";
import type {
  AuditEvent,
  Change,
  CredentialsRecord,
  Draft,
  Impersonation,
  Invitation,
  MediaRecord,
  Membership,
  NewSite,
  Profile,
  Role,
  SchemaRecord,
  Site,
  Store,
} from "./types";

/**
 * Stockage du mode démonstration : un fichier JSON dans .data/.
 * Même interface que le stockage Supabase, pour que tout le reste du code soit identique.
 */

export interface DemoAuthUser {
  id: string;
  email: string;
  passwordHash: string;
}

interface DemoDb {
  profiles: Profile[];
  authUsers: DemoAuthUser[];
  magicLinks: { tokenHash: string; userId: string; expiresAt: string; remember: boolean }[];
  sites: Site[];
  memberships: Membership[];
  credentials: CredentialsRecord[];
  schemas: SchemaRecord[];
  changes: Change[];
  drafts: Draft[];
  media: MediaRecord[];
  invitations: Invitation[];
  events: AuditEvent[];
  impersonations: Impersonation[];
}

const DATA_DIR = path.resolve(process.cwd(), ".data");
const DB_FILE = path.join(DATA_DIR, "demo.json");

const empty = (): DemoDb => ({
  profiles: [],
  authUsers: [],
  magicLinks: [],
  sites: [],
  memberships: [],
  credentials: [],
  schemas: [],
  changes: [],
  drafts: [],
  media: [],
  invitations: [],
  events: [],
  impersonations: [],
});

// Partagé entre tous les modules du serveur (pages, actions et routes peuvent être des paquets séparés),
// et relu si le fichier a changé sur le disque.
const shared = globalThis as unknown as { __scDemo?: { cache: DemoDb | null; mtime: number; queue: Promise<unknown> } };
const state = (shared.__scDemo ??= { cache: null, mtime: 0, queue: Promise.resolve() });

async function load(): Promise<DemoDb> {
  let mtime = 0;
  try {
    mtime = (await fs.stat(DB_FILE)).mtimeMs;
  } catch {
    // pas encore de fichier
  }
  if (state.cache && mtime === state.mtime) return state.cache;
  try {
    state.cache = { ...empty(), ...(JSON.parse(await fs.readFile(DB_FILE, "utf8")) as DemoDb) };
  } catch {
    state.cache = empty();
  }
  state.mtime = mtime;
  return state.cache;
}

async function persist(db: DemoDb) {
  await fs.mkdir(DATA_DIR, { recursive: true });
  const tmp = `${DB_FILE}.${process.pid}.tmp`;
  await fs.writeFile(tmp, JSON.stringify(db, null, 1));
  await fs.rename(tmp, DB_FILE);
  state.mtime = (await fs.stat(DB_FILE)).mtimeMs;
}

/** Les écritures passent une par une. */
export function withDb<T>(fn: (db: DemoDb) => T | Promise<T>, write = false): Promise<T> {
  const run = state.queue.then(async () => {
    const db = await load();
    const result = await fn(db);
    if (write) await persist(db);
    return result;
  });
  state.queue = run.catch(() => undefined);
  return run;
}

const now = () => new Date().toISOString();
const clone = <T>(v: T): T => (v === undefined ? v : (JSON.parse(JSON.stringify(v)) as T));

export class FileStore implements Store {
  async getProfile(id: string) {
    return withDb((db) => clone(db.profiles.find((p) => p.id === id) ?? null));
  }
  async getProfileByEmail(email: string) {
    return withDb((db) => clone(db.profiles.find((p) => p.email.toLowerCase() === email.toLowerCase()) ?? null));
  }
  async listProfiles() {
    return withDb((db) => clone([...db.profiles].sort((a, b) => a.fullName.localeCompare(b.fullName))));
  }
  async updateProfile(id: string, patch: Partial<Pick<Profile, "fullName">>) {
    await withDb((db) => {
      const p = db.profiles.find((x) => x.id === id);
      if (p) Object.assign(p, patch);
    }, true);
  }

  async listSitesForUser(userId: string) {
    return withDb((db) =>
      clone(
        db.memberships
          .filter((m) => m.userId === userId)
          .map((m) => ({ ...db.sites.find((s) => s.id === m.siteId)!, role: m.role }))
          .filter((s) => s.id)
          .sort((a, b) => a.name.localeCompare(b.name)),
      ),
    );
  }
  async listAllSites() {
    return withDb((db) => clone([...db.sites].sort((a, b) => a.name.localeCompare(b.name))));
  }
  async getSiteBySlug(slug: string) {
    return withDb((db) => clone(db.sites.find((s) => s.slug === slug) ?? null));
  }
  async getSite(id: string) {
    return withDb((db) => clone(db.sites.find((s) => s.id === id) ?? null));
  }
  async slugExists(slug: string) {
    return withDb((db) => db.sites.some((s) => s.slug === slug));
  }
  async createSite(input: NewSite, ownerId: string) {
    return withDb((db) => {
      const site: Site = { ...input, id: randomUUID(), createdAt: now(), updatedAt: now(), lastCheckAt: null, lastCheckOk: null };
      db.sites.push(site);
      db.memberships.push({ siteId: site.id, userId: ownerId, role: "owner", createdAt: now() });
      return clone(site);
    }, true);
  }
  async updateSite(id: string, patch: Partial<Site>) {
    await withDb((db) => {
      const s = db.sites.find((x) => x.id === id);
      if (s) Object.assign(s, patch, { updatedAt: now() });
    }, true);
  }
  async deleteSite(id: string) {
    await withDb((db) => {
      db.sites = db.sites.filter((s) => s.id !== id);
      db.memberships = db.memberships.filter((m) => m.siteId !== id);
      db.credentials = db.credentials.filter((c) => c.siteId !== id);
      db.drafts = db.drafts.filter((d) => d.siteId !== id);
      db.invitations = db.invitations.filter((i) => i.siteId !== id);
    }, true);
  }

  async listMembers(siteId: string) {
    return withDb((db) =>
      clone(
        db.memberships
          .filter((m) => m.siteId === siteId)
          .map((m) => ({ ...m, profile: db.profiles.find((p) => p.id === m.userId)! }))
          .filter((m) => m.profile),
      ),
    );
  }
  async getMembership(siteId: string, userId: string) {
    return withDb((db) => clone(db.memberships.find((m) => m.siteId === siteId && m.userId === userId) ?? null));
  }
  async addMember(siteId: string, userId: string, role: Role) {
    await withDb((db) => {
      const existing = db.memberships.find((m) => m.siteId === siteId && m.userId === userId);
      if (existing) existing.role = role;
      else db.memberships.push({ siteId, userId, role, createdAt: now() });
    }, true);
  }
  async removeMember(siteId: string, userId: string) {
    await withDb((db) => {
      db.memberships = db.memberships.filter((m) => !(m.siteId === siteId && m.userId === userId));
    }, true);
  }

  async getCredentials(siteId: string) {
    return withDb((db) => clone(db.credentials.find((c) => c.siteId === siteId) ?? null));
  }
  async setCredentials(record: CredentialsRecord) {
    await withDb((db) => {
      db.credentials = [...db.credentials.filter((c) => c.siteId !== record.siteId), record];
    }, true);
  }

  async getActiveSchema(siteId: string) {
    return withDb((db) => {
      const list = db.schemas.filter((s) => s.siteId === siteId).sort((a, b) => b.version - a.version);
      return clone(list[0] ?? null);
    });
  }
  async saveSchema(siteId: string, definition: SchemaRecord["definition"], createdBy: string) {
    return withDb((db) => {
      const version = Math.max(0, ...db.schemas.filter((s) => s.siteId === siteId).map((s) => s.version)) + 1;
      const record: SchemaRecord = { id: randomUUID(), siteId, version, definition, createdBy, createdAt: now() };
      db.schemas.push(record);
      return clone(record);
    }, true);
  }

  async insertChange(change: Omit<Change, "id" | "createdAt">) {
    return withDb((db) => {
      const record: Change = { ...change, id: randomUUID(), createdAt: now() };
      db.changes.push(record);
      return clone(record);
    }, true);
  }
  async updateChange(id: string, patch: Partial<Change>) {
    await withDb((db) => {
      const c = db.changes.find((x) => x.id === id);
      if (c) Object.assign(c, patch);
    }, true);
  }
  async getChange(id: string) {
    return withDb((db) => clone(db.changes.find((c) => c.id === id) ?? null));
  }
  async listChanges(siteId: string, limit: number) {
    return withDb((db) =>
      clone(
        db.changes
          .filter((c) => c.siteId === siteId)
          .sort((a, b) => b.createdAt.localeCompare(a.createdAt))
          .slice(0, limit),
      ),
    );
  }

  async listDrafts(siteId: string) {
    return withDb((db) => clone(db.drafts.filter((d) => d.siteId === siteId).sort((a, b) => b.updatedAt.localeCompare(a.updatedAt))));
  }
  async getDraft(id: string) {
    return withDb((db) => clone(db.drafts.find((d) => d.id === id) ?? null));
  }
  async findDraft(siteId: string, sectionKey: string, entryId: string | null) {
    return withDb((db) => clone(db.drafts.find((d) => d.siteId === siteId && d.sectionKey === sectionKey && d.entryId === entryId && entryId !== null) ?? null));
  }
  async saveDraft(input: Omit<Draft, "id" | "createdAt" | "updatedAt"> & { id?: string }) {
    return withDb((db) => {
      const existing = input.id ? db.drafts.find((d) => d.id === input.id) : undefined;
      if (existing) {
        Object.assign(existing, input, { updatedAt: now() });
        return clone(existing);
      }
      const draft: Draft = { ...input, id: randomUUID(), createdAt: now(), updatedAt: now() };
      db.drafts.push(draft);
      return clone(draft);
    }, true);
  }
  async deleteDraft(id: string) {
    await withDb((db) => {
      db.drafts = db.drafts.filter((d) => d.id !== id);
    }, true);
  }

  async insertMedia(media: Omit<MediaRecord, "createdAt">) {
    return withDb((db) => {
      const record = { ...media, createdAt: now() };
      db.media.push(record);
      return clone(record);
    }, true);
  }
  async getMedia(id: string) {
    return withDb((db) => clone(db.media.find((m) => m.id === id) ?? null));
  }
  async putMediaBlob(rel: string, data: Buffer) {
    const full = path.join(DATA_DIR, "media", rel);
    if (!full.startsWith(path.join(DATA_DIR, "media") + path.sep)) throw new Error("Chemin interdit");
    await fs.mkdir(path.dirname(full), { recursive: true });
    await fs.writeFile(full, data);
  }
  async getMediaBlob(rel: string) {
    const full = path.join(DATA_DIR, "media", rel);
    if (!full.startsWith(path.join(DATA_DIR, "media") + path.sep)) return null;
    try {
      return await fs.readFile(full);
    } catch {
      return null;
    }
  }

  async createInvitation(inv: Omit<Invitation, "id" | "createdAt" | "acceptedAt" | "acceptedBy">) {
    return withDb((db) => {
      const record: Invitation = { ...inv, id: randomUUID(), createdAt: now(), acceptedAt: null, acceptedBy: null };
      db.invitations.push(record);
      return clone(record);
    }, true);
  }
  async getInvitationByTokenHash(tokenHash: string) {
    return withDb((db) => clone(db.invitations.find((i) => i.tokenHash === tokenHash) ?? null));
  }
  async listInvitations(siteId: string) {
    return withDb((db) => clone(db.invitations.filter((i) => i.siteId === siteId && !i.acceptedAt)));
  }
  async acceptInvitation(id: string, userId: string) {
    await withDb((db) => {
      const inv = db.invitations.find((i) => i.id === id);
      if (!inv) return;
      inv.acceptedAt = now();
      inv.acceptedBy = userId;
      const existing = db.memberships.find((m) => m.siteId === inv.siteId && m.userId === userId);
      if (!existing) db.memberships.push({ siteId: inv.siteId, userId, role: inv.role, createdAt: now() });
    }, true);
  }
  async deleteInvitation(id: string) {
    await withDb((db) => {
      db.invitations = db.invitations.filter((i) => i.id !== id);
    }, true);
  }

  async logEvent(event: Omit<AuditEvent, "id" | "at">) {
    await withDb((db) => {
      db.events.push({ ...event, id: randomUUID(), at: now() });
      if (db.events.length > 5000) db.events = db.events.slice(-5000);
    }, true);
  }
  async listEvents(filter: { siteId?: string; limit: number }) {
    return withDb((db) =>
      clone(
        db.events
          .filter((e) => !filter.siteId || e.siteId === filter.siteId)
          .sort((a, b) => b.at.localeCompare(a.at))
          .slice(0, filter.limit),
      ),
    );
  }

  async createImpersonation(adminId: string, targetUserId: string, minutes: number) {
    return withDb((db) => {
      const record: Impersonation = {
        id: randomUUID(),
        adminId,
        targetUserId,
        startedAt: now(),
        expiresAt: new Date(Date.now() + minutes * 60_000).toISOString(),
        endedAt: null,
      };
      db.impersonations.push(record);
      return clone(record);
    }, true);
  }
  async getImpersonation(id: string) {
    return withDb((db) => clone(db.impersonations.find((i) => i.id === id) ?? null));
  }
  async endImpersonation(id: string) {
    await withDb((db) => {
      const i = db.impersonations.find((x) => x.id === id);
      if (i && !i.endedAt) i.endedAt = now();
    }, true);
  }
}
