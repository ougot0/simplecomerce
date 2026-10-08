// Génère les illustrations des sites de démonstration (dessins simples, pas de fausses photos).
import sharp from "sharp";
import { mkdirSync } from "node:fs";

const W = 800, H = 600;
const bg = (c) => `<rect width="${W}" height="${H}" fill="${c}"/>`;
const table = `<rect y="430" width="${W}" height="170" fill="#d9cdb6"/><rect y="430" width="${W}" height="3" fill="#c4b597"/>`;
const plate = (cx = 400, cy = 455, rx = 230) => `<ellipse cx="${cx}" cy="${cy}" rx="${rx}" ry="${rx * 0.2}" fill="#f6f1e7"/><ellipse cx="${cx}" cy="${cy - 4}" rx="${rx * 0.82}" ry="${rx * 0.15}" fill="#ece5d6"/>`;

const pastry = {
  "tarte-citron": `${bg("#e9dcc3")}${table}${plate()}
    <path d="M220 430 Q400 470 580 430 L560 395 Q400 425 240 395 Z" fill="#c98a3e"/>
    <ellipse cx="400" cy="395" rx="160" ry="34" fill="#efce4a"/>
    <path d="M270 390 q20 -40 40 0 q20 -45 40 0 q20 -50 40 0 q20 -45 40 0 q20 -40 40 0 q15 -30 30 0" fill="#f7efe0" stroke="#d9a36a" stroke-width="3"/>`,
  "eclair-cafe": `${bg("#e3d6c0")}${table}${plate()}
    <rect x="230" y="385" width="340" height="62" rx="31" fill="#c08848"/>
    <rect x="236" y="378" width="328" height="34" rx="17" fill="#6b4226"/>
    <path d="M260 388 h270" stroke="#8a5a36" stroke-width="4" stroke-linecap="round"/>`,
  "paris-brest": `${bg("#eadfcb")}${table}${plate()}
    <ellipse cx="400" cy="410" rx="170" ry="52" fill="#b9793a"/>
    <ellipse cx="400" cy="402" rx="160" ry="40" fill="#e8cfa4"/>
    <ellipse cx="400" cy="398" rx="78" ry="18" fill="#eadfcb"/>
    ${Array.from({ length: 14 }, (_, i) => `<ellipse cx="${270 + i * 19}" cy="${384 + Math.sin(i) * 6}" rx="7" ry="3" fill="#f3e9d8"/>`).join("")}`,
  macarons: `${bg("#efe3d2")}${table}
    ${[["#e46f7f", 230], ["#a9c47a", 330], ["#5b3a29", 430], ["#f2d27a", 530]].map(([c, x]) => `
      <ellipse cx="${x}" cy="440" rx="44" ry="16" fill="${c}"/><rect x="${Number(x) - 40}" y="418" width="80" height="12" fill="#f7efe2"/>
      <ellipse cx="${x}" cy="414" rx="44" ry="18" fill="${c}"/>`).join("")}`,
  croissant: `${bg("#e6d8bd")}${table}${plate()}
    <path d="M250 430 Q400 300 550 430 Q470 400 400 405 Q330 400 250 430 Z" fill="#c47c2c"/>
    <path d="M310 410 Q330 360 360 400 M370 398 Q400 345 430 398 M440 400 Q470 360 490 410" stroke="#8f5420" stroke-width="5" fill="none"/>`,
  vitrine: `${bg("#2d2620")}<rect x="80" y="120" width="640" height="300" fill="#4a3c30"/><rect x="80" y="120" width="640" height="300" fill="none" stroke="#c9a46b" stroke-width="4"/>
    <rect x="80" y="270" width="640" height="4" fill="#c9a46b"/>
    ${[160, 280, 400, 520, 640].map((x, i) => `<ellipse cx="${x}" cy="255" rx="42" ry="12" fill="${["#efce4a", "#6b4226", "#e8cfa4", "#e46f7f", "#c47c2c"][i]}"/>`).join("")}
    ${[160, 280, 400, 520, 640].map((x, i) => `<ellipse cx="${x}" cy="405" rx="42" ry="12" fill="${["#c47c2c", "#a9c47a", "#efce4a", "#5b3a29", "#e8cfa4"][i]}"/>`).join("")}
    <rect y="430" width="${W}" height="170" fill="#1d1813"/>`,
  // Atelier Brun : silhouettes de bâtiments
  "maison-erdre": `${bg("#dfe3dc")}<rect y="440" width="${W}" height="160" fill="#7f9a86"/><rect y="470" width="${W}" height="130" fill="#6d8db0"/>
    <path d="M230 440 V300 L390 220 L550 300 V440 Z" fill="#8a6a4a"/>${Array.from({ length: 16 }, (_, i) => `<rect x="${236 + i * 19}" y="300" width="2" height="140" fill="#6f5338"/>`).join("")}
    <rect x="300" y="340" width="70" height="100" fill="#2f3a40"/><rect x="420" y="330" width="90" height="60" fill="#2f3a40"/>`,
  preau: `${bg("#e4e1da")}<rect y="460" width="${W}" height="140" fill="#b9b2a4"/>
    <path d="M120 300 L400 220 L680 300 Z" fill="#a4774b"/>${[160, 280, 400, 520, 640].map((x) => `<rect x="${x - 6}" y="290" width="12" height="170" fill="#a4774b"/>`).join("")}
    <rect x="120" y="296" width="560" height="10" fill="#7d5a37"/>`,
  graslin: `${bg("#ece6db")}<rect x="0" y="0" width="${W}" height="${H}" fill="#e9e0d0"/>
    <rect x="80" y="80" width="220" height="380" fill="#cfd8de"/><rect x="80" y="80" width="220" height="380" fill="none" stroke="#7a5b3c" stroke-width="10"/>
    <rect x="186" y="80" width="8" height="380" fill="#7a5b3c"/>
    <rect y="460" width="${W}" height="140" fill="#b88a5a"/>${Array.from({ length: 10 }, (_, i) => `<rect x="${i * 80}" y="460" width="2" height="140" fill="#9c7348"/>`).join("")}
    <rect x="420" y="300" width="300" height="160" fill="#efe9de" stroke="#7a5b3c" stroke-width="4"/><rect x="420" y="300" width="300" height="14" fill="#7a5b3c"/>`,
};

const targets = {
  "demo/sites/patisserie-lune/images": ["tarte-citron", "eclair-cafe", "paris-brest", "macarons", "croissant", "vitrine"],
  "demo/sites/atelier-brun/public/images": ["maison-erdre", "preau", "graslin"],
};

for (const [dir, names] of Object.entries(targets)) {
  mkdirSync(dir, { recursive: true });
  for (const name of names) {
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">${pastry[name]}</svg>`;
    await sharp(Buffer.from(svg)).webp({ quality: 85 }).toFile(`${dir}/${name}.webp`);
  }
}
console.log("ok");
