import { describe, expect, it } from "vitest";
import sharp from "sharp";
import { processUpload, UploadError } from "@/lib/media";

describe("photos", () => {
  it("réencode en WebP, redimensionne et retire les métadonnées", async () => {
    const jpeg = await sharp({ create: { width: 3000, height: 2000, channels: 3, background: "#c84" } }).jpeg().withMetadata({ exif: { IFD0: { Copyright: "x" } } }).toBuffer();
    const out = await processUpload(jpeg, { originalName: "Tarte Citron.JPG", maxWidth: 1600 });
    const meta = await sharp(out.buffer).metadata();
    expect(meta.format).toBe("webp");
    expect(meta.width).toBe(1600);
    expect(meta.exif).toBeUndefined();
    expect(out.fileName).toMatch(/^tarte-citron-[0-9a-f]{8}\.webp$/);
  });
  it("refuse ce qui n'est pas une image, même renommé", async () => {
    await expect(processUpload(Buffer.from("<svg onload=alert(1)></svg>"), { originalName: "x.png" })).rejects.toBeInstanceOf(UploadError);
    await expect(processUpload(Buffer.from("%PDF-1.4 ............"), { originalName: "x.jpg" })).rejects.toBeInstanceOf(UploadError);
  });
});
