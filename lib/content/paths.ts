/** Accès par chemin « a.b.c » dans un objet, sans jamais muter l'original. */

export function splitPath(path: string | undefined): string[] {
  return path ? path.split(".").filter(Boolean) : [];
}

export function getAt(root: unknown, path: string | undefined): unknown {
  let cur = root;
  for (const part of splitPath(path)) {
    if (cur === null || typeof cur !== "object") return undefined;
    cur = (cur as Record<string, unknown>)[part];
  }
  return cur;
}

export function setAt(root: unknown, path: string | undefined, value: unknown): unknown {
  const parts = splitPath(path);
  if (parts.length === 0) return value;
  const [head, ...rest] = parts;
  const base = root && typeof root === "object" && !Array.isArray(root) ? (root as Record<string, unknown>) : {};
  if (["__proto__", "constructor", "prototype"].includes(head)) throw new Error("Chemin interdit");
  return { ...base, [head]: setAt(base[head], rest.join("."), value) };
}

/** Joint des chemins relatifs en refusant toute sortie du dossier racine. */
export function safeJoin(...parts: string[]): string {
  const segments: string[] = [];
  for (const part of parts) {
    for (const seg of part.split(/[\\/]+/)) {
      if (!seg || seg === ".") continue;
      if (seg === ".." || seg.includes("\0")) throw new Error("Chemin interdit");
      segments.push(seg);
    }
  }
  return segments.join("/");
}

export function slugify(text: string): string {
  return (
    text
      .normalize("NFD")
      .replace(/[̀-ͯ]/g, "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "")
      .slice(0, 60) || "element"
  );
}
