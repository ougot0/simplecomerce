import { describe, expect, it } from "vitest";
import { assertPublicUrl, isPrivateAddress } from "@/lib/adapters/net";

describe("protection du réseau interne", () => {
  it("reconnaît les adresses privées", () => {
    for (const ip of ["127.0.0.1", "10.1.2.3", "192.168.1.1", "169.254.169.254", "172.16.0.1", "::1", "fd00::1", "::ffff:127.0.0.1"]) expect(isPrivateAddress(ip)).toBe(true);
    expect(isPrivateAddress("93.184.216.34")).toBe(false);
  });
  it("refuse localhost, http et les identifiants dans l'URL", async () => {
    await expect(assertPublicUrl("https://localhost/x")).rejects.toThrow();
    await expect(assertPublicUrl("https://169.254.169.254/latest")).rejects.toThrow();
    await expect(assertPublicUrl("http://exemple.fr")).rejects.toThrow();
    await expect(assertPublicUrl("https://user:pw@1.1.1.1")).rejects.toThrow();
  });
});
