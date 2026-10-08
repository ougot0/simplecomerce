import type { SealedSecret } from "@/lib/vault";
import type { ContentSchema } from "@/lib/content/schema";
import type { ConnectorId } from "@/lib/adapters/catalog";

export type Role = "owner" | "editor";
export type Data = Record<string, unknown>;

export interface Profile {
  id: string;
  email: string;
  fullName: string;
  isSuperAdmin: boolean;
  createdAt: string;
}

export interface Site {
  id: string;
  slug: string;
  name: string;
  publicUrl: string;
  connector: ConnectorId;
  connectorConfig: Record<string, unknown>;
  status: "active" | "suspended";
  createdBy: string;
  createdAt: string;
  updatedAt: string;
  lastCheckAt: string | null;
  lastCheckOk: boolean | null;
}

export interface Membership {
  siteId: string;
  userId: string;
  role: Role;
  createdAt: string;
}

export interface CredentialsRecord {
  siteId: string;
  sealed: SealedSecret;
  /** Pour chaque champ secret enregistré : « …a3F9 ». Jamais la valeur. */
  fingerprints: Record<string, string>;
  updatedAt: string;
  updatedBy: string;
}

export interface SchemaRecord {
  id: string;
  siteId: string;
  version: number;
  definition: ContentSchema;
  createdBy: string;
  createdAt: string;
}

export type ChangeAction = "create" | "update" | "delete" | "reorder" | "revert";

export interface Change {
  id: string;
  siteId: string;
  actorId: string;
  onBehalfOf: string | null;
  action: ChangeAction;
  sectionKey: string;
  entryId: string | null;
  entryLabel: string;
  before: Data | null;
  after: Data | null;
  beforeOrder: string[] | null;
  afterOrder: string[] | null;
  remoteRef: string | null;
  status: "pending" | "applied" | "failed";
  errorMessage: string | null;
  revertsChangeId: string | null;
  revertedByChangeId: string | null;
  createdAt: string;
}

export interface Draft {
  id: string;
  siteId: string;
  sectionKey: string;
  /** null = nouvel élément pas encore en ligne. */
  entryId: string | null;
  label: string;
  data: Data;
  /** Version en ligne au moment où le brouillon a été commencé (pour détecter les conflits). */
  baseData: Data | null;
  createdBy: string;
  updatedBy: string;
  createdAt: string;
  updatedAt: string;
}

export interface MediaRecord {
  id: string;
  siteId: string;
  uploadedBy: string;
  storagePath: string;
  fileName: string;
  mime: string;
  bytes: number;
  width: number;
  height: number;
  alt: string;
  createdAt: string;
}

export interface Invitation {
  id: string;
  siteId: string;
  email: string;
  role: Role;
  tokenHash: string;
  invitedBy: string;
  createdAt: string;
  expiresAt: string;
  acceptedAt: string | null;
  acceptedBy: string | null;
}

export interface AuditEvent {
  id: string;
  at: string;
  actorId: string | null;
  onBehalfOf: string | null;
  siteId: string | null;
  kind: string;
  details: Data;
}

export interface Impersonation {
  id: string;
  adminId: string;
  targetUserId: string;
  startedAt: string;
  expiresAt: string;
  endedAt: string | null;
}

export type NewSite = Omit<Site, "id" | "createdAt" | "updatedAt" | "lastCheckAt" | "lastCheckOk">;

/**
 * Accès aux données. Aucune méthode ne vérifie les droits : c'est le rôle de lib/access.ts,
 * appelé au début de chaque action. En production, la Row Level Security de Postgres
 * constitue une seconde barrière indépendante.
 */
export interface Store {
  // Profils
  getProfile(id: string): Promise<Profile | null>;
  getProfileByEmail(email: string): Promise<Profile | null>;
  listProfiles(): Promise<Profile[]>;
  updateProfile(id: string, patch: Partial<Pick<Profile, "fullName">>): Promise<void>;

  // Sites
  listSitesForUser(userId: string): Promise<(Site & { role: Role })[]>;
  listAllSites(): Promise<Site[]>;
  getSiteBySlug(slug: string): Promise<Site | null>;
  getSite(id: string): Promise<Site | null>;
  slugExists(slug: string): Promise<boolean>;
  /** Crée le site et rend `ownerId` propriétaire (opération privilégiée). */
  createSite(site: NewSite, ownerId: string): Promise<Site>;
  updateSite(id: string, patch: Partial<Omit<Site, "id" | "createdAt" | "createdBy">>): Promise<void>;
  deleteSite(id: string): Promise<void>;

  // Membres
  listMembers(siteId: string): Promise<(Membership & { profile: Profile })[]>;
  getMembership(siteId: string, userId: string): Promise<Membership | null>;
  addMember(siteId: string, userId: string, role: Role): Promise<void>;
  removeMember(siteId: string, userId: string): Promise<void>;

  // Identifiants (privilégié : jamais lisibles depuis le navigateur)
  getCredentials(siteId: string): Promise<CredentialsRecord | null>;
  setCredentials(record: CredentialsRecord): Promise<void>;

  // Schémas
  getActiveSchema(siteId: string): Promise<SchemaRecord | null>;
  saveSchema(siteId: string, definition: ContentSchema, createdBy: string): Promise<SchemaRecord>;

  // Historique
  insertChange(change: Omit<Change, "id" | "createdAt">): Promise<Change>;
  updateChange(id: string, patch: Partial<Change>): Promise<void>;
  getChange(id: string): Promise<Change | null>;
  listChanges(siteId: string, limit: number): Promise<Change[]>;

  // Brouillons
  listDrafts(siteId: string): Promise<Draft[]>;
  getDraft(id: string): Promise<Draft | null>;
  findDraft(siteId: string, sectionKey: string, entryId: string | null): Promise<Draft | null>;
  saveDraft(draft: Omit<Draft, "id" | "createdAt" | "updatedAt"> & { id?: string }): Promise<Draft>;
  deleteDraft(id: string): Promise<void>;

  // Photos en attente
  insertMedia(media: Omit<MediaRecord, "createdAt">): Promise<MediaRecord>;
  getMedia(id: string): Promise<MediaRecord | null>;
  putMediaBlob(path: string, data: Buffer, mime: string): Promise<void>;
  getMediaBlob(path: string): Promise<Buffer | null>;

  // Invitations (privilégié)
  createInvitation(inv: Omit<Invitation, "id" | "createdAt" | "acceptedAt" | "acceptedBy">): Promise<Invitation>;
  getInvitationByTokenHash(tokenHash: string): Promise<Invitation | null>;
  listInvitations(siteId: string): Promise<Invitation[]>;
  acceptInvitation(id: string, userId: string): Promise<void>;
  deleteInvitation(id: string): Promise<void>;

  // Journal
  logEvent(event: Omit<AuditEvent, "id" | "at">): Promise<void>;
  listEvents(filter: { siteId?: string; limit: number }): Promise<AuditEvent[]>;

  // Assistance (« se connecter en tant que »)
  createImpersonation(adminId: string, targetUserId: string, minutes: number): Promise<Impersonation>;
  getImpersonation(id: string): Promise<Impersonation | null>;
  endImpersonation(id: string): Promise<void>;
}
