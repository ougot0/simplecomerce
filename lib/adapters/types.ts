import type { ContentSchema, Section } from "@/lib/content/schema";

export type Data = Record<string, unknown>;

export interface Entry {
  id: string;
  data: Data;
}

export interface CheckResult {
  label: string;
  ok: boolean;
  /** Message pour l'utilisateur, en français simple, sans jargon. */
  hint?: string;
}

export interface ConnectionReport {
  ok: boolean;
  checks: CheckResult[];
}

/** Image prête à être envoyée sur le site (déjà vérifiée et réencodée). */
export interface PendingAsset {
  token: string;
  buffer: Buffer;
  fileName: string;
  mime: string;
  alt?: string;
}

export interface ChangeContext {
  authorName: string;
  /** Résumé lisible : « « Tarte au citron » modifiée ». Utilisé comme message de commit. */
  summary: string;
  changeId: string;
  assets: PendingAsset[];
}

export interface WriteResult {
  id?: string;
  before: Data | null;
  after: Data | null;
  ref?: string;
  /** Ordre des éléments, pour les réordonnancements. */
  beforeOrder?: string[];
  afterOrder?: string[];
}

export interface AdapterCapabilities {
  reorder: boolean;
  create: boolean;
  delete: boolean;
}

export interface SiteAdapter {
  capabilities(section: Section): AdapterCapabilities;
  testConnection(): Promise<ConnectionReport>;
  /** Propose un schéma de contenu à partir du site lui-même. */
  discover(): Promise<{ schema: ContentSchema; notes: string[] }>;

  listEntries(section: Section): Promise<Entry[]>;
  getEntry(section: Section, id: string): Promise<Entry | null>;
  createEntry(section: Section, data: Data, ctx: ChangeContext): Promise<WriteResult>;
  /** `expected` = état connu par le client ; si le site a changé entre-temps, ConflictError. */
  updateEntry(section: Section, id: string, data: Data, expected: Data | null, ctx: ChangeContext): Promise<WriteResult>;
  deleteEntry(section: Section, id: string, expected: Data | null, ctx: ChangeContext): Promise<WriteResult>;
  reorder(section: Section, orderedIds: string[], ctx: ChangeContext): Promise<WriteResult>;

  getSingleton(section: Section): Promise<Data>;
  updateSingleton(section: Section, data: Data, expected: Data | null, ctx: ChangeContext): Promise<WriteResult>;

  /** Valeurs de départ d'un nouvel élément (ex. une ligne de prix pour un produit Shopify). */
  template?(section: Section): Data;

  /** Adresse publique d'une image telle que stockée dans le contenu (chemin relatif → URL). */
  resolveImageUrl(value: string): string | null;
  close?(): Promise<void>;
}

export type AdapterErrorCode = "auth" | "not_found" | "conflict" | "network" | "invalid" | "rate_limited" | "unsupported" | "remote";

export class AdapterError extends Error {
  constructor(
    public code: AdapterErrorCode,
    /** Message montré au client. */
    public userMessage: string,
    /** Détail technique (journalisé, masqué). */
    public detail?: string,
  ) {
    super(detail ? `${userMessage} — ${detail}` : userMessage);
    this.name = "AdapterError";
  }
}

export class ConflictError extends AdapterError {
  constructor(detail?: string) {
    super(
      "conflict",
      "Ce contenu a été modifié ailleurs entre-temps. Rechargez la page pour voir la version actuelle avant de recommencer.",
      detail,
    );
    this.name = "ConflictError";
  }
}

export function userMessageFor(error: unknown): string {
  if (error instanceof AdapterError) return error.userMessage;
  return "Une erreur inattendue s'est produite. Réessayez dans un instant ; si cela continue, contactez votre administrateur.";
}
