import "server-only";
import type { ContentSchema, Section } from "@/lib/content/schema";
import { collectMediaTokens, deepEqual, MEDIA_TOKEN_PREFIX, pickFields } from "@/lib/content/values";
import { httpError } from "./http";
import {
  AdapterError,
  ConflictError,
  type AdapterCapabilities,
  type ChangeContext,
  type ConnectionReport,
  type Data,
  type Entry,
  type PendingAsset,
  type SiteAdapter,
  type WriteResult,
} from "./types";

/**
 * Shopify — API Admin GraphQL.
 * Jeton : application personnalisée de la boutique (Paramètres → Applications → Développer des applications),
 * portées : read/write_products, read/write_inventory, read_locations.
 */

export interface ShopifyConfig {
  shopDomain: string;
  accessToken: string;
  apiVersion?: string;
}

export const SHOPIFY_DOMAIN_RE = /^[a-z0-9][a-z0-9-]*\.myshopify\.com$/;
const DEFAULT_API_VERSION = "2025-07";

const PRODUCT_FIELDS = `
  id title descriptionHtml status
  media(first: 30) { nodes { id ... on MediaImage { image { url } } } }
  variants(first: 100) { nodes { id title price compareAtPrice inventoryQuantity inventoryItem { id tracked } } }
`;

interface ShopifyVariant {
  id: string;
  title: string;
  price: string;
  compareAtPrice: string | null;
  inventoryQuantity: number | null;
  inventoryItem: { id: string; tracked: boolean } | null;
}
interface ShopifyProduct {
  id: string;
  title: string;
  descriptionHtml: string;
  status: "ACTIVE" | "DRAFT" | "ARCHIVED";
  media: { nodes: { id: string; image?: { url: string } }[] };
  variants: { nodes: ShopifyVariant[] };
}

const PRODUCTS_SECTION: Section = {
  key: "produits",
  label: "Produits",
  kind: "collection",
  itemLabel: "produit",
  titleField: "titre",
  imageField: "photos",
  subtitleField: "prixAffiche",
  orderable: false,
  allowCreate: true,
  allowDelete: true,
  fields: [
    { key: "titre", label: "Nom du produit", type: "text", required: true, maxLength: 255 },
    { key: "photos", label: "Photos", type: "gallery", aspect: "libre", maxWidth: 2048 },
    { key: "description", label: "Description", type: "richtext" },
    {
      key: "declinaisons",
      label: "Prix et stock",
      type: "repeater",
      fixedRows: true,
      itemLabel: "déclinaison",
      fields: [
        { key: "nom", label: "Déclinaison", type: "text", readOnly: true },
        { key: "prix", label: "Prix", type: "price", required: true, priceFormat: { store: "number" } },
        { key: "prixBarre", label: "Prix barré", type: "price", priceFormat: { store: "number" }, help: "Ancien prix affiché barré (facultatif)." },
        { key: "stock", label: "Stock", type: "number", min: 0, help: "Laissé vide si le stock n'est pas suivi." },
        { key: "variantId", label: "Référence", type: "text", hidden: true },
        { key: "inventoryItemId", label: "Référence stock", type: "text", hidden: true },
      ],
    },
    { key: "enVente", label: "En vente sur la boutique", type: "boolean", help: "Décochez pour masquer le produit sans le supprimer." },
    { key: "prixAffiche", label: "Prix", type: "text", hidden: true },
  ],
};

const COLLECTIONS_SECTION: Section = {
  key: "collections",
  label: "Collections",
  kind: "collection",
  itemLabel: "collection",
  titleField: "titre",
  orderable: false,
  allowCreate: true,
  allowDelete: false,
  fields: [
    { key: "titre", label: "Nom de la collection", type: "text", required: true, maxLength: 255 },
    { key: "description", label: "Description", type: "richtext" },
  ],
};

const numericId = (gid: string) => gid.split("/").pop()!;

export class ShopifyAdapter implements SiteAdapter {
  private locationId: string | null = null;
  constructor(private cfg: ShopifyConfig) {
    if (!SHOPIFY_DOMAIN_RE.test(cfg.shopDomain)) {
      throw new AdapterError("invalid", "L'adresse de la boutique doit être de la forme ma-boutique.myshopify.com.");
    }
  }

  private async gql<T>(query: string, variables: Record<string, unknown> = {}, attempt = 0): Promise<T> {
    const url = `https://${this.cfg.shopDomain}/admin/api/${this.cfg.apiVersion ?? DEFAULT_API_VERSION}/graphql.json`;
    let res: Response;
    try {
      res = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-Shopify-Access-Token": this.cfg.accessToken },
        body: JSON.stringify({ query, variables }),
        signal: AbortSignal.timeout(25_000),
        cache: "no-store",
      });
    } catch (err) {
      throw new AdapterError("network", "Impossible de joindre Shopify.", String(err));
    }
    const text = await res.text();
    if (!res.ok) throw httpError("Shopify", res.status, text);
    const body = JSON.parse(text) as { data?: T; errors?: { message: string; extensions?: { code?: string } }[] };
    if (body.errors?.length) {
      if (body.errors.some((e) => e.extensions?.code === "THROTTLED") && attempt < 4) {
        await new Promise((r) => setTimeout(r, 1500 * (attempt + 1)));
        return this.gql(query, variables, attempt + 1);
      }
      if (body.errors.some((e) => e.extensions?.code === "ACCESS_DENIED")) {
        throw new AdapterError("auth", "Le jeton Shopify n'a pas les autorisations nécessaires.", body.errors.map((e) => e.message).join("; "));
      }
      throw new AdapterError("remote", "Shopify a refusé la demande.", body.errors.map((e) => e.message).join("; "));
    }
    return body.data as T;
  }

  private assertNoUserErrors(errors: { field?: string[] | null; message: string }[] | undefined) {
    if (errors && errors.length) {
      throw new AdapterError("invalid", `Shopify a refusé la modification : ${errors.map((e) => e.message).join(" ; ")}`);
    }
  }

  capabilities(section: Section): AdapterCapabilities {
    return { reorder: false, create: section.allowCreate !== false, delete: section.allowDelete !== false };
  }

  async testConnection(): Promise<ConnectionReport> {
    const checks = [];
    try {
      const data = await this.gql<{ shop: { name: string }; currentAppInstallation: { accessScopes: { handle: string }[] } }>(
        `{ shop { name } currentAppInstallation { accessScopes { handle } } }`,
      );
      checks.push({ label: `Boutique « ${data.shop.name} » trouvée`, ok: true });
      const scopes = new Set(data.currentAppInstallation.accessScopes.map((s) => s.handle));
      const has = (s: string) => scopes.has(s);
      checks.push(
        has("write_products")
          ? { label: "Modification des produits autorisée", ok: true }
          : { label: "Modification des produits", ok: false, hint: "Ajoutez l'autorisation « write_products » à l'application personnalisée." },
      );
      checks.push(
        has("write_inventory") && has("read_locations")
          ? { label: "Gestion du stock autorisée", ok: true }
          : { label: "Gestion du stock", ok: false, hint: "Pour modifier le stock, ajoutez « write_inventory » et « read_locations »." },
      );
    } catch (err) {
      checks.push({ label: "Connexion à la boutique", ok: false, hint: err instanceof AdapterError ? err.userMessage : "Connexion impossible." });
    }
    return { ok: checks.every((c) => c.ok), checks };
  }

  async discover(): Promise<{ schema: ContentSchema; notes: string[] }> {
    return { schema: { version: 1, sections: [PRODUCTS_SECTION, COLLECTIONS_SECTION] }, notes: [] };
  }

  resolveImageUrl(value: string): string | null {
    return /^https:\/\//.test(value) ? value : null;
  }

  template(section: Section): Data {
    if (section.key !== "produits") return {};
    return { enVente: false, declinaisons: [{ nom: "Prix unique", prix: null, prixBarre: null, stock: null }] };
  }

  // ---------- Correspondances ----------

  private toData(p: ShopifyProduct): Data {
    const variants = p.variants.nodes.map((v) => ({
      nom: v.title === "Default Title" ? "Prix unique" : v.title,
      prix: Number(v.price),
      prixBarre: v.compareAtPrice ? Number(v.compareAtPrice) : null,
      stock: v.inventoryItem?.tracked ? v.inventoryQuantity : null,
      variantId: v.id,
      inventoryItemId: v.inventoryItem?.id ?? "",
    }));
    const prices = variants.map((v) => v.prix);
    const min = Math.min(...prices);
    return {
      titre: p.title,
      photos: p.media.nodes.map((m) => m.image?.url).filter(Boolean),
      description: p.descriptionHtml,
      declinaisons: variants,
      enVente: p.status === "ACTIVE",
      prixAffiche: prices.length ? `${prices.length > 1 && Math.max(...prices) !== min ? "dès " : ""}${min.toLocaleString("fr-FR", { minimumFractionDigits: 2 })} €` : "",
    };
  }

  async listEntries(section: Section): Promise<Entry[]> {
    if (section.key === "collections") {
      const data = await this.gql<{ collections: { nodes: { id: string; title: string; descriptionHtml: string }[] } }>(
        `{ collections(first: 100, sortKey: TITLE) { nodes { id title descriptionHtml } } }`,
      );
      return data.collections.nodes.map((c) => ({ id: numericId(c.id), data: { titre: c.title, description: c.descriptionHtml } }));
    }
    const out: Entry[] = [];
    let after: string | null = null;
    for (let page = 0; page < 10; page++) {
      const data: { products: { nodes: ShopifyProduct[]; pageInfo: { hasNextPage: boolean; endCursor: string } } } = await this.gql(
        `query($after: String) { products(first: 50, after: $after, sortKey: TITLE) { nodes { ${PRODUCT_FIELDS} } pageInfo { hasNextPage endCursor } } }`,
        { after },
      );
      out.push(...data.products.nodes.filter((p) => p.status !== "ARCHIVED").map((p) => ({ id: numericId(p.id), data: this.toData(p) })));
      if (!data.products.pageInfo.hasNextPage) break;
      after = data.products.pageInfo.endCursor;
    }
    return out;
  }

  private async fetchProduct(id: string): Promise<ShopifyProduct | null> {
    const data = await this.gql<{ product: ShopifyProduct | null }>(`query($id: ID!) { product(id: $id) { ${PRODUCT_FIELDS} } }`, {
      id: `gid://shopify/Product/${id}`,
    });
    return data.product;
  }

  async getEntry(section: Section, id: string): Promise<Entry | null> {
    if (!/^\d+$/.test(id)) return null;
    if (section.key === "collections") {
      const data = await this.gql<{ collection: { title: string; descriptionHtml: string } | null }>(
        `query($id: ID!) { collection(id: $id) { title descriptionHtml } }`,
        { id: `gid://shopify/Collection/${id}` },
      );
      return data.collection ? { id, data: { titre: data.collection.title, description: data.collection.descriptionHtml } } : null;
    }
    const p = await this.fetchProduct(id);
    return p ? { id, data: this.toData(p) } : null;
  }

  async getSingleton(): Promise<Data> {
    throw new AdapterError("unsupported", "Rubrique non disponible pour Shopify.");
  }
  async updateSingleton(): Promise<WriteResult> {
    throw new AdapterError("unsupported", "Rubrique non disponible pour Shopify.");
  }
  async reorder(): Promise<WriteResult> {
    throw new AdapterError("unsupported", "L'ordre des produits se règle dans les collections Shopify.");
  }

  // ---------- Photos ----------

  private async stageUpload(asset: PendingAsset): Promise<string> {
    const data = await this.gql<{
      stagedUploadsCreate: { stagedTargets: { url: string; resourceUrl: string; parameters: { name: string; value: string }[] }[]; userErrors: { message: string }[] };
    }>(
      `mutation($input: [StagedUploadInput!]!) { stagedUploadsCreate(input: $input) { stagedTargets { url resourceUrl parameters { name value } } userErrors { field message } } }`,
      { input: [{ filename: asset.fileName, mimeType: asset.mime, resource: "IMAGE", httpMethod: "POST" }] },
    );
    this.assertNoUserErrors(data.stagedUploadsCreate.userErrors);
    const target = data.stagedUploadsCreate.stagedTargets[0];
    const form = new FormData();
    for (const p of target.parameters) form.append(p.name, p.value);
    form.append("file", new Blob([new Uint8Array(asset.buffer)], { type: asset.mime }), asset.fileName);
    const res = await fetch(target.url, { method: "POST", body: form, signal: AbortSignal.timeout(60_000) });
    if (!res.ok) throw new AdapterError("remote", "L'envoi de la photo vers Shopify a échoué.", `HTTP ${res.status}`);
    return target.resourceUrl;
  }

  /** Photos à ajouter / retirer pour passer de la liste actuelle à la liste voulue. */
  private async mediaChanges(current: ShopifyProduct | null, wanted: string[], ctx: ChangeContext) {
    const existing = current?.media.nodes ?? [];
    const toAdd: { originalSource: string; mediaContentType: "IMAGE"; alt?: string }[] = [];
    for (const value of wanted) {
      if (value.startsWith(MEDIA_TOKEN_PREFIX)) {
        const asset = ctx.assets.find((a) => a.token === value.slice(MEDIA_TOKEN_PREFIX.length));
        if (!asset) throw new AdapterError("invalid", "Une photo n'a pas été retrouvée. Envoyez-la à nouveau.");
        toAdd.push({ originalSource: await this.stageUpload(asset), mediaContentType: "IMAGE", alt: asset.alt });
      } else if (!existing.some((m) => m.image?.url === value)) {
        // Photo d'une ancienne version (annulation) : Shopify la récupère depuis son adresse.
        toAdd.push({ originalSource: value, mediaContentType: "IMAGE" });
      }
    }
    const toRemove = existing.filter((m) => !m.image?.url || !wanted.includes(m.image.url)).map((m) => m.id);
    return { toAdd, toRemove };
  }

  private async location(): Promise<string> {
    if (this.locationId) return this.locationId;
    const data = await this.gql<{ locations: { nodes: { id: string }[] } }>(`{ locations(first: 1) { nodes { id } } }`);
    if (!data.locations.nodes[0]) throw new AdapterError("invalid", "Aucun emplacement de stock n'est configuré sur la boutique.");
    this.locationId = data.locations.nodes[0].id;
    return this.locationId;
  }

  private async applyVariants(productId: string, product: ShopifyProduct, rows: Data[]) {
    const variants = product.variants.nodes;
    const updates = [];
    const stock = [];
    for (const [i, row] of rows.entries()) {
      const variant = variants.find((v) => v.id === row.variantId) ?? variants[i];
      if (!variant) continue;
      const price = row.prix === null || row.prix === undefined ? null : Number(row.prix).toFixed(2);
      const compare = row.prixBarre === null || row.prixBarre === undefined || row.prixBarre === "" ? null : Number(row.prixBarre).toFixed(2);
      if ((price !== null && Number(price) !== Number(variant.price)) || compare !== (variant.compareAtPrice ? Number(variant.compareAtPrice).toFixed(2) : null)) {
        updates.push({ id: variant.id, ...(price !== null ? { price } : {}), compareAtPrice: compare });
      }
      if (row.stock !== null && row.stock !== undefined && row.stock !== "" && variant.inventoryItem && Number(row.stock) !== variant.inventoryQuantity) {
        if (!variant.inventoryItem.tracked) {
          await this.gql(`mutation($id: ID!) { inventoryItemUpdate(id: $id, input: { tracked: true }) { userErrors { message } } }`, { id: variant.inventoryItem.id });
        }
        stock.push({ inventoryItemId: variant.inventoryItem.id, locationId: await this.location(), quantity: Math.max(0, Math.round(Number(row.stock))) });
      }
    }
    if (updates.length) {
      const data = await this.gql<{ productVariantsBulkUpdate: { userErrors: { message: string }[] } }>(
        `mutation($productId: ID!, $variants: [ProductVariantsBulkInput!]!) { productVariantsBulkUpdate(productId: $productId, variants: $variants) { userErrors { field message } } }`,
        { productId, variants: updates },
      );
      this.assertNoUserErrors(data.productVariantsBulkUpdate.userErrors);
    }
    if (stock.length) {
      const data = await this.gql<{ inventorySetQuantities: { userErrors: { message: string }[] } }>(
        `mutation($input: InventorySetQuantitiesInput!) { inventorySetQuantities(input: $input) { userErrors { field message } } }`,
        { input: { name: "available", reason: "correction", ignoreCompareQuantity: true, quantities: stock } },
      );
      this.assertNoUserErrors(data.inventorySetQuantities.userErrors);
    }
  }

  // ---------- Écriture ----------

  async createEntry(section: Section, data: Data, ctx: ChangeContext): Promise<WriteResult> {
    if (section.key === "collections") {
      const res = await this.gql<{ collectionCreate: { collection: { id: string } | null; userErrors: { message: string }[] } }>(
        `mutation($input: CollectionInput!) { collectionCreate(input: $input) { collection { id } userErrors { field message } } }`,
        { input: { title: data.titre, descriptionHtml: data.description ?? "" } },
      );
      this.assertNoUserErrors(res.collectionCreate.userErrors);
      const id = numericId(res.collectionCreate.collection!.id);
      return { id, before: null, after: pickFields(section, data) };
    }
    const { toAdd } = await this.mediaChanges(null, (data.photos as string[]) ?? [], ctx);
    const res = await this.gql<{ productCreate: { product: ShopifyProduct | null; userErrors: { message: string }[] } }>(
      `mutation($product: ProductCreateInput!, $media: [CreateMediaInput!]) { productCreate(product: $product, media: $media) { product { ${PRODUCT_FIELDS} } userErrors { field message } } }`,
      {
        product: { title: data.titre, descriptionHtml: data.description ?? "", status: data.enVente ? "ACTIVE" : "DRAFT" },
        media: toAdd,
      },
    );
    this.assertNoUserErrors(res.productCreate.userErrors);
    const product = res.productCreate.product!;
    await this.applyVariants(product.id, product, ((data.declinaisons as Data[]) ?? []).slice(0, 1));
    const id = numericId(product.id);
    const fresh = await this.fetchProduct(id);
    return { id, before: null, after: pickFields(section, fresh ? this.toData(fresh) : data), ref: product.id };
  }

  async updateEntry(section: Section, id: string, data: Data, expected: Data | null, ctx: ChangeContext): Promise<WriteResult> {
    const currentEntry = await this.getEntry(section, id);
    if (!currentEntry) throw new ConflictError("élément supprimé");
    const comparable = (d: Data) => {
      const { prixAffiche: _p, ...rest } = pickFields(section, d);
      void _p;
      return rest;
    };
    if (expected && !deepEqual(comparable(currentEntry.data), comparable(expected))) throw new ConflictError();
    const before = currentEntry.data;

    if (section.key === "collections") {
      const res = await this.gql<{ collectionUpdate: { userErrors: { message: string }[] } }>(
        `mutation($input: CollectionInput!) { collectionUpdate(input: $input) { userErrors { field message } } }`,
        { input: { id: `gid://shopify/Collection/${id}`, title: data.titre, descriptionHtml: data.description ?? "" } },
      );
      this.assertNoUserErrors(res.collectionUpdate.userErrors);
      return { id, before, after: pickFields(section, { ...before, ...data }) };
    }

    const product = (await this.fetchProduct(id))!;
    const gid = product.id;
    const { toAdd, toRemove } = await this.mediaChanges(product, (data.photos as string[]) ?? [], ctx);
    const res = await this.gql<{ productUpdate: { userErrors: { message: string }[] } }>(
      `mutation($product: ProductUpdateInput!, $media: [CreateMediaInput!]) { productUpdate(product: $product, media: $media) { product { id } userErrors { field message } } }`,
      {
        product: { id: gid, title: data.titre, descriptionHtml: data.description ?? "", status: data.enVente ? "ACTIVE" : "DRAFT" },
        media: toAdd.length ? toAdd : undefined,
      },
    );
    this.assertNoUserErrors(res.productUpdate.userErrors);
    if (toRemove.length) {
      const del = await this.gql<{ productDeleteMedia: { mediaUserErrors: { message: string }[] } }>(
        `mutation($productId: ID!, $mediaIds: [ID!]!) { productDeleteMedia(productId: $productId, mediaIds: $mediaIds) { deletedMediaIds mediaUserErrors { field message } } }`,
        { productId: gid, mediaIds: toRemove },
      );
      this.assertNoUserErrors(del.productDeleteMedia.mediaUserErrors);
    }
    await this.applyVariants(gid, product, (data.declinaisons as Data[]) ?? []);
    const fresh = await this.fetchProduct(id);
    return { id, before, after: fresh ? pickFields(section, this.toData(fresh)) : pickFields(section, { ...before, ...data }), ref: gid };
  }

  async deleteEntry(section: Section, id: string, expected: Data | null): Promise<WriteResult> {
    const current = await this.getEntry(section, id);
    if (!current) throw new ConflictError("élément déjà supprimé");
    if (section.key === "collections") {
      throw new AdapterError("unsupported", "La suppression des collections se fait depuis Shopify.");
    }
    if (expected && !deepEqual(current.data.titre, expected.titre)) throw new ConflictError();
    const res = await this.gql<{ productDelete: { userErrors: { message: string }[] } }>(
      `mutation($input: ProductDeleteInput!) { productDelete(input: $input) { deletedProductId userErrors { field message } } }`,
      { input: { id: `gid://shopify/Product/${id}` } },
    );
    this.assertNoUserErrors(res.productDelete.userErrors);
    return { id, before: current.data, after: null };
  }
}

export function hasPendingMedia(data: Data): boolean {
  return collectMediaTokens(data).size > 0;
}
