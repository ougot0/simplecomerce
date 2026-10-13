/* Simple Commerce : affichage des produits sur le site du client (inclus dans /catalogue/{clé}.js, D = données).
   Panier et demande de commande si le commerçant les a ouverts ; « Prévenez-moi » pour les produits épuisés. */
var me = document.currentScript;
var host = document.querySelector('[data-simplecommerce]');
if (!host) {
  host = document.createElement('div');
  if (me && me.parentNode) { me.parentNode.insertBefore(host, me); } else { document.body.appendChild(host); }
}
var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
var cart = {};
var cartKey = 'sc_panier_' + (D.api || '').slice(-24);
try { cart = JSON.parse(localStorage.getItem(cartKey) || '{}') || {}; } catch (e) { cart = {}; }

function price(n) {
  if (n === null || n === undefined) { return ''; }
  var s = (Math.round(n * 100) % 100 === 0) ? String(Math.round(n)) : n.toFixed(2).replace('.', ',');
  return s.replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' €';
}
function el(tag, cls, text) {
  var e = document.createElement(tag);
  if (cls) { e.className = cls; }
  if (text) { e.textContent = text; }
  return e;
}
function input(type, name, label, required) {
  var l = el('label', 'sc-field');
  l.appendChild(el('span', '', label + (required ? '' : ' (facultatif)')));
  var i = el(type === 'textarea' ? 'textarea' : 'input');
  if (type !== 'textarea') { i.type = type; }
  i.name = name;
  if (required) { i.required = true; }
  l.appendChild(i);
  return l;
}
function honeypot() {
  var h = el('input');
  h.name = 'site_web';
  h.tabIndex = -1;
  h.autocomplete = 'off';
  h.className = 'sc-hp';
  h.setAttribute('aria-hidden', 'true');
  return h;
}
function send(path, data, done) {
  fetch(D.api + path, { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify(data) })
    .then(function (r) { return r.json(); })
    .then(function (j) { done(j); })
    .catch(function () { done({ error: 'Envoi impossible. Vérifiez votre connexion et réessayez.' }); });
}
function save() {
  try { localStorage.setItem(cartKey, JSON.stringify(cart)); } catch (e) { /* navigation privée */ }
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
  '.sc-badge{position:absolute;top:10px;left:10px;padding:3px 10px;border-radius:999px;background:#141210;color:#fff;font-size:.78em;font-weight:600}',
  '.sc-badge-out{background:#fff;color:#141210}',
  '.sc-dots{position:absolute;left:0;right:0;bottom:8px;display:flex;justify-content:center;gap:6px}',
  '.sc-dots button{width:8px;height:8px;border-radius:50%;border:0;padding:0;background:#fff;opacity:.55;cursor:pointer}',
  '.sc-dots button[aria-current=true]{opacity:1}',
  '.sc-name{font-weight:600;margin:0}',
  '.sc-price{display:flex;gap:8px;align-items:baseline}',
  '.sc-old{text-decoration:line-through;opacity:.6}',
  '.sc-desc{margin:0;opacity:.8;font-size:.92em;line-height:1.45;white-space:pre-line}',
  '.sc-tag{font-size:.8em;font-weight:600;opacity:.75}',
  '.sc-btn{align-self:flex-start;display:inline-block;padding:8px 18px;border-radius:999px;border:1.5px solid currentColor;color:inherit;background:transparent;text-decoration:none;font:inherit;font-weight:600;cursor:pointer}',
  '.sc-btn-main{background:#141210;border-color:#141210;color:#fff}',
  '.sc-btn[aria-disabled=true],.sc-btn:disabled{opacity:.4;pointer-events:none}',
  '.sc-empty{opacity:.7}',
  '.sc-mini{display:flex;flex-direction:column;gap:6px;font-size:.92em}',
  '.sc-mini-row{display:flex;gap:6px;flex-wrap:wrap}',
  '.sc-field{display:flex;flex-direction:column;gap:4px;font-size:.92em}',
  '.sc-field input,.sc-field textarea,.sc-mini input{font:inherit;padding:8px 10px;border:1px solid rgba(127,127,127,.5);border-radius:8px;background:#fff;color:#141210;min-width:0}',
  '.sc-field textarea{min-height:70px}',
  '.sc-hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}',
  '.sc-msg{margin:0;font-size:.92em}',
  '.sc-err{color:#a12d1f}',
  '.sc-cart{margin-top:28px;border:1px solid rgba(127,127,127,.35);border-radius:12px;padding:16px 18px}',
  '.sc-cart h3{margin:0 0 10px}',
  '.sc-line{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid rgba(127,127,127,.2)}',
  '.sc-line span:first-child{flex:1}',
  '.sc-qty{display:inline-flex;align-items:center;gap:6px}',
  '.sc-qty button{width:28px;height:28px;border-radius:50%;border:1px solid currentColor;background:transparent;color:inherit;cursor:pointer;font:inherit}',
  '.sc-total{display:flex;justify-content:space-between;font-weight:700;margin:10px 0}',
  '.sc-form{display:grid;gap:10px;margin-top:12px}',
  '.sc-note{opacity:.8;font-size:.92em;margin:0}'
].join('');
root.appendChild(style);

var byId = {};
D.produits.forEach(function (p) { byId[p.id] = p; });
// Produits disparus ou épuisés depuis : retirés du panier.
Object.keys(cart).forEach(function (id) { if (!byId[id] || byId[id].epuise) { delete cart[id]; } });

var wrap = el('div', 'sc');
if (D.ferme) {
  wrap.appendChild(el('p', 'sc-closed', D.message || 'Les commandes sont fermées pour le moment.'));
}
if (!D.produits.length) {
  wrap.appendChild(el('p', 'sc-empty', 'Aucun produit pour le moment.'));
}
var grid = el('div', 'sc-grid');
var cartBox = el('section', 'sc-cart');
cartBox.hidden = true;

function notifyForm(p, card) {
  var box = el('form', 'sc-mini');
  box.appendChild(el('span', '', 'Prévenez-moi quand il revient :'));
  var row = el('div', 'sc-mini-row');
  var mail = el('input');
  mail.type = 'email';
  mail.required = true;
  mail.placeholder = 'Votre e-mail';
  mail.setAttribute('aria-label', 'Votre e-mail');
  var ok = el('button', 'sc-btn', 'Me prévenir');
  ok.type = 'submit';
  row.appendChild(mail);
  row.appendChild(ok);
  box.appendChild(row);
  var hp = honeypot();
  box.appendChild(hp);
  var msg = el('p', 'sc-msg');
  box.appendChild(msg);
  box.addEventListener('submit', function (ev) {
    ev.preventDefault();
    ok.disabled = true;
    send('/alerte', { produit: p.id, email: mail.value, site_web: hp.value }, function (j) {
      ok.disabled = false;
      msg.className = j.error ? 'sc-msg sc-err' : 'sc-msg';
      msg.textContent = j.error || j.message;
      if (!j.error) { row.hidden = true; }
    });
  });
  card.appendChild(box);
}

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
    if (p.epuise && !p.photos.length) { pr.appendChild(el('span', 'sc-tag', 'Épuisé')); }
    card.appendChild(pr);
  }
  if (p.description) { card.appendChild(el('p', 'sc-desc', p.description)); }
  if (p.epuise) {
    notifyForm(p, card);
  } else if (D.commandes && !D.ferme) {
    var add = el('button', 'sc-btn', 'Ajouter au panier');
    add.type = 'button';
    add.addEventListener('click', function () {
      cart[p.id] = Math.min(99, (cart[p.id] || 0) + 1);
      save();
      drawCart();
      add.textContent = 'Ajouté ✓';
      setTimeout(function () { add.textContent = 'Ajouter au panier'; }, 1200);
    });
    card.appendChild(add);
  } else if (p.lien) {
    var a = el('a', 'sc-btn');
    a.href = p.lien;
    a.rel = 'noopener';
    a.appendChild(el('span', '', 'Commander'));
    if (D.ferme) { a.setAttribute('aria-disabled', 'true'); }
    card.appendChild(a);
  }
  grid.appendChild(card);
});

function drawCart() {
  var ids = Object.keys(cart).filter(function (id) { return cart[id] > 0 && byId[id]; });
  cartBox.textContent = '';
  cartBox.hidden = !ids.length;
  if (!ids.length) { return; }
  cartBox.appendChild(el('h3', '', 'Mon panier'));
  var total = 0;
  ids.forEach(function (id) {
    var p = byId[id];
    var line = el('div', 'sc-line');
    line.appendChild(el('span', '', p.nom));
    var q = el('span', 'sc-qty');
    var minus = el('button', '', '−');
    minus.type = 'button';
    minus.setAttribute('aria-label', 'Un de moins');
    var plus = el('button', '', '+');
    plus.type = 'button';
    plus.setAttribute('aria-label', 'Un de plus');
    minus.addEventListener('click', function () { cart[id]--; if (cart[id] <= 0) { delete cart[id]; } save(); drawCart(); });
    plus.addEventListener('click', function () { cart[id] = Math.min(99, cart[id] + 1); save(); drawCart(); });
    q.appendChild(minus);
    q.appendChild(el('span', '', String(cart[id])));
    q.appendChild(plus);
    line.appendChild(q);
    line.appendChild(el('span', '', p.prix !== null ? price(p.prix * cart[id]) : ''));
    total += (p.prix || 0) * cart[id];
    cartBox.appendChild(line);
  });
  var t = el('div', 'sc-total');
  t.appendChild(el('span', '', 'Total'));
  t.appendChild(el('span', '', price(total)));
  cartBox.appendChild(t);
  if (D.noteCommande) { cartBox.appendChild(el('p', 'sc-note', D.noteCommande)); }
  var f = el('form', 'sc-form');
  f.appendChild(input('text', 'nom', 'Nom', true));
  f.appendChild(input('email', 'email', 'E-mail', true));
  f.appendChild(input('tel', 'telephone', 'Téléphone', false));
  f.appendChild(input('textarea', 'adresse', 'Adresse de livraison', false));
  f.appendChild(input('textarea', 'note', 'Message', false));
  var hp = honeypot();
  f.appendChild(hp);
  var go = el('button', 'sc-btn sc-btn-main', 'Envoyer ma commande');
  go.type = 'submit';
  f.appendChild(go);
  var msg = el('p', 'sc-msg');
  f.appendChild(msg);
  f.addEventListener('submit', function (ev) {
    ev.preventDefault();
    go.disabled = true;
    var data = { site_web: hp.value, lignes: ids.map(function (id) { return { id: id, qte: cart[id] }; }) };
    ['nom', 'email', 'telephone', 'adresse', 'note'].forEach(function (k) { data[k] = f.elements[k].value; });
    send('/commande', data, function (j) {
      go.disabled = false;
      if (j.error) {
        msg.className = 'sc-msg sc-err';
        msg.textContent = j.error;
        return;
      }
      cart = {};
      save();
      cartBox.textContent = '';
      cartBox.appendChild(el('h3', '', 'Commande envoyée'));
      cartBox.appendChild(el('p', 'sc-msg', j.message));
    });
  });
  cartBox.appendChild(f);
}

wrap.appendChild(grid);
wrap.appendChild(cartBox);
root.appendChild(wrap);
drawCart();
