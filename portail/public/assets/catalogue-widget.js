/* Simple Commerce : affichage des produits sur le site du client (inclus dans /catalogue/{clé}.js, D = données). */
var me = document.currentScript;
var host = document.querySelector('[data-simplecommerce]');
if (!host) {
  host = document.createElement('div');
  if (me && me.parentNode) { me.parentNode.insertBefore(host, me); } else { document.body.appendChild(host); }
}
var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

function price(n) {
  if (n === null || n === undefined) { return ''; }
  var s = (Math.round(n * 100) % 100 === 0) ? String(Math.round(n)) : n.toFixed(2).replace('.', ',');
  return s.replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' €';
}
function el(tag, cls, text) {
  var e = document.createElement(tag);
  if (cls) { e.className = cls; }
  if (text) { e.textContent = text; }
  return e;
}

var style = el('style');
style.textContent = [
  ':host{display:block}',
  '.sc{font:inherit;color:inherit}',
  '.sc-closed{border:1px solid currentColor;border-radius:10px;padding:12px 16px;margin:0 0 16px;opacity:.9}',
  '.sc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:20px}',
  '.sc-card{display:flex;flex-direction:column;gap:8px;min-width:0}',
  '.sc-photo{position:relative;aspect-ratio:1/1;border-radius:10px;overflow:hidden;background:rgba(127,127,127,.12)}',
  '.sc-photo img{width:100%;height:100%;object-fit:cover;display:block}',
  '.sc-badge{position:absolute;top:10px;left:10px;padding:3px 10px;border-radius:999px;background:#141210;color:#fff;font-size:.78em;font-weight:600;letter-spacing:.01em}',
  '.sc-badge-out{background:#fff;color:#141210}',
  '.sc-dots{position:absolute;left:0;right:0;bottom:8px;display:flex;justify-content:center;gap:6px}',
  '.sc-dots button{width:8px;height:8px;border-radius:50%;border:0;padding:0;background:#fff;opacity:.55;cursor:pointer}',
  '.sc-dots button[aria-current=true]{opacity:1}',
  '.sc-name{font-weight:600;margin:0}',
  '.sc-price{display:flex;gap:8px;align-items:baseline}',
  '.sc-old{text-decoration:line-through;opacity:.6}',
  '.sc-desc{margin:0;opacity:.8;font-size:.92em;line-height:1.45;white-space:pre-line}',
  '.sc-tag{font-size:.8em;font-weight:600;opacity:.75}',
  '.sc-btn{align-self:flex-start;display:inline-block;padding:8px 18px;border-radius:999px;border:1.5px solid currentColor;color:inherit;text-decoration:none;font-weight:600}',
  '.sc-btn[aria-disabled=true]{opacity:.4;pointer-events:none}',
  '.sc-empty{opacity:.7}'
].join('');
root.appendChild(style);

var wrap = el('div', 'sc');
if (D.ferme) {
  wrap.appendChild(el('p', 'sc-closed', D.message || 'Les commandes sont fermées pour le moment.'));
}
if (!D.produits.length) {
  wrap.appendChild(el('p', 'sc-empty', 'Aucun produit pour le moment.'));
}
var grid = el('div', 'sc-grid');
D.produits.forEach(function (p) {
  var card = el('article', 'sc-card');
  if (p.photos.length) {
    var box = el('div', 'sc-photo');
    var img = el('img');
    img.src = p.photos[0];
    img.alt = p.nom;
    img.loading = 'lazy';
    box.appendChild(img);
    if (p.pastille || p.epuise) {
      box.appendChild(el('span', p.epuise ? 'sc-badge sc-badge-out' : 'sc-badge', p.epuise ? 'Épuisé' : p.pastille));
    }
    if (p.photos.length > 1) {
      var dots = el('div', 'sc-dots');
      p.photos.forEach(function (src, i) {
        var b = el('button');
        b.type = 'button';
        b.setAttribute('aria-label', 'Photo ' + (i + 1));
        b.setAttribute('aria-current', i === 0 ? 'true' : 'false');
        b.addEventListener('click', function () {
          img.src = src;
          Array.prototype.forEach.call(dots.children, function (d) { d.setAttribute('aria-current', d === b ? 'true' : 'false'); });
        });
        dots.appendChild(b);
      });
      box.appendChild(dots);
    }
    card.appendChild(box);
  }
  if (!p.photos.length && p.pastille) { card.appendChild(el('span', 'sc-tag', p.pastille)); }
  card.appendChild(el('h3', 'sc-name', p.nom));
  if (p.prix !== null || p.epuise) {
    var pr = el('div', 'sc-price');
    if (p.prix !== null) { pr.appendChild(el('span', '', price(p.prix))); }
    if (p.ancienPrix !== null && p.prix !== null && p.ancienPrix > p.prix) { pr.appendChild(el('span', 'sc-old', price(p.ancienPrix))); }
    if (p.epuise) { pr.appendChild(el('span', 'sc-tag', 'Épuisé')); }
    card.appendChild(pr);
  }
  if (p.description) { card.appendChild(el('p', 'sc-desc', p.description)); }
  if (p.lien) {
    var a = el('a', 'sc-btn');
    a.href = p.lien;
    a.rel = 'noopener';
    a.appendChild(el('span', '', p.epuise ? 'Épuisé' : 'Commander'));
    if (p.epuise || D.ferme) { a.setAttribute('aria-disabled', 'true'); }
    card.appendChild(a);
  }
  grid.appendChild(card);
});
wrap.appendChild(grid);
root.appendChild(wrap);
