import YAML from "yaml";
import matter from "gray-matter";

/**
 * Lecture / écriture des fichiers de contenu en conservant le style du fichier d'origine
 * (indentation JSON, commentaires YAML hors des zones modifiées, retour à la ligne final).
 */

export type FileFormat = "json" | "yaml" | "markdown";

export function formatFromPath(path: string): FileFormat | null {
  const lower = path.toLowerCase();
  if (lower.endsWith(".json")) return "json";
  if (lower.endsWith(".yml") || lower.endsWith(".yaml")) return "yaml";
  if (lower.endsWith(".md") || lower.endsWith(".mdx") || lower.endsWith(".markdown")) return "markdown";
  return null;
}

export interface ParsedFile {
  format: FileFormat;
  data: unknown;
  /** Markdown : corps du fichier sous l'en-tête. */
  body?: string;
  style: { indent: string | number; finalNewline: boolean; bom: boolean };
  yamlDoc?: YAML.Document;
}

export function parseFile(text: string, format: FileFormat): ParsedFile {
  const bom = text.charCodeAt(0) === 0xfeff;
  const source = bom ? text.slice(1) : text;
  const finalNewline = source.endsWith("\n");
  if (format === "json") {
    const indentMatch = source.match(/^[\[{]\s*\n([ \t]+)/);
    const indent = indentMatch ? (indentMatch[1].includes("\t") ? "\t" : indentMatch[1].length) : 2;
    return { format, data: source.trim() ? JSON.parse(source) : null, style: { indent, finalNewline, bom } };
  }
  if (format === "yaml") {
    const doc = YAML.parseDocument(source);
    if (doc.errors.length) throw new Error(`YAML invalide : ${doc.errors[0].message}`);
    return { format, data: doc.toJS(), yamlDoc: doc, style: { indent: 2, finalNewline, bom } };
  }
  const parsed = matter(source);
  return { format, data: parsed.data ?? {}, body: parsed.content.replace(/^\n/, ""), style: { indent: 2, finalNewline, bom } };
}

export function serializeFile(parsed: ParsedFile, data: unknown, body?: string): string {
  let out: string;
  if (parsed.format === "json") {
    out = JSON.stringify(data, null, parsed.style.indent);
    if (parsed.style.finalNewline) out += "\n";
  } else if (parsed.format === "yaml") {
    const doc = parsed.yamlDoc ?? new YAML.Document();
    replaceYamlContents(doc, data);
    out = doc.toString({ lineWidth: 0 });
  } else {
    out = matter.stringify(`\n${body ?? parsed.body ?? ""}`.replace(/^\n\n/, "\n"), (data ?? {}) as Record<string, unknown>);
  }
  return (parsed.style.bom ? "﻿" : "") + out;
}

export function newFile(format: FileFormat, data: unknown, body?: string): string {
  if (format === "json") return JSON.stringify(data, null, 2) + "\n";
  if (format === "yaml") return YAML.stringify(data, { lineWidth: 0 });
  return matter.stringify(`\n${body ?? ""}`, (data ?? {}) as Record<string, unknown>);
}

/** Remplace le contenu d'un document YAML clé par clé, pour garder les commentaires des zones non touchées. */
function replaceYamlContents(doc: YAML.Document, data: unknown) {
  if (!data || typeof data !== "object" || Array.isArray(data) || !YAML.isMap(doc.contents)) {
    doc.contents = doc.createNode(data) as typeof doc.contents;
    return;
  }
  const map = doc.contents;
  const next = data as Record<string, unknown>;
  for (const item of [...map.items]) {
    const key = YAML.isScalar(item.key) ? String(item.key.value) : String(item.key);
    if (!(key in next)) map.delete(key);
  }
  const current = doc.toJS() as Record<string, unknown>;
  for (const [key, value] of Object.entries(next)) {
    if (JSON.stringify(current?.[key]) !== JSON.stringify(value)) doc.set(key, doc.createNode(value));
  }
}
