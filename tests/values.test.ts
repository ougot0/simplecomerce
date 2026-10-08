import { describe, expect, it } from "vitest";
import { normalizeData } from "@/lib/content/values";
import { numberToStored, parseDecimal, priceToNumber } from "@/lib/content/price";
import type { Field } from "@/lib/content/schema";

const opts = { sanitizeHtml: (h: string) => h.replace(/<script.*?<\/script>/g, "") };

describe("validation des champs", () => {
  const fields: Field[] = [
    { key: "nom", label: "Nom", type: "text", required: true, maxLength: 10 },
    { key: "secret", label: "Réf", type: "text", hidden: true },
    { key: "site", label: "Lien", type: "url" },
    { key: "photo", label: "Photo", type: "image" },
  ];

  it("conserve les champs masqués et inconnus, ignore ceux envoyés par le navigateur", () => {
    const { data, errors } = normalizeData(fields, { nom: "Tarte", secret: "piraté", autre: 1 }, { nom: "x", secret: "garde", inconnu: true }, opts);
    expect(errors).toEqual({});
    expect(data).toEqual({ nom: "Tarte", secret: "garde", inconnu: true });
  });

  it("signale les erreurs en français", () => {
    const { errors } = normalizeData(fields, { nom: "beaucoup trop long", site: "javascript:alert(1)", photo: "javascript:alert(1)" }, {}, opts);
    expect(errors.nom).toMatch(/10 caractères/);
    expect(errors.site).toBeDefined();
    expect(errors.photo).toBeDefined();
  });

  it("garde les données cachées des lignes répétées et bloque l'ajout si lignes fixes", () => {
    const rep: Field[] = [{ key: "rows", label: "Prix", type: "repeater", fixedRows: true, fields: [{ key: "prix", label: "Prix", type: "price" }, { key: "variantId", label: "id", type: "text", hidden: true }] }];
    const current = { rows: [{ prix: 1, variantId: "gid://1" }] };
    const ok = normalizeData(rep, { rows: [{ __index: 0, prix: 2, variantId: "gid://autre" }] }, current, opts);
    expect(ok.data.rows).toEqual([{ prix: 2, variantId: "gid://1" }]);
    const ko = normalizeData(rep, { rows: [{ prix: 2 }, { prix: 3 }] }, current, opts);
    expect(ko.errors.rows).toBeDefined();
  });
});

describe("prix", () => {
  it("lit et réécrit dans le format du site", () => {
    const field: Field = { key: "p", label: "Prix", type: "price", priceFormat: { store: "string", decimal: ",", suffix: " €" } };
    expect(priceToNumber(field, "4,50 €")).toBe(4.5);
    expect(numberToStored(field, 4.8)).toBe("4,80 €");
    const cents: Field = { key: "p", label: "Prix", type: "price", priceFormat: { store: "cents" } };
    expect(numberToStored(cents, 12.5)).toBe(1250);
    expect(parseDecimal("1 200,50")).toBe(1200.5);
  });
});
