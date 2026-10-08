import "server-only";

const SECRET_KEY = /token|password|passwd|secret|authorization|private.?key|passphrase|api.?key|consumer.?key|cookie/i;
const SECRET_VALUE = /\b(ghp_|github_pat_|gho_|glpat-|shpat_|shpca_|shppa_|sk_live_|sk_test_)[A-Za-z0-9_\-]+/g;

/** Masque récursivement toute valeur qui ressemble à un secret. */
export function redact(value: unknown, depth = 0): unknown {
  if (depth > 6) return "[…]";
  if (typeof value === "string") return value.replace(SECRET_VALUE, (m) => `${m.slice(0, 4)}…[masqué]`);
  if (Array.isArray(value)) return value.map((v) => redact(v, depth + 1));
  if (value instanceof Error) return { name: value.name, message: redact(value.message, depth + 1) };
  if (value && typeof value === "object") {
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(value)) {
      out[k] = SECRET_KEY.test(k) ? "[masqué]" : redact(v, depth + 1);
    }
    return out;
  }
  return value;
}

export const log = {
  info(message: string, data?: unknown) {
    console.info(`[simplecommerce] ${message}`, data === undefined ? "" : JSON.stringify(redact(data)));
  },
  warn(message: string, data?: unknown) {
    console.warn(`[simplecommerce] ${message}`, data === undefined ? "" : JSON.stringify(redact(data)));
  },
  error(message: string, data?: unknown) {
    console.error(`[simplecommerce] ${message}`, data === undefined ? "" : JSON.stringify(redact(data)));
  },
};
