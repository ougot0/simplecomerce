/* Simple Commerce : prise de rendez-vous sur le site du client (inclus dans /rdv/{clé}.js, D = données). */
var me = document.currentScript;
var host = document.querySelector('[data-simplecommerce-rdv]');
if (!host) {
  host = document.createElement('div');
  if (me && me.parentNode) { me.parentNode.insertBefore(host, me); } else { document.body.appendChild(host); }
}
var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text) { e.textContent = text; } return e; }
var DAYS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
var MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
function hour(t) { var p = t.split(':'); return (+p[0]) + ' h' + (p[1] === '00' ? '' : ' ' + p[1]); }
function label(day) { var p = day.split('-'); var d = new Date(+p[0], +p[1] - 1, +p[2]); return DAYS[d.getDay()] + ' ' + d.getDate() + ' ' + MONTHS[d.getMonth()]; }
var style = el('style');
style.textContent = [
  ':host{display:block}', '.r{font:inherit;color:inherit;max-width:640px}',
  '.r h3{margin:18px 0 8px;font-size:1em}',
  '.r-list{display:flex;flex-wrap:wrap;gap:8px}',
  '.r-chip{padding:8px 14px;border-radius:999px;border:1.5px solid currentColor;background:transparent;color:inherit;font:inherit;cursor:pointer}',
  '.r-chip[aria-pressed=true]{background:#141210;border-color:#141210;color:#fff}',
  '.r-chip small{opacity:.7;margin-left:6px}',
  '.r-form{display:grid;gap:10px;margin-top:16px}',
  '.r-field{display:flex;flex-direction:column;gap:4px;font-size:.92em}',
  '.r-field input,.r-field textarea{font:inherit;padding:8px 10px;border:1px solid rgba(127,127,127,.5);border-radius:8px;background:#fff;color:#141210}',
  '.r-btn{justify-self:start;padding:10px 20px;border-radius:999px;border:0;background:#141210;color:#fff;font:inherit;font-weight:600;cursor:pointer}',
  '.r-btn:disabled{opacity:.5}', '.r-msg{margin:8px 0 0}', '.r-err{color:#a12d1f}', '.r-muted{opacity:.7}',
  '.r-hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}'
].join('');
root.appendChild(style);
var wrap = el('div', 'r');
root.appendChild(wrap);
var state = { service: null, day: null, time: null };

function get(q, done) {
  fetch(D.api + '/creneaux?' + q).then(function (r) { return r.json(); }).then(done).catch(function () { done({}); });
}
function section(title) { var s = el('div'); s.appendChild(el('h3', '', title)); var l = el('div', 'r-list'); s.appendChild(l); wrap.appendChild(s); return { box: s, list: l }; }
function chip(text, small, pressed, onClick) {
  var b = el('button', 'r-chip', text); b.type = 'button'; b.setAttribute('aria-pressed', pressed ? 'true' : 'false');
  if (small) { b.appendChild(el('small', '', small)); }
  b.addEventListener('click', onClick); return b;
}
function render() {
  wrap.textContent = '';
  var s1 = section('1. Que souhaitez-vous ?');
  D.services.forEach(function (sv, i) {
    s1.list.appendChild(chip(sv.nom, sv.duree + ' min' + (sv.prix ? ' · ' + sv.prix : ''), state.service === i, function () { state = { service: i, day: null, time: null }; render(); }));
  });
  if (state.service === null) { return; }
  var s2 = section('2. Quel jour ?');
  s2.list.appendChild(el('span', 'r-muted', 'Chargement…'));
  get('prestation=' + state.service, function (j) {
    s2.list.textContent = '';
    if (!j.jours || !j.jours.length) { s2.list.appendChild(el('span', 'r-muted', 'Aucun créneau libre pour le moment.')); return; }
    j.jours.forEach(function (d) { s2.list.appendChild(chip(label(d), '', state.day === d, function () { state.day = d; state.time = null; render(); })); });
    if (!state.day) { return; }
    var s3 = section('3. À quelle heure ?');
    get('prestation=' + state.service + '&jour=' + state.day, function (k) {
      (k.creneaux || []).forEach(function (t) { s3.list.appendChild(chip(hour(t), '', state.time === t, function () { state.time = t; render(); })); });
      if (!(k.creneaux || []).length) { s3.list.appendChild(el('span', 'r-muted', 'Plus de créneau ce jour-là.')); }
      if (state.time) { form(); }
    });
  });
}
function field(f, type, name, labelText, req) {
  var l = el('label', 'r-field'); l.appendChild(el('span', '', labelText + (req ? '' : ' (facultatif)')));
  var i = el(type === 'textarea' ? 'textarea' : 'input'); if (type !== 'textarea') { i.type = type; } else { i.rows = 2; }
  i.name = name; if (req) { i.required = true; } l.appendChild(i); f.appendChild(l); return i;
}
function form() {
  var f = el('form', 'r-form');
  f.appendChild(el('h3', '', '4. Vos coordonnées'));
  var nom = field(f, 'text', 'nom', 'Nom', true), mail = field(f, 'email', 'email', 'E-mail', true), tel = field(f, 'tel', 'telephone', 'Téléphone', false), note = field(f, 'textarea', 'note', 'Message', false);
  var hp = el('input', 'r-hp'); hp.name = 'site_web'; hp.tabIndex = -1; hp.autocomplete = 'off'; hp.setAttribute('aria-hidden', 'true'); f.appendChild(hp);
  var go = el('button', 'r-btn', 'Confirmer le rendez-vous'); go.type = 'submit'; f.appendChild(go);
  var msg = el('p', 'r-msg'); f.appendChild(msg);
  f.addEventListener('submit', function (ev) {
    ev.preventDefault(); go.disabled = true;
    fetch(D.api, { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify({ prestation: state.service, jour: state.day, heure: state.time, nom: nom.value, email: mail.value, telephone: tel.value, note: note.value, site_web: hp.value }) })
      .then(function (r) { return r.json(); }).catch(function () { return { error: 'Envoi impossible. Vérifiez votre connexion.' }; })
      .then(function (j) {
        go.disabled = false;
        if (j.error) { msg.className = 'r-msg r-err'; msg.textContent = j.error; return; }
        wrap.textContent = ''; wrap.appendChild(el('h3', '', 'Rendez-vous confirmé')); wrap.appendChild(el('p', 'r-msg', j.message));
      });
  });
  wrap.appendChild(f);
}
if (D.services.length === 1) { state.service = 0; }
render();
