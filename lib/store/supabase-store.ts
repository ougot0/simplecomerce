import "server-only";
import { supabaseAdmin } from "@/lib/supabase/admin";
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
 * Stockage de production. Utilise la clé service : les droits sont vérifiés en amont par lib/access.ts.
 */

type Row = Record<string, unknown>;

/** Lève une erreur lisible ; pour .maybeSingle(), l'appelant teste lui-même l'absence de ligne. */
// Le client Supabase n'est pas typé avec le schéma : les lignes sont converties une à une ci-dessous.
function check(res: { data: unknown; error: { message: string } | null }): any {
  if (res.error) throw new Error(`Base de données : ${res.error.message}`);
  return res.data;
}

const toProfile = (r: Row): Profile => ({
  id: r.id as string,
  email: r.email as string,
  fullName: (r.full_name as string) ?? "",
  isSuperAdmin: !!r.is_super_admin,
  createdAt: r.created_at as string,
});

const toSite = (r: Row): Site => ({
  id: r.id as string,
  slug: r.slug as string,
  name: r.name as string,
  publicUrl: (r.public_url as string) ?? "",
  connector: r.connector as Site["connector"],
  connectorConfig: (r.connector_config as Record<string, unknown>) ?? {},
  status: r.status as Site["status"],
  createdBy: r.created_by as string,
  createdAt: r.created_at as string,
  updatedAt: r.updated_at as string,
  lastCheckAt: (r.last_check_at as string) ?? null,
  lastCheckOk: (r.last_check_ok as boolean) ?? null,
});

const siteToRow = (s: Partial<Site>): Row => {
  const map: Record<string, string> = {
    slug: "slug",
    name: "name",
    publicUrl: "public_url",
    connector: "connector",
    connectorConfig: "connector_config",
    status: "status",
    createdBy: "created_by",
    lastCheckAt: "last_check_at",
    lastCheckOk: "last_check_ok",
  };
  const row: Row = {};
  for (const [k, v] of Object.entries(s)) if (map[k]) row[map[k]] = v;
  return row;
};

const toChange = (r: Row): Change => ({
  id: r.id as string,
  siteId: r.site_id as string,
  actorId: r.actor_id as string,
  onBehalfOf: (r.on_behalf_of as string) ?? null,
  action: r.action as Change["action"],
  sectionKey: r.section_key as string,
  entryId: (r.entry_id as string) ?? null,
  entryLabel: (r.entry_label as string) ?? "",
  before: (r.before as Change["before"]) ?? null,
  after: (r.after as Change["after"]) ?? null,
  beforeOrder: (r.before_order as string[]) ?? null,
  afterOrder: (r.after_order as string[]) ?? null,
  remoteRef: (r.remote_ref as string) ?? null,
  status: r.status as Change["status"],
  errorMessage: (r.error_message as string) ?? null,
  revertsChangeId: (r.reverts_change_id as string) ?? null,
  revertedByChangeId: (r.reverted_by_change_id as string) ?? null,
  createdAt: r.created_at as string,
});

const changeToRow = (c: Partial<Change>): Row => {
  const map: Record<string, string> = {
    siteId: "site_id",
    actorId: "actor_id",
    onBehalfOf: "on_behalf_of",
    action: "action",
    sectionKey: "section_key",
    entryId: "entry_id",
    entryLabel: "entry_label",
    before: "before",
    after: "after",
    beforeOrder: "before_order",
    afterOrder: "after_order",
    remoteRef: "remote_ref",
    status: "status",
    errorMessage: "error_message",
    revertsChangeId: "reverts_change_id",
    revertedByChangeId: "reverted_by_change_id",
  };
  const row: Row = {};
  for (const [k, v] of Object.entries(c)) if (map[k]) row[map[k]] = v;
  return row;
};

const toDraft = (r: Row): Draft => ({
  id: r.id as string,
  siteId: r.site_id as string,
  sectionKey: r.section_key as string,
  entryId: (r.entry_id as string) ?? null,
  label: (r.label as string) ?? "",
  data: r.data as Draft["data"],
  baseData: (r.base_data as Draft["baseData"]) ?? null,
  createdBy: r.created_by as string,
  updatedBy: r.updated_by as string,
  createdAt: r.created_at as string,
  updatedAt: r.updated_at as string,
});

const toMedia = (r: Row): MediaRecord => ({
  id: r.id as string,
  siteId: r.site_id as string,
  uploadedBy: r.uploaded_by as string,
  storagePath: r.storage_path as string,
  fileName: r.file_name as string,
  mime: r.mime as string,
  bytes: r.bytes as number,
  width: r.width as number,
  height: r.height as number,
  alt: (r.alt as string) ?? "",
  createdAt: r.created_at as string,
});

const toInvitation = (r: Row): Invitation => ({
  id: r.id as string,
  siteId: r.site_id as string,
  email: r.email as string,
  role: r.role as Role,
  tokenHash: r.token_hash as string,
  invitedBy: r.invited_by as string,
  createdAt: r.created_at as string,
  expiresAt: r.expires_at as string,
  acceptedAt: (r.accepted_at as string) ?? null,
  acceptedBy: (r.accepted_by as string) ?? null,
});

const toEvent = (r: Row): AuditEvent => ({
  id: r.id as string,
  at: r.at as string,
  actorId: (r.actor_id as string) ?? null,
  onBehalfOf: (r.on_behalf_of as string) ?? null,
  siteId: (r.site_id as string) ?? null,
  kind: r.kind as string,
  details: (r.details as AuditEvent["details"]) ?? {},
});

const toImpersonation = (r: Row): Impersonation => ({
  id: r.id as string,
  adminId: r.admin_id as string,
  targetUserId: r.target_user_id as string,
  startedAt: r.started_at as string,
  expiresAt: r.expires_at as string,
  endedAt: (r.ended_at as string) ?? null,
});

export class SupabaseStore implements Store {
  private get db() {
    return supabaseAdmin();
  }

  async getProfile(id: string) {
    const data = check(await this.db.from("profiles").select("*").eq("id", id).maybeSingle());
    return data ? toProfile(data) : null;
  }
  async getProfileByEmail(email: string) {
    const data = check(await this.db.from("profiles").select("*").ilike("email", email.replace(/[%_\\]/g, "\\$&")).maybeSingle());
    return data ? toProfile(data) : null;
  }
  async listProfiles() {
    return (check(await this.db.from("profiles").select("*").order("full_name")) as Row[]).map(toProfile);
  }
  async updateProfile(id: string, patch: Partial<Pick<Profile, "fullName">>) {
    if (patch.fullName !== undefined) check(await this.db.from("profiles").update({ full_name: patch.fullName }).eq("id", id));
  }

  async listSitesForUser(userId: string) {
    const rows = check(await this.db.from("site_members").select("role, sites(*)").eq("user_id", userId));
    return (rows as unknown as { role: Role; sites: Row }[])
      .filter((r) => r.sites)
      .map((r) => ({ ...toSite(r.sites), role: r.role }))
      .sort((a, b) => a.name.localeCompare(b.name));
  }
  async listAllSites() {
    return check(await this.db.from("sites").select("*").order("name")).map(toSite);
  }
  async getSiteBySlug(slug: string) {
    const data = check(await this.db.from("sites").select("*").eq("slug", slug).maybeSingle());
    return data ? toSite(data) : null;
  }
  async getSite(id: string) {
    const data = check(await this.db.from("sites").select("*").eq("id", id).maybeSingle());
    return data ? toSite(data) : null;
  }
  async slugExists(slug: string) {
    const data = check(await this.db.from("sites").select("id").eq("slug", slug).maybeSingle());
    return !!data;
  }
  async createSite(input: NewSite, ownerId: string) {
    const site = toSite(check(await this.db.from("sites").insert(siteToRow(input)).select("*").single()));
    check(await this.db.from("site_members").insert({ site_id: site.id, user_id: ownerId, role: "owner" }));
    return site;
  }
  async updateSite(id: string, patch: Partial<Site>) {
    check(await this.db.from("sites").update({ ...siteToRow(patch), updated_at: new Date().toISOString() }).eq("id", id));
  }
  async deleteSite(id: string) {
    check(await this.db.from("sites").delete().eq("id", id));
  }

  async listMembers(siteId: string) {
    const rows = check(await this.db.from("site_members").select("*, profiles(*)").eq("site_id", siteId));
    return (rows as Row[])
      .filter((r) => r.profiles)
      .map((r) => ({
        siteId: r.site_id as string,
        userId: r.user_id as string,
        role: r.role as Role,
        createdAt: r.created_at as string,
        profile: toProfile(r.profiles as Row),
      }));
  }
  async getMembership(siteId: string, userId: string): Promise<Membership | null> {
    const r = check(await this.db.from("site_members").select("*").eq("site_id", siteId).eq("user_id", userId).maybeSingle());
    return r ? { siteId: r.site_id, userId: r.user_id, role: r.role, createdAt: r.created_at } : null;
  }
  async addMember(siteId: string, userId: string, role: Role) {
    check(await this.db.from("site_members").upsert({ site_id: siteId, user_id: userId, role }));
  }
  async removeMember(siteId: string, userId: string) {
    check(await this.db.from("site_members").delete().eq("site_id", siteId).eq("user_id", userId));
  }

  async getCredentials(siteId: string): Promise<CredentialsRecord | null> {
    const r = check(await this.db.from("site_credentials").select("*").eq("site_id", siteId).maybeSingle());
    if (!r) return null;
    return {
      siteId: r.site_id,
      sealed: { ciphertext: r.ciphertext, iv: r.iv, authTag: r.auth_tag, keyVersion: r.key_version },
      fingerprints: r.fingerprints ?? {},
      updatedAt: r.updated_at,
      updatedBy: r.updated_by,
    };
  }
  async setCredentials(record: CredentialsRecord) {
    check(
      await this.db.from("site_credentials").upsert({
        site_id: record.siteId,
        ciphertext: record.sealed.ciphertext,
        iv: record.sealed.iv,
        auth_tag: record.sealed.authTag,
        key_version: record.sealed.keyVersion,
        fingerprints: record.fingerprints,
        updated_at: record.updatedAt,
        updated_by: record.updatedBy,
      }),
    );
  }

  async getActiveSchema(siteId: string): Promise<SchemaRecord | null> {
    const r = check(await this.db.from("content_schemas").select("*").eq("site_id", siteId).order("version", { ascending: false }).limit(1).maybeSingle());
    return r ? { id: r.id, siteId: r.site_id, version: r.version, definition: r.definition, createdBy: r.created_by, createdAt: r.created_at } : null;
  }
  async saveSchema(siteId: string, definition: SchemaRecord["definition"], createdBy: string) {
    const current = await this.getActiveSchema(siteId);
    const r = check(
      await this.db.from("content_schemas").insert({ site_id: siteId, version: (current?.version ?? 0) + 1, definition, created_by: createdBy }).select("*").single(),
    );
    return { id: r.id, siteId: r.site_id, version: r.version, definition: r.definition, createdBy: r.created_by, createdAt: r.created_at };
  }

  async insertChange(change: Omit<Change, "id" | "createdAt">) {
    return toChange(check(await this.db.from("changes").insert(changeToRow(change)).select("*").single()));
  }
  async updateChange(id: string, patch: Partial<Change>) {
    check(await this.db.from("changes").update(changeToRow(patch)).eq("id", id));
  }
  async getChange(id: string) {
    const r = check(await this.db.from("changes").select("*").eq("id", id).maybeSingle());
    return r ? toChange(r) : null;
  }
  async listChanges(siteId: string, limit: number) {
    return check(await this.db.from("changes").select("*").eq("site_id", siteId).order("created_at", { ascending: false }).limit(limit)).map(toChange);
  }

  async listDrafts(siteId: string) {
    return check(await this.db.from("drafts").select("*").eq("site_id", siteId).order("updated_at", { ascending: false })).map(toDraft);
  }
  async getDraft(id: string) {
    const r = check(await this.db.from("drafts").select("*").eq("id", id).maybeSingle());
    return r ? toDraft(r) : null;
  }
  async findDraft(siteId: string, sectionKey: string, entryId: string | null) {
    if (entryId === null) return null;
    const r = check(await this.db.from("drafts").select("*").eq("site_id", siteId).eq("section_key", sectionKey).eq("entry_id", entryId).limit(1).maybeSingle());
    return r ? toDraft(r) : null;
  }
  async saveDraft(input: Omit<Draft, "id" | "createdAt" | "updatedAt"> & { id?: string }) {
    const row = {
      site_id: input.siteId,
      section_key: input.sectionKey,
      entry_id: input.entryId,
      label: input.label,
      data: input.data,
      base_data: input.baseData,
      created_by: input.createdBy,
      updated_by: input.updatedBy,
      updated_at: new Date().toISOString(),
    };
    const r = input.id
      ? check(await this.db.from("drafts").update(row).eq("id", input.id).select("*").single())
      : check(await this.db.from("drafts").insert(row).select("*").single());
    return toDraft(r);
  }
  async deleteDraft(id: string) {
    check(await this.db.from("drafts").delete().eq("id", id));
  }

  async insertMedia(media: Omit<MediaRecord, "createdAt">) {
    return toMedia(
      check(
        await this.db
          .from("media")
          .insert({
            id: media.id,
            site_id: media.siteId,
            uploaded_by: media.uploadedBy,
            storage_path: media.storagePath,
            file_name: media.fileName,
            mime: media.mime,
            bytes: media.bytes,
            width: media.width,
            height: media.height,
            alt: media.alt,
          })
          .select("*")
          .single(),
      ),
    );
  }
  async getMedia(id: string) {
    const r = check(await this.db.from("media").select("*").eq("id", id).maybeSingle());
    return r ? toMedia(r) : null;
  }
  async putMediaBlob(path: string, data: Buffer, mime: string) {
    const { error } = await this.db.storage.from("staging").upload(path, data, { contentType: mime, upsert: false });
    if (error) throw new Error(`Stockage : ${error.message}`);
  }
  async getMediaBlob(path: string) {
    const { data, error } = await this.db.storage.from("staging").download(path);
    if (error || !data) return null;
    return Buffer.from(await data.arrayBuffer());
  }

  async createInvitation(inv: Omit<Invitation, "id" | "createdAt" | "acceptedAt" | "acceptedBy">) {
    return toInvitation(
      check(
        await this.db
          .from("invitations")
          .insert({ site_id: inv.siteId, email: inv.email, role: inv.role, token_hash: inv.tokenHash, invited_by: inv.invitedBy, expires_at: inv.expiresAt })
          .select("*")
          .single(),
      ),
    );
  }
  async getInvitationByTokenHash(tokenHash: string) {
    const r = check(await this.db.from("invitations").select("*").eq("token_hash", tokenHash).maybeSingle());
    return r ? toInvitation(r) : null;
  }
  async listInvitations(siteId: string) {
    return check(await this.db.from("invitations").select("*").eq("site_id", siteId).is("accepted_at", null)).map(toInvitation);
  }
  async acceptInvitation(id: string, userId: string) {
    const inv = check(await this.db.from("invitations").update({ accepted_at: new Date().toISOString(), accepted_by: userId }).eq("id", id).is("accepted_at", null).select("*").maybeSingle());
    if (!inv) return;
    const existing = await this.getMembership(inv.site_id, userId);
    if (!existing) await this.addMember(inv.site_id, userId, inv.role);
  }
  async deleteInvitation(id: string) {
    check(await this.db.from("invitations").delete().eq("id", id));
  }

  async logEvent(event: Omit<AuditEvent, "id" | "at">) {
    check(
      await this.db.from("audit_events").insert({
        actor_id: event.actorId,
        on_behalf_of: event.onBehalfOf,
        site_id: event.siteId,
        kind: event.kind,
        details: event.details,
      }),
    );
  }
  async listEvents(filter: { siteId?: string; limit: number }) {
    let q = this.db.from("audit_events").select("*").order("at", { ascending: false }).limit(filter.limit);
    if (filter.siteId) q = q.eq("site_id", filter.siteId);
    return check(await q).map(toEvent);
  }

  async createImpersonation(adminId: string, targetUserId: string, minutes: number) {
    return toImpersonation(
      check(
        await this.db
          .from("impersonations")
          .insert({ admin_id: adminId, target_user_id: targetUserId, expires_at: new Date(Date.now() + minutes * 60_000).toISOString() })
          .select("*")
          .single(),
      ),
    );
  }
  async getImpersonation(id: string) {
    const r = check(await this.db.from("impersonations").select("*").eq("id", id).maybeSingle());
    return r ? toImpersonation(r) : null;
  }
  async endImpersonation(id: string) {
    check(await this.db.from("impersonations").update({ ended_at: new Date().toISOString() }).eq("id", id).is("ended_at", null));
  }
}
