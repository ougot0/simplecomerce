import type { Field } from "./schema";

/** Conversions de prix partagées entre le navigateur et le serveur. */

export function priceToNumber(field: Field, stored: unknown): number | null {
  const fmt = field.priceFormat ?? { store: "number" };
  if (stored === null || stored === undefined || stored === "") return null;
  if (typeof stored === "number") return fmt.store === "cents" ? stored / 100 : stored;
  if (typeof stored === "string") return parseDecimal(stored);
  return null;
}

export function numberToStored(field: Field, value: number | null): unknown {
  const fmt = field.priceFormat ?? { store: "number" };
  if (value === null) return fmt.store === "string" ? "" : null;
  const rounded = Math.round(value * 100) / 100;
  if (fmt.store === "cents") return Math.round(rounded * 100);
  if (fmt.store === "number") return rounded;
  const decimals = Number.isInteger(rounded) && !fmt.decimal ? 0 : 2;
  let text = rounded.toFixed(decimals);
  if (fmt.decimal === ",") text = text.replace(".", ",");
  return `${fmt.prefix ?? ""}${text}${fmt.suffix ?? ""}`;
}

/** « 4,50 € », « 4.5 », « 1 200,00 » → nombre. */
export function parseDecimal(text: string): number | null {
  const cleaned = text.replace(/[^\d,.\-]/g, "");
  if (!cleaned) return null;
  const lastComma = cleaned.lastIndexOf(",");
  const lastDot = cleaned.lastIndexOf(".");
  let normalized = cleaned;
  if (lastComma > lastDot) normalized = cleaned.replace(/\./g, "").replace(",", ".");
  else normalized = cleaned.replace(/,/g, "");
  const value = Number(normalized);
  return Number.isFinite(value) ? value : null;
}

export function formatPriceForDisplay(value: number | null, currency = "€"): string {
  if (value === null) return "";
  const text = value.toLocaleString("fr-FR", { minimumFractionDigits: Number.isInteger(value) ? 0 : 2, maximumFractionDigits: 2 });
  return `${text} ${currency}`;
}

/** Devine le format d'un prix existant pour réécrire dans le même style. */
export function detectPriceFormat(samples: unknown[]): NonNullable<Field["priceFormat"]> {
  const first = samples.find((s) => s !== null && s !== undefined && s !== "");
  if (typeof first === "number") {
    const allIntegers = samples.every((s) => typeof s !== "number" || Number.isInteger(s));
    const looksLikeCents = allIntegers && samples.some((s) => typeof s === "number" && s >= 100) &&
      samples.every((s) => typeof s !== "number" || s % 5 === 0);
    return { store: looksLikeCents ? "cents" : "number" };
  }
  if (typeof first === "string") {
    const m = first.match(/^(\D*?)\s*([\d\s.,]+?)\s*(\D*)$/);
    return {
      store: "string",
      decimal: first.includes(",") ? "," : ".",
      prefix: m?.[1] ? m[1] : undefined,
      suffix: m?.[3] ? (first.includes(` ${m[3]}`) ? ` ${m[3]}` : m[3]) : undefined,
    };
  }
  return { store: "number" };
}
