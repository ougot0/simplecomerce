import "server-only";
import path from "node:path";
import type { ContentSchema } from "@/lib/content/schema";
import { AdapterError, type SiteAdapter } from "./types";
import { FileSiteAdapter, type FileSiteOptions } from "./files/engine";
import { GitHubBackend } from "./files/github";
import { GitLabBackend } from "./files/gitlab";
import { BitbucketBackend } from "./files/bitbucket";
import { SftpBackend } from "./files/sftp";
import { FtpBackend } from "./files/ftp";
import { LocalBackend } from "./files/local";
import { ShopifyAdapter, SHOPIFY_DOMAIN_RE } from "./shopify";
import { WordPressAdapter } from "./wordpress";
import { WebflowAdapter } from "./webflow";
import { CustomApiAdapter } from "./custom-api";
import { assertPublicHost, assertPublicUrl } from "./net";
import { safeJoin } from "@/lib/content/paths";
import { appMode } from "@/lib/env";
import { CONNECTORS, type ConnectorId } from "./catalog";

/**
 * Fabrique des adaptateurs à partir de la configuration enregistrée.
 * Le catalogue (libellés, champs du formulaire) est dans catalog.ts, partagé avec l'interface.
 */

type Values = Record<string, string>;

export interface CreateContext {
  siteId: string;
  publicUrl: string;
  schema: ContentSchema | null;
}

/** Valeurs saisies → configuration non secrète + secrets, avec vérifications. */
export async function parseConnectorValues(
  id: ConnectorId,
  values: Values,
  existingSecrets: Record<string, string> | null,
): Promise<{ config: Record<string, unknown>; secrets: Record<string, string>; errors: Record<string, string> }> {
  const def = CONNECTORS.find((c) => c.id === id);
  if (!def || (def.demoOnly && appMode() !== "demo")) throw new AdapterError("invalid", "Type de site inconnu.");
  const config: Record<string, unknown> = {};
  const secrets: Record<string, string> = { ...(existingSecrets ?? {}) };
  const errors: Record<string, string> = {};

  for (const field of def.fields) {
    const raw = (values[field.name] ?? "").trim();
    const value = field.type === "textarea" ? (values[field.name] ?? "").replace(/\r\n/g, "\n").trim() : raw;
    if (field.secret) {
      if (value) secrets[field.name] = value;
      else if (field.required && !secrets[field.name]) errors[field.name] = "Ce champ est obligatoire.";
      continue;
    }
    const finalValue = value || field.default || "";
    if (field.required && !finalValue) {
      errors[field.name] = "Ce champ est obligatoire.";
      continue;
    }
    if (field.pattern && finalValue && !new RegExp(field.pattern).test(finalValue)) {
      errors[field.name] = field.patternMessage ?? "Format invalide.";
      continue;
    }
    if (field.type === "number" && finalValue) config[field.name] = Number(finalValue);
    else config[field.name] = finalValue;
  }

  // Vérifications propres à chaque connecteur.
  try {
    switch (id) {
      case "github": {
        const m = String(config.repository).match(/^(?:https?:\/\/github\.com\/)?([\w.-]+)\/([\w.-]+?)(?:\.git)?\/?$/i);
        if (!m) errors.repository = "Indiquez l'adresse du dépôt, par exemple https://github.com/mon-compte/mon-site";
        else Object.assign(config, { owner: m[1], repo: m[2] });
        if (config.apiBase) await assertPublicUrl(String(config.apiBase));
        break;
      }
      case "gitlab": {
        const url = await assertPublicUrl(String(config.repository).startsWith("http") ? String(config.repository) : `https://gitlab.com/${config.repository}`);
        const project = url.pathname.replace(/^\/|\/$|\.git$/g, "");
        if (!project.includes("/")) errors.repository = "Indiquez l'adresse complète du projet.";
        Object.assign(config, { baseUrl: url.origin, project });
        break;
      }
      case "bitbucket": {
        const m = String(config.repository).match(/^(?:https?:\/\/bitbucket\.org\/)?([\w.-]+)\/([\w.-]+?)(?:\.git)?\/?$/i);
        if (!m) errors.repository = "Indiquez l'adresse du dépôt, par exemple https://bitbucket.org/espace/mon-site";
        else Object.assign(config, { workspace: m[1], repo: m[2] });
        break;
      }
      case "sftp":
      case "ftp":
        await assertPublicHost(String(config.host));
        if (id === "sftp" && !secrets.password && !secrets.privateKey) errors.password = "Indiquez un mot de passe ou une clé privée.";
        if (!String(config.remoteRoot).startsWith("/")) config.remoteRoot = `/${config.remoteRoot}`;
        break;
      case "shopify": {
        const domain = String(config.shopDomain).toLowerCase().replace(/^https?:\/\//, "").replace(/\/.*$/, "");
        if (!SHOPIFY_DOMAIN_RE.test(domain)) errors.shopDomain = "L'adresse doit se terminer par .myshopify.com (visible dans Shopify → Paramètres → Domaines).";
        config.shopDomain = domain;
        break;
      }
      case "wordpress":
        await assertPublicUrl(String(config.siteUrl));
        break;
      case "custom-api":
        await assertPublicUrl(String(config.baseUrl));
        break;
    }
  } catch (err) {
    const target = def.fields.find((f) => !f.secret)?.name ?? "_";
    errors[target] = err instanceof AdapterError ? err.userMessage : "Adresse invalide.";
  }

  return { config, secrets, errors };
}

function fileOptions(config: Record<string, unknown>, ctx: CreateContext, webRoot = false): FileSiteOptions {
  const configuredDir = String(config.contentDir ?? "auto");
  const contentDir = configuredDir === "auto" && ctx.schema?.contentDir !== undefined ? ctx.schema.contentDir : configuredDir === "auto" ? "auto" : safeJoin(configuredDir);
  const media = ctx.schema?.media ?? (config.mediaDir && config.mediaDir !== "auto"
    ? { dir: safeJoin(String(config.mediaDir)), publicPrefix: String(config.mediaPublicPrefix || `/${safeJoin(String(config.mediaDir)).replace(/^(public|static)\//, "")}`) }
    : { dir: "auto", publicPrefix: "/images/simplecommerce" });
  return { contentDir, media, publicUrl: ctx.publicUrl, webRoot };
}

export async function createAdapter(
  id: ConnectorId,
  config: Record<string, unknown>,
  secrets: Record<string, string>,
  ctx: CreateContext,
): Promise<SiteAdapter> {
  const s = (k: string) => String(config[k] ?? "");
  switch (id) {
    case "github":
      return new FileSiteAdapter(
        new GitHubBackend({ owner: s("owner"), repo: s("repo"), branch: s("branch") || "main", token: secrets.token, apiBase: s("apiBase") || undefined }),
        fileOptions(config, ctx),
      );
    case "gitlab":
      return new FileSiteAdapter(new GitLabBackend({ project: s("project"), branch: s("branch") || "main", token: secrets.token, baseUrl: s("baseUrl") }), fileOptions(config, ctx));
    case "bitbucket":
      return new FileSiteAdapter(
        new BitbucketBackend({ workspace: s("workspace"), repo: s("repo"), branch: s("branch") || "main", token: secrets.token, username: s("username") || undefined }),
        fileOptions(config, ctx),
      );
    case "sftp":
      await assertPublicHost(s("host"));
      return new FileSiteAdapter(
        new SftpBackend({
          host: s("host"),
          port: Number(config.port) || 22,
          username: s("username"),
          password: secrets.password,
          privateKey: secrets.privateKey,
          passphrase: secrets.passphrase,
          remoteRoot: s("remoteRoot") || "/",
          hostFingerprint: s("hostFingerprint") || undefined,
        }),
        fileOptions(config, ctx, true),
      );
    case "ftp":
      await assertPublicHost(s("host"));
      return new FileSiteAdapter(
        new FtpBackend({
          host: s("host"),
          port: Number(config.port) || 0,
          username: s("username"),
          password: secrets.password,
          tls: (s("tls") || "explicit") as "explicit" | "implicit" | "none",
          remoteRoot: s("remoteRoot") || "/",
        }),
        fileOptions(config, ctx, true),
      );
    case "shopify":
      return new ShopifyAdapter({ shopDomain: s("shopDomain"), accessToken: secrets.accessToken, apiVersion: s("apiVersion") || undefined });
    case "wordpress":
      await assertPublicUrl(s("siteUrl"));
      return new WordPressAdapter({
        siteUrl: s("siteUrl"),
        username: s("username"),
        applicationPassword: secrets.applicationPassword,
        wooConsumerKey: secrets.wooConsumerKey,
        wooConsumerSecret: secrets.wooConsumerSecret,
      });
    case "webflow":
      return new WebflowAdapter({ token: secrets.token, siteId: s("siteId") || undefined });
    case "custom-api":
      await assertPublicUrl(s("baseUrl"));
      return new CustomApiAdapter({ baseUrl: s("baseUrl"), secret: secrets.secret });
    case "demo": {
      if (appMode() !== "demo") throw new AdapterError("invalid", "Connecteur réservé à la démonstration.");
      const root = path.resolve(process.cwd(), "demo", "sites", path.basename(s("folder")));
      // Détection comme pour un vrai site : public/ si le site en a un, sinon la racine.
      return new FileSiteAdapter(new LocalBackend(root), { ...fileOptions(config, ctx, false), publicUrl: ctx.publicUrl });
    }
  }
}

/** Empreinte du serveur SFTP vue pendant un test (à mémoriser). */
export function sftpFingerprint(adapter: SiteAdapter): string | null {
  const backend = (adapter as unknown as { backend?: unknown }).backend;
  return backend instanceof SftpBackend ? backend.seenFingerprint : null;
}
