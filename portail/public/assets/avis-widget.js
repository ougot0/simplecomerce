/* Simple Commerce : avis clients sur le site du client (inclus dans /avis/{clé}.js, D = données). */
var me = document.currentScript;
var host = document.querySelector('[data-simplecommerce-avis]');
if (!host) {
  host = document.createElement('div');
  if (me && me.parentNode) { me.parentNode.insertBefore(host, me); } else { document.body.appendChild(host); }
}
var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
function el(tag, cls, text) {
  var e = document.createElement(tag);
  if (cls) { e.className = cls; }
  if (text) { e.textContent = text; }
  return e;
}
function stars(n) {
  var s = el('span', 'a-stars');
  s.setAttribute('aria-label', n + ' sur 5');
  s.appendChild(document.createTextNode('★★★★★'.slice(0, n)));
  s.appendChild(el('span', 'a-off', '★★★★★'.slice(0, 5 - n)));
  return s;
}
function day(iso) {
  var p = iso.split('-');
  return p[2] + '/' + p[1] + '/' + p[0];
}
var style = el('style');
style.textContent = [
  ':host{display:block}',
  '.a{font:inherit;color:inherit}',
  '.a-head{display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin:0 0 14px}',
  '.a-avg{font-size:1.6em;font-weight:700}',
  '.a-stars{letter-spacing:1px}',
  '.a-off{opacity:.25}',
  '.a-list{display:grid;gap:14px;margin:0 0 18px;padding:0;list-style:none}',
  '.a-item{border:1px solid rgba(127,127,127,.3);border-radius:12px;padding:14px 16px}',
  '.a-meta{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;font-size:.9em}',
  '.a-meta strong{font-size:1.05em}',
  '.a-date{opacity:.6}',
  '.a-text{margin:8px 0 0;white-space:pre-line;line-height:1.5}',
  '.a-reply{margin:10px 0 0;padding:8px 12px;border-left:3px solid currentColor;opacity:.85;white-space:pre-line;font-size:.95em}',
  '.a-more{background:none;border:0;padding:0;color:inherit;text-decoration:underline;cursor:pointer;font:inherit;margin:0 0 16px}',
  '.a-form{display:grid;gap:10px;max-width:520px}',
  '.a-field{display:flex;flex-direction:column;gap:4px;font-size:.92em}',
  '.a-field input,.a-field textarea{font:inherit;padding:8px 10px;border:1px solid rgba(127,127,127,.5);border-radius:8px;background:#fff;color:#141210}',
  '.a-pick{display:flex;gap:2px;font-size:1.6em}',
  '.a-pick button{background:none;border:0;padding:0 2px;cursor:pointer;color:inherit;opacity:.25;font:inherit;line-height:1}',
  '.a-pick button.on{opacity:1}',
  '.a-btn{justify-self:start;padding:8px 18px;border-radius:999px;border:1.5px solid #141210;background:#141210;color:#fff;font:inherit;font-weight:600;cursor:pointer}',
  '.a-btn:disabled{opacity:.5}',
  '.a-hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}',
  '.a-msg{margin:0}',
  '.a-err{color:#a12d1f}'
].join('');
root.appendChild(style);

var wrap = el('div', 'a');
if (D.nombre) {
  var head = el('p', 'a-head');
  head.appendChild(el('span', 'a-avg', String(D.moyenne).replace('.', ',')));
  head.appendChild(stars(Math.round(D.moyenne)));
  head.appendChild(el('span', '', D.nombre + ' avis'));
  wrap.appendChild(head);
  var list = el('ul', 'a-list');
  D.avis.forEach(function (a, i) {
    var li = el('li', 'a-item');
    if (i >= 6) { li.hidden = true; }
    var meta = el('div', 'a-meta');
    meta.appendChild(stars(a.note));
    meta.appendChild(el('strong', '', a.auteur));
    meta.appendChild(el('span', 'a-date', day(a.date)));
    li.appendChild(meta);
    li.appendChild(el('p', 'a-text', a.texte));
    if (a.reponse) { li.appendChild(el('p', 'a-reply', 'Réponse de ' + D.nom + ' : ' + a.reponse)); }
    list.appendChild(li);
  });
  wrap.appendChild(list);
  if (D.avis.length > 6) {
    var more = el('button', 'a-more', 'Voir tous les avis');
    more.type = 'button';
    more.addEventListener('click', function () {
      Array.prototype.forEach.call(list.children, function (li) { li.hidden = false; });
      more.remove();
    });
    wrap.appendChild(more);
  }
}

var open = el('button', 'a-btn', 'Donner mon avis');
open.type = 'button';
var form = el('form', 'a-form');
form.hidden = true;
var rating = 0;
var pick = el('div', 'a-pick');
pick.setAttribute('role', 'group');
pick.setAttribute('aria-label', 'Votre note');
for (var n = 1; n <= 5; n++) {
  (function (v) {
    var b = el('button', '', '★');
    b.type = 'button';
    b.setAttribute('aria-label', v + ' sur 5');
    b.addEventListener('click', function () {
      rating = v;
      Array.prototype.forEach.call(pick.children, function (c, i) { c.className = i < v ? 'on' : ''; });
    });
    pick.appendChild(b);
  })(n);
}
var lab = el('div', 'a-field');
lab.appendChild(el('span', '', 'Votre note'));
lab.appendChild(pick);
form.appendChild(lab);
function field(type, name, label) {
  var l = el('label', 'a-field');
  l.appendChild(el('span', '', label));
  var i = el(type === 'textarea' ? 'textarea' : 'input');
  if (type !== 'textarea') { i.type = type; } else { i.rows = 4; }
  i.name = name;
  i.required = true;
  l.appendChild(i);
  form.appendChild(l);
  return i;
}
var name = field('text', 'nom', 'Votre prénom');
name.maxLength = 80;
var text = field('textarea', 'texte', 'Votre avis');
text.maxLength = 1500;
var hp = el('input', 'a-hp');
hp.name = 'site_web';
hp.tabIndex = -1;
hp.autocomplete = 'off';
hp.setAttribute('aria-hidden', 'true');
form.appendChild(hp);
var go = el('button', 'a-btn', 'Envoyer mon avis');
go.type = 'submit';
form.appendChild(go);
var msg = el('p', 'a-msg');
form.appendChild(msg);
open.addEventListener('click', function () { form.hidden = false; open.hidden = true; name.focus(); });
form.addEventListener('submit', function (ev) {
  ev.preventDefault();
  if (!rating) { msg.className = 'a-msg a-err'; msg.textContent = 'Choisissez une note en cliquant sur les étoiles.'; return; }
  go.disabled = true;
  fetch(D.api, { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify({ nom: name.value, texte: text.value, note: rating, site_web: hp.value }) })
    .then(function (r) { return r.json(); })
    .catch(function () { return { error: 'Envoi impossible. Vérifiez votre connexion et réessayez.' }; })
    .then(function (j) {
      go.disabled = false;
      msg.className = j.error ? 'a-msg a-err' : 'a-msg';
      msg.textContent = j.error || j.message;
      if (!j.error) { form.textContent = ''; form.appendChild(msg); }
    });
});
wrap.appendChild(open);
wrap.appendChild(form);
root.appendChild(wrap);
