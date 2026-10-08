"use client";

import { useRef, useState } from "react";
import Cropper, { type Area } from "react-easy-crop";
import type { Field } from "@/lib/content/schema";
import { imageSrc } from "@/lib/ui/format";
import { uploadImageAction } from "@/app/s/[site]/actions";

const MAX_INPUT_BYTES = 30 * 1024 * 1024;

function aspectOf(field: Field, natural: number): number {
  if (!field.aspect || field.aspect === "libre") return natural;
  const [w, h] = field.aspect.split(":").map(Number);
  return w && h ? w / h : natural;
}

async function loadImage(src: string): Promise<HTMLImageElement> {
  const img = new Image();
  img.src = src;
  await img.decode();
  return img;
}

/** Recadre et compresse dans le navigateur : une photo de téléphone de 6 Mo devient ~300 Ko. */
async function cropToBlob(src: string, area: Area, maxWidth: number): Promise<Blob> {
  const img = await loadImage(src);
  const scale = Math.min(1, maxWidth / area.width);
  const canvas = document.createElement("canvas");
  canvas.width = Math.round(area.width * scale);
  canvas.height = Math.round(area.height * scale);
  const ctx = canvas.getContext("2d")!;
  ctx.imageSmoothingQuality = "high";
  ctx.drawImage(img, area.x, area.y, area.width, area.height, 0, 0, canvas.width, canvas.height);
  for (const [type, quality] of [["image/webp", 0.85], ["image/jpeg", 0.85], ["image/jpeg", 0.7]] as const) {
    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, type, quality));
    if (blob && blob.type === type && blob.size < 5 * 1024 * 1024) return blob;
  }
  throw new Error("Photo trop lourde, même compressée.");
}

function useUpload(site: string, field: Field) {
  const [pending, setPending] = useState<{ src: string; name: string; natural: number } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const pick = () => inputRef.current?.click();
  const onFile = async (file: File | undefined) => {
    setError(null);
    if (!file) return;
    if (!/^image\/(jpeg|png|webp|heic|heif)$/i.test(file.type) && !/\.(jpe?g|png|webp|heic)$/i.test(file.name)) {
      setError("Choisissez une photo (JPEG, PNG ou WebP).");
      return;
    }
    if (file.size > MAX_INPUT_BYTES) {
      setError("Cette photo est trop lourde (30 Mo maximum).");
      return;
    }
    const src = URL.createObjectURL(file);
    try {
      const img = await loadImage(src);
      setPending({ src, name: file.name, natural: img.naturalWidth / img.naturalHeight });
    } catch {
      setError("Ce fichier ne peut pas être lu. Sur iPhone, choisissez « Le plus compatible » dans Réglages → Appareil photo → Formats.");
    }
    if (inputRef.current) inputRef.current.value = "";
  };

  const upload = async (area: Area, alt: string): Promise<{ token: string; url: string } | null> => {
    if (!pending) return null;
    setBusy(true);
    try {
      const blob = await cropToBlob(pending.src, area, field.maxWidth ?? 1600);
      const form = new FormData();
      form.set("site", site);
      form.set("file", new File([blob], pending.name.replace(/\.[^.]+$/, "") + (blob.type === "image/webp" ? ".webp" : ".jpg"), { type: blob.type }));
      form.set("alt", alt);
      form.set("maxWidth", String(field.maxWidth ?? 1600));
      const res = await uploadImageAction(form);
      if (res.error || !res.token) {
        setError(res.error ?? "Envoi impossible.");
        return null;
      }
      URL.revokeObjectURL(pending.src);
      setPending(null);
      return { token: res.token, url: res.url! };
    } catch (err) {
      setError(err instanceof Error ? err.message : "Envoi impossible.");
      return null;
    } finally {
      setBusy(false);
    }
  };

  const input = (
    <input ref={inputRef} type="file" accept="image/jpeg,image/png,image/webp,image/heic" hidden onChange={(e) => onFile(e.target.files?.[0])} />
  );
  return { pending, setPending, error, busy, pick, input, upload };
}

function CropDialog({ src, aspect, busy, onCancel, onConfirm }: { src: string; aspect: number; busy: boolean; onCancel: () => void; onConfirm: (area: Area, alt: string) => void }) {
  const [crop, setCrop] = useState({ x: 0, y: 0 });
  const [zoom, setZoom] = useState(1);
  const [area, setArea] = useState<Area | null>(null);
  const [alt, setAlt] = useState("");
  return (
    <div className="dialog" role="dialog" aria-modal="true" aria-labelledby="crop-title">
      <div className="dialog-box">
        <div className="dialog-head">
          <h2 id="crop-title">Cadrer la photo</h2>
          <span className="muted small">Faites glisser pour déplacer, utilisez le curseur pour zoomer.</span>
        </div>
        <div className="crop-area">
          <Cropper image={src} crop={crop} zoom={zoom} aspect={aspect} onCropChange={setCrop} onZoomChange={setZoom} onCropComplete={(_, px) => setArea(px)} />
        </div>
        <div className="dialog-foot" style={{ display: "grid", gap: 14, justifyContent: "stretch" }}>
          <label className="field">
            <span className="label">Zoom</span>
            <input type="range" min={1} max={3} step={0.05} value={zoom} onChange={(e) => setZoom(Number(e.target.value))} />
          </label>
          <label className="field">
            <span className="label">
              Que montre la photo ? <span className="optional">(facultatif)</span>
            </span>
            <input type="text" value={alt} maxLength={200} onChange={(e) => setAlt(e.target.value)} placeholder="Tarte au citron meringuée vue de dessus" />
            <span className="help">Lu aux personnes malvoyantes, et utile pour Google.</span>
          </label>
          <div className="actions">
            <button type="button" className="btn btn-primary" disabled={!area || busy} onClick={() => area && onConfirm(area, alt)}>
              {busy ? "Envoi…" : "Utiliser cette photo"}
            </button>
            <button type="button" className="btn btn-quiet" onClick={onCancel} disabled={busy}>
              Annuler
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

function Frame({ src }: { src: string | null }) {
  const [broken, setBroken] = useState(false);
  return (
    <span className="frame">
      {src && !broken ? <img src={src} alt="" onError={() => setBroken(true)} /> : <span>{src ? "Photo bientôt visible (site en cours de mise à jour)" : "Aucune photo"}</span>}
    </span>
  );
}

export function ImageInput({ id, field, value, onChange, site, assetBase }: { id: string; field: Field; value: string; onChange: (v: unknown) => void; site: string; assetBase: string }) {
  const up = useUpload(site, field);
  return (
    <div className="image-field" id={id}>
      <Frame key={value} src={imageSrc(value, assetBase)} />
      <div style={{ display: "grid", gap: 8 }}>
        <div className="actions">
          <button type="button" className="btn btn-small" onClick={up.pick}>
            {value ? "Changer la photo" : "Choisir une photo"}
          </button>
          {value && (
            <button type="button" className="btn btn-small btn-quiet" onClick={() => onChange("")}>
              Retirer
            </button>
          )}
        </div>
        <span className="help">JPEG, PNG ou WebP. Elle sera recadrée et allégée automatiquement.</span>
        {up.error && <span className="error-text">{up.error}</span>}
      </div>
      {up.input}
      {up.pending && (
        <CropDialog
          src={up.pending.src}
          aspect={aspectOf(field, up.pending.natural)}
          busy={up.busy}
          onCancel={() => up.setPending(null)}
          onConfirm={async (area, alt) => {
            const res = await up.upload(area, alt);
            if (res) onChange(res.token);
          }}
        />
      )}
    </div>
  );
}

export function GalleryInput({ field, value, onChange, site, assetBase }: { field: Field; value: string[]; onChange: (v: unknown) => void; site: string; assetBase: string }) {
  const up = useUpload(site, field);
  const move = (i: number, d: number) => {
    const j = i + d;
    if (j < 0 || j >= value.length) return;
    const next = [...value];
    [next[i], next[j]] = [next[j], next[i]];
    onChange(next);
  };
  return (
    <div style={{ display: "grid", gap: 10 }}>
      <div className="gallery">
        {value.map((v, i) => (
          <div className="gallery-item" key={`${v}-${i}`}>
            <Frame src={imageSrc(v, assetBase)} />
            <div className="actions">
              <button type="button" className="btn btn-small btn-quiet" onClick={() => move(i, -1)} disabled={i === 0} aria-label="Avancer">
                Avant
              </button>
              <button type="button" className="btn btn-small btn-quiet" onClick={() => move(i, 1)} disabled={i === value.length - 1} aria-label="Reculer">
                Après
              </button>
              <button type="button" className="btn btn-small btn-quiet" onClick={() => onChange(value.filter((_, j) => j !== i))}>
                Retirer
              </button>
            </div>
            {i === 0 && <span className="help">Photo principale</span>}
          </div>
        ))}
      </div>
      <div>
        <button type="button" className="btn btn-small" onClick={up.pick}>
          Ajouter une photo
        </button>
      </div>
      {up.error && <span className="error-text">{up.error}</span>}
      {up.input}
      {up.pending && (
        <CropDialog
          src={up.pending.src}
          aspect={aspectOf(field, up.pending.natural)}
          busy={up.busy}
          onCancel={() => up.setPending(null)}
          onConfirm={async (area, alt) => {
            const res = await up.upload(area, alt);
            if (res) onChange([...value, res.token]);
          }}
        />
      )}
    </div>
  );
}
