import { describe, expect, it } from "vitest";
import { redact } from "@/lib/log";

describe("journal", () => {
  it("masque les secrets par nom de clé et par forme", () => {
    const out = redact({ password: "hunter2", nested: { accessToken: "abc" }, message: "jeton ghp_ABCDEFGHIJKLMNOP refusé" }) as Record<string, unknown>;
    expect(out.password).toBe("[masqué]");
    expect((out.nested as Record<string, unknown>).accessToken).toBe("[masqué]");
    expect(String(out.message)).not.toContain("ABCDEFGHIJKLMNOP");
  });
});
