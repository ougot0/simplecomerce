import { beforeAll, describe, expect, it } from "vitest";
import { randomBytes } from "node:crypto";

beforeAll(() => {
  process.env.CREDENTIALS_KEY_V1 = randomBytes(32).toString("base64");
});

describe("coffre des identifiants", async () => {
  const { seal, unseal, fingerprint } = await import("@/lib/vault");

  it("chiffre et déchiffre", () => {
    const sealed = seal({ token: "ghp_secret123456" }, "site-a");
    expect(sealed.ciphertext).not.toContain("ghp_");
    expect(unseal(sealed, "site-a")).toEqual({ token: "ghp_secret123456" });
  });

  it("refuse un secret recopié sur un autre site", () => {
    const sealed = seal({ token: "x".repeat(20) }, "site-a");
    expect(() => unseal(sealed, "site-b")).toThrow();
  });

  it("détecte une altération", () => {
    const sealed = seal({ token: "x".repeat(20) }, "site-a");
    const tampered = { ...sealed, ciphertext: Buffer.from("autre chose").toString("base64") };
    expect(() => unseal(tampered, "site-a")).toThrow();
  });

  it("n'affiche que 4 caractères", () => {
    expect(fingerprint({ token: "shpat_abcdefgh1234" })).toBe("…1234");
  });
});
