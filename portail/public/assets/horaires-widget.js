/* Simple Commerce : horaires d'ouverture sur le site du client (inclus dans /horaires/{clé}.js, D = données). */
var me = document.currentScript;
var host = document.querySelector('[data-simplecommerce-horaires]');
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
function day(iso) {
  var p = iso.split('-');
  return p[2] + '/' + p[1] + '/' + p[0];
}
var style = el('style');
style.textContent = ':host{display:block}.h{font:inherit;color:inherit}.h-note{border:1px solid currentColor;border-radius:10px;padding:10px 14px;margin:0 0 12px}'
  + 'table{border-collapse:collapse}td{padding:3px 16px 3px 0;vertical-align:top}.h-today td{font-weight:700}.h-off{opacity:.6}';
root.appendChild(style);
var wrap = el('div', 'h');
if (D.fermeturePrevue) {
  var f = D.fermeturePrevue;
  wrap.appendChild(el('p', 'h-note', (f.message ? f.message + ' ' : '') + 'Fermeture à partir du ' + day(f.du) + (f.au ? ', retour le ' + day(f.au) : '') + '.'));
}
var t = el('table');
D.jours.forEach(function (name, i) {
  var line = (D.horaires[i] || '').trim();
  var tr = el('tr', i === D.today ? 'h-today' : '');
  tr.appendChild(el('td', '', name));
  tr.appendChild(el('td', line ? '' : 'h-off', line || 'Fermé'));
  t.appendChild(tr);
});
wrap.appendChild(t);
root.appendChild(wrap);
