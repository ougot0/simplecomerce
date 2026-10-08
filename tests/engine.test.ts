import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { mkdtemp, readFile, rm, writeFile, mkdir, readdir } from "node:fs/promises";
import { tmpdir } from "node:os";
import path from "node:path";
import { FileSiteAdapter } from "@/lib/adapters/files/engine";
import { LocalBackend } from "@/lib/adapters/files/local";
import { ConflictError, type ChangeContext } from "@/lib/adapters/types";
import { inverseOperation } from "@/lib/changes";
import type { Change } from "@/lib/store/types";

let dir: string;
const ctx = (assets: ChangeContext["assets"] = []): ChangeContext => ({ authorName: "Test", summary: "test", changeId: "c1", assets });
const original = {
  gateaux: [
    { id: 1, nom: "Tarte", prix: "4,50 €", extra: "conservé" },
    { id: 2, nom: "Éclair", prix: "3,80 €" },
  ],
  infos: { telephone: "01", horaires: [{ jour: "Lundi", heures: "Fermé" }] },
};

async function adapter() {
  const a = new FileSiteAdapter(new LocalBackend(dir), { contentDir: "auto", media: { dir: "auto", publicPrefix: "/images/simplecommerce" }, publicUrl: "https://exemple.fr", webRoot: true });
  const { schema } = await a.discover();
  return { a, schema, section: (k: string) => schema.sections.find((s) => s.key === k)! };
}
const read = async () => JSON.parse(await readFile(path.join(dir, "content.json"), "utf8"));

beforeEach(async () => {
  dir = await mkdtemp(path.join(tmpdir(), "sc-"));
  await writeFile(path.join(dir, "content.json"), JSON.stringify(original, null, 4) + "\n");
});
afterEach(() => rm(dir, { recursive: true, force: true }));

describe("moteur de fichiers", () => {
  it("modifie un élément sans toucher au reste ni au style du fichier", async () => {
    const { a, section } = await adapter();
    const s = section("gateaux");
    const before = (await a.getEntry(s, "1"))!.data;
    const res = await a.updateEntry(s, "1", { ...before, prix: "4,80 €" }, before, ctx());
    expect(res.before?.prix).toBe("4,50 €");
    const file = await readFile(path.join(dir, "content.json"), "utf8");
    expect(file).toContain('    "gateaux"'); // indentation de 4 conservée
    const json = await read();
    expect(json.gateaux[0]).toEqual({ id: 1, nom: "Tarte", prix: "4,80 €", extra: "conservé" });
    expect(json.infos).toEqual(original.infos);
  });

  it("refuse d'écraser une version modifiée entre-temps", async () => {
    const { a, section } = await adapter();
    const s = section("gateaux");
    await expect(a.updateEntry(s, "1", { prix: "1 €" }, { nom: "Autre", prix: "9 €" }, ctx())).rejects.toBeInstanceOf(ConflictError);
  });

  it("ajoute avec un identifiant du même type, réordonne, supprime", async () => {
    const { a, section } = await adapter();
    const s = section("gateaux");
    const created = await a.createEntry(s, { nom: "Paris-Brest", prix: "5 €" }, ctx());
    expect(created.id).toBe("3");
    expect((await read()).gateaux[2].id).toBe(3);
    await a.reorder(s, ["3", "1", "2"], ctx());
    expect((await read()).gateaux.map((g: { id: number }) => g.id)).toEqual([3, 1, 2]);
    await a.deleteEntry(s, "1", null, ctx());
    expect((await read()).gateaux.map((g: { id: number }) => g.id)).toEqual([3, 2]);
  });

  it("écrit la photo et le contenu ensemble", async () => {
    const { a, section } = await adapter();
    const s = section("gateaux");
    await a.updateEntry(s, "2", { photo: "sc-media:abc" }, null, ctx([{ token: "abc", buffer: Buffer.from("img"), fileName: "eclair.webp", mime: "image/webp" }]));
    expect((await read()).gateaux[1].photo).toBe("/images/simplecommerce/eclair.webp");
    expect(await readdir(path.join(dir, "images", "simplecommerce"))).toEqual(["eclair.webp"]);
  });

  it("met à jour un bloc unique", async () => {
    const { a, section } = await adapter();
    const s = section("infos");
    const cur = await a.getSingleton(s);
    await a.updateSingleton(s, { ...cur, telephone: "02" }, cur, ctx());
    expect((await read()).infos.telephone).toBe("02");
  });

  it("calcule l'annulation d'un réordonnancement sans identifiants", async () => {
    await writeFile(path.join(dir, "content.json"), JSON.stringify({ liste: [{ nom: "A", t: "x" }, { nom: "B", t: "y" }, { nom: "C", t: "z" }] }));
    const { a, section } = await adapter();
    const s = section("liste");
    const r = await a.reorder(s, ["n2", "n0", "n1"], ctx());
    expect((await read()).liste.map((x: { nom: string }) => x.nom)).toEqual(["C", "A", "B"]);
    const change = { status: "applied", revertedByChangeId: null, beforeOrder: r.beforeOrder, afterOrder: ["n2", "n0", "n1"], before: null, after: null } as unknown as Change;
    const inv = inverseOperation(change, s);
    expect(inv?.type).toBe("reorder");
    await a.reorder(s, (inv as { ids: string[] }).ids, ctx());
    expect((await read()).liste.map((x: { nom: string }) => x.nom)).toEqual(["A", "B", "C"]);
  });

  it("gère un dossier Markdown", async () => {
    await mkdir(path.join(dir, "content", "projets"), { recursive: true });
    await writeFile(path.join(dir, "content", "projets", "a.md"), "---\ntitre: A\nordre: 1\n---\nTexte A\n");
    await writeFile(path.join(dir, "content", "projets", "b.md"), "---\ntitre: B\nordre: 2\n---\nTexte B\n");
    const { a, section } = await adapter();
    const s = section("projets");
    const created = await a.createEntry(s, { titre: "Halle", body: "Texte **gras**" }, ctx());
    expect(created.id).toBe("halle");
    const file = await readFile(path.join(dir, "content", "projets", "halle.md"), "utf8");
    expect(file).toMatch(/titre: Halle/);
    expect(file).toMatch(/ordre: 3/);
    // Retours à la ligne finaux ignorés : l'annulation (suppression) passe le contrôle.
    await a.deleteEntry(s, "halle", created.after, ctx());
    expect((await a.listEntries(s)).map((e) => e.id)).toEqual(["a", "b"]);
  });

  it("refuse les chemins qui sortent du site", async () => {
    const { a, schema } = await adapter();
    const evil = { ...schema.sections[0], source: { type: "file" as const, file: "../../etc/passwd" } };
    await expect(a.listEntries(evil)).rejects.toThrow();
  });
});
