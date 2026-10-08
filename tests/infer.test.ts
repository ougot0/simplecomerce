import { describe, expect, it } from "vitest";
import { inferSchema } from "@/lib/content/infer";

describe("détection automatique du contenu", () => {
  it("reconnaît listes et blocs dans un content.json", () => {
    const text = JSON.stringify({
      gateaux: [
        { id: 1, nom: "Tarte", prix: "4,50 €", photo: "/images/t.webp", disponible: true },
        { id: 2, nom: "Éclair", prix: "3,80 €", photo: "/images/e.webp", disponible: false },
      ],
      contact: { telephone: "04 78 00 00 00", email: "a@b.fr" },
      titre: "Pâtisserie",
    });
    const { schema } = inferSchema([{ path: "content.json", text }]);
    const gateaux = schema.sections.find((s) => s.key === "gateaux")!;
    expect(gateaux.kind).toBe("collection");
    expect(gateaux.label).toBe("Gâteaux");
    expect(gateaux.idField).toBe("id");
    expect(gateaux.titleField).toBe("nom");
    expect(gateaux.fields.find((f) => f.key === "prix")).toMatchObject({ type: "price", priceFormat: { store: "string", decimal: ",", suffix: " €" } });
    expect(gateaux.fields.find((f) => f.key === "photo")?.type).toBe("image");
    expect(gateaux.fields.find((f) => f.key === "id")?.hidden).toBe(true);
    expect(schema.sections.find((s) => s.key === "contact")?.kind).toBe("singleton");
    expect(schema.sections.find((s) => s.key === "content")?.fields.map((f) => f.key)).toEqual(["titre"]);
  });

  it("reconnaît un dossier Markdown (Hugo, Astro, Jekyll)", () => {
    const md = (t: string, o: number) => `---\ntitre: ${t}\nordre: ${o}\nphoto: /img/${o}.jpg\n---\nTexte de ${t}\n`;
    const { schema } = inferSchema([
      { path: "projets/a.md", text: md("A", 1) },
      { path: "projets/b.md", text: md("B", 2) },
    ]);
    const s = schema.sections[0];
    expect(s.source).toMatchObject({ type: "folder", folder: "projets", format: "markdown", orderField: "ordre" });
    expect(s.fields.find((f) => f.key === "body")?.type).toBe("markdown");
  });

  it("lit le YAML et ignore les fichiers techniques", () => {
    const { schema, skipped } = inferSchema([
      { path: "site.yml", text: "nom: Atelier\nemail: a@b.fr\n" },
      { path: "package.json", text: "{}" },
    ]);
    expect(schema.sections).toHaveLength(1);
    expect(skipped).toContain("package.json");
  });
});
