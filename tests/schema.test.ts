import { describe, expect, it } from "vitest";
import { parseContentSchema } from "@/lib/content/schema";

describe("schéma de contenu", () => {
  it("refuse les chemins dangereux et les doublons", () => {
    const section = { key: "a", label: "A", kind: "singleton", fields: [{ key: "x", label: "X", type: "text" }] };
    expect(() => parseContentSchema({ version: 1, sections: [{ ...section, source: { type: "file", file: "../secret.json" } }] })).toThrow();
    expect(() => parseContentSchema({ version: 1, sections: [section, section] })).toThrow();
    expect(parseContentSchema({ version: 1, sections: [section] }).sections).toHaveLength(1);
  });
});
