/*
 * Simple Commerce — petites aides dans le navigateur.
 * Tout fonctionne aussi sans ce fichier, sauf l'envoi de photos, le recadrage et l'ajout de lignes.
 */
(function () {
  "use strict";

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var csrf = ($('meta[name="csrf"]') || {}).content || "";

  function post(url, body) {
    return fetch(url, {
      method: "POST",
      body: body,
      credentials: "same-origin",
      headers: { Accept: "application/json", "X-CSRF-Token": csrf },
    }).then(function (r) {
      return r.json().catch(function () { return { error: "Réponse inattendue du serveur. Réessayez." }; });
    }, function () {
      return { error: "Connexion impossible. Vérifiez votre accès à Internet." };
    });
  }

  function formatPrice(text) {
    var t = String(text || "").trim();
    if (!t) return "";
    var n = parseFloat(t.replace(/\s/g, "").replace(",", "."));
    if (isNaN(n)) return t;
    return (n % 1 === 0 ? String(n) : n.toFixed(2)).replace(".", ",") + " €";
  }

  // ---------------------------------------------------------------- menu (petits écrans)
  $$("[data-menu-toggle]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var menu = $("[data-menu]");
      var open = !menu.classList.contains("is-open");
      menu.classList.toggle("is-open", open);
      btn.setAttribute("aria-expanded", open ? "true" : "false");
    });
  });

  // ---------------------------------------------------------------- confirmations, envois
  document.addEventListener("submit", function (e) {
    var form = e.target;
    var msg = form.getAttribute("data-confirm");
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
      return;
    }
    if (form.hasAttribute("data-dirty-guard")) form.__clean = true;
    var btn = e.submitter;
    if (btn && btn.tagName === "BUTTON") {
      var label = btn.getAttribute("data-busy") ||
        (btn.value === "publish" ? "Publication…" : btn.value === "draft" ? "Enregistrement…" : btn.value === "test" ? null : "Un instant…");
      if (label) {
        // Après l'envoi : un bouton désactivé trop tôt ne serait pas transmis.
        setTimeout(function () {
          btn.disabled = true;
          btn.textContent = label;
        }, 0);
      }
    }
  });

  $$("[data-autosubmit]").forEach(function (el) {
    el.addEventListener("change", function () { el.form.submit(); });
  });

  $$("[data-fill]").forEach(function (b) {
    b.addEventListener("click", function () {
      var parts = b.getAttribute("data-fill").split("|");
      $("#email").value = parts[0];
      $("#password").value = parts[1];
      $("#password").focus();
    });
  });

  $$("[data-copy]").forEach(function (b) {
    b.addEventListener("click", function () {
      var input = b.parentNode.querySelector("input");
      input.select();
      (navigator.clipboard ? navigator.clipboard.writeText(input.value) : Promise.reject()).then(
        function () { b.textContent = "Copié"; },
        function () { document.execCommand("copy"); b.textContent = "Copié"; }
      );
    });
  });
  $$("[data-select]").forEach(function (i) { i.addEventListener("focus", function () { i.select(); }); });

  // ---------------------------------------------------------------- recherche dans les listes
  $$("[data-filter]").forEach(function (input) {
    var list = $("[data-filter-list]");
    var empty = $("[data-filter-empty]");
    input.addEventListener("input", function () {
      var q = input.value.trim().toLowerCase();
      var shown = 0;
      $$("[data-filter-item]", list).forEach(function (item) {
        var ok = !q || (item.getAttribute("data-text") || "").indexOf(q) !== -1;
        item.hidden = !ok;
        if (ok) shown++;
      });
      if (empty) empty.hidden = shown > 0;
    });
  });

  // ---------------------------------------------------------------- lignes : ajouter, retirer, déplacer
  var rowCounter = 0;
  function renumber(container) {
    $$("[data-pos]", container).forEach(function (cell, i) { cell.textContent = String(i + 1); });
  }
  document.addEventListener("click", function (e) {
    var t = e.target.closest ? e.target.closest("[data-add],[data-remove],[data-move]") : null;
    if (!t) return;
    var holder = t.closest("[data-repeater],[data-list],[data-gallery],[data-reorder]");
    if (!holder) return;
    var items = $("[data-items]", holder);
    if (t.hasAttribute("data-add")) {
      var tpl = holder.querySelector(":scope > template");
      var html = tpl.innerHTML.replace(/__ROW__/g, "n" + Date.now().toString(36) + rowCounter++);
      var wrap = document.createElement("div");
      wrap.innerHTML = html;
      var row = wrap.firstElementChild;
      items.appendChild(row);
      enhance(row);
      var first = row.querySelector("input:not([type=hidden]),textarea,select");
      if (first) first.focus();
    } else if (t.hasAttribute("data-remove")) {
      var r = t.closest("[data-row],[data-gallery-item]");
      if (r) r.remove();
    } else {
      var cur = t.closest("[data-row],[data-gallery-item]");
      var dir = parseInt(t.getAttribute("data-move"), 10);
      var sib = dir < 0 ? cur.previousElementSibling : cur.nextElementSibling;
      if (sib) {
        if (dir < 0) cur.parentNode.insertBefore(cur, sib);
        else cur.parentNode.insertBefore(sib, cur);
        t.focus();
      }
      renumber(holder);
    }
    markDirty(holder);
    updatePreview();
  });

  // ---------------------------------------------------------------- éditeur : modifications non enregistrées
  var editor = $("[data-editor]");
  var dirty = false;
  function markDirty(el) {
    if (editor && (!el || editor.contains(el))) dirty = true;
  }
  if (editor) {
    editor.addEventListener("input", function () { dirty = true; updatePreview(); });
    editor.addEventListener("change", function () { dirty = true; updatePreview(); });
    window.addEventListener("beforeunload", function (e) {
      if (dirty && !editor.__clean) {
        e.preventDefault();
        e.returnValue = "";
      }
    });
    $$("[data-leave]").forEach(function (a) {
      a.addEventListener("click", function (e) {
        if (dirty && !window.confirm("Quitter sans enregistrer vos modifications ?")) e.preventDefault();
        else editor.__clean = true;
      });
    });
  }

  var toggleSchedule = $("[data-toggle-schedule]");
  if (toggleSchedule) {
    toggleSchedule.addEventListener("click", function () {
      var box = $("[data-schedule]");
      box.hidden = !box.hidden;
      if (!box.hidden) $("input", box).focus();
    });
  }
  var openDelete = $("[data-open-delete]");
  if (openDelete) {
    openDelete.addEventListener("click", function () {
      var box = $("[data-delete-box]");
      box.hidden = false;
      box.scrollIntoView({ behavior: "smooth", block: "center" });
    });
    $("[data-close-delete]").addEventListener("click", function () { $("[data-delete-box]").hidden = true; });
  }

  // ---------------------------------------------------------------- aperçu en direct
  var preview = $("[data-preview]");
  function fieldInput(key) {
    return editor ? editor.querySelector('[name="data[' + key + ']"]:not([type=hidden]), [name="data[' + key + ']"][data-image-value]') : null;
  }
  function updatePreview() {
    if (!preview || !editor) return;
    var tKey = preview.getAttribute("data-title");
    var sKey = preview.getAttribute("data-sub");
    var xKey = preview.getAttribute("data-text");
    var iKey = preview.getAttribute("data-image");
    if (tKey) {
      var ti = fieldInput(tKey);
      if (ti) $("[data-p-title]", preview).textContent = ti.value || "Sans titre";
    }
    if (sKey) {
      var si = fieldInput(sKey);
      if (si) $("[data-p-sub]", preview).textContent = preview.getAttribute("data-sub-price") ? formatPrice(si.value) : si.value;
    }
    if (xKey) {
      var xi = fieldInput(xKey);
      if (xi) {
        var tmp = document.createElement("div");
        tmp.innerHTML = xi.value;
        var text = (tmp.textContent || "").replace(/\s+/g, " ").trim();
        $("[data-p-text]", preview).textContent = text.length > 260 ? text.slice(0, 259) + "…" : text;
      }
    }
    if (iKey) {
      var box = editor.querySelector('[data-image] [name="data[' + iKey + ']"]');
      var gal = editor.querySelector('[data-gallery][data-name="data[' + iKey + ']"]');
      var img = box ? box.closest("[data-image]").querySelector(".frame img") : gal ? gal.querySelector(".frame img") : null;
      var slot = $("[data-p-photo]", preview);
      if (slot) slot.innerHTML = img && img.getAttribute("src") ? '<img alt="" src="' + img.getAttribute("src").replace(/"/g, "&quot;") + '">' : "<span>Aucune photo</span>";
    }
  }

  // ---------------------------------------------------------------- compteurs de caractères
  function counters(root) {
    $$("[data-max]", root).forEach(function (input) {
      var c = input.parentNode.querySelector("[data-counter]");
      if (!c) return;
      var max = parseInt(input.getAttribute("data-max"), 10);
      var upd = function () {
        var n = input.value.length;
        c.textContent = n + " / " + max;
        c.classList.toggle("over", n > max);
      };
      input.addEventListener("input", upd);
      upd();
    });
  }

  // ---------------------------------------------------------------- texte enrichi (gras, italique, liste, lien)
  var ALLOWED = { P: 1, BR: 1, STRONG: 1, EM: 1, A: 1, UL: 1, OL: 1, LI: 1 };
  function clean(node) {
    var out = document.createElement("div");
    (function walk(src, dst) {
      Array.prototype.forEach.call(src.childNodes, function (n) {
        if (n.nodeType === 3) { dst.appendChild(document.createTextNode(n.nodeValue)); return; }
        if (n.nodeType !== 1) return;
        var tag = n.tagName === "B" ? "STRONG" : n.tagName === "I" ? "EM" : n.tagName === "DIV" ? "P" : n.tagName;
        if (!ALLOWED[tag]) { walk(n, dst); return; }
        var el = document.createElement(tag);
        if (tag === "A") {
          var href = n.getAttribute("href") || "";
          if (/^(https?:|mailto:|tel:|\/)/i.test(href)) el.setAttribute("href", href);
        }
        dst.appendChild(el);
        walk(n, el);
      });
    })(node, out);
    return out.innerHTML;
  }
  function richtext(root) {
    $$("[data-richtext]", root).forEach(function (box) {
      if (box.__done) return;
      box.__done = true;
      var ta = box.querySelector("textarea");
      var parsed = new DOMParser().parseFromString("<body>" + ta.value + "</body>", "text/html").body;
      var area = document.createElement("div");
      area.className = "richtext-area";
      area.contentEditable = "true";
      area.setAttribute("role", "textbox");
      area.setAttribute("aria-multiline", "true");
      area.setAttribute("aria-labelledby", ta.id + "-label");
      var label = document.querySelector('label[for="' + ta.id + '"]');
      if (label) label.id = ta.id + "-label";
      area.innerHTML = clean(parsed) || "<p><br></p>";
      var bar = document.createElement("div");
      bar.className = "toolbar";
      var tools = [
        ["bold", "Gras", '<strong>G</strong>'],
        ["italic", "Italique", "<em>I</em>"],
        ["insertUnorderedList", "Liste à puces", "• Liste"],
        ["createLink", "Lien", "Lien"],
        ["removeFormat", "Effacer la mise en forme", "Effacer"],
      ];
      tools.forEach(function (t) {
        var b = document.createElement("button");
        b.type = "button";
        b.title = t[1];
        b.setAttribute("aria-label", t[1]);
        b.innerHTML = t[2];
        b.addEventListener("mousedown", function (e) { e.preventDefault(); });
        b.addEventListener("click", function () {
          if (t[0] === "createLink") {
            var url = window.prompt("Adresse du lien (https://…)", "https://");
            if (!url || !/^(https?:\/\/|mailto:|tel:|\/)/i.test(url)) return;
            document.execCommand("createLink", false, url);
          } else {
            document.execCommand(t[0], false, null);
          }
          sync();
        });
        bar.appendChild(b);
      });
      function sync() {
        ta.value = clean(area);
        ta.dispatchEvent(new Event("input", { bubbles: true }));
      }
      area.addEventListener("input", sync);
      area.addEventListener("paste", function (e) {
        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData("text/plain");
        document.execCommand("insertText", false, text);
      });
      ta.hidden = true;
      box.insertBefore(bar, ta);
      box.insertBefore(area, ta);
    });
  }

  // ---------------------------------------------------------------- photos : choix, recadrage, envoi, bibliothèque
  var urls = $("[data-upload-url]");
  var fileInput = $("[data-file-input]");
  var cropDialog = $("[data-crop-dialog]");
  var libraryDialog = $("[data-library-dialog]");
  var pending = null; // { target, gallery }

  function setImage(box, value, src) {
    var input = box.querySelector("[data-image-value]");
    input.value = value;
    var frame = box.querySelector(".frame");
    frame.innerHTML = src ? '<img alt="">' : "Pas de photo";
    if (src) frame.querySelector("img").src = src;
    box.querySelector("[data-clear]").hidden = !value;
    box.querySelector("[data-pick]").lastChild.textContent = value ? " Changer la photo" : " Choisir une photo";
    markDirty(box);
    updatePreview();
  }
  function addGalleryItem(gal, value, src) {
    var name = gal.getAttribute("data-name");
    var item = document.createElement("div");
    item.className = "gallery-item";
    item.setAttribute("data-gallery-item", "");
    item.innerHTML = '<span class="frame"><img alt=""></span><input type="hidden">' +
      '<div class="actions"><button type="button" class="btn btn-small btn-quiet" data-move="-1" aria-label="Avant">←</button>' +
      '<button type="button" class="btn btn-small btn-quiet" data-move="1" aria-label="Après">→</button>' +
      '<button type="button" class="btn btn-small btn-quiet" data-remove aria-label="Retirer">✕</button></div>';
    item.querySelector("img").src = src;
    var hidden = item.querySelector("input");
    hidden.name = name + "[]";
    hidden.value = value;
    $("[data-items]", gal).appendChild(item);
    markDirty(gal);
    updatePreview();
  }
  function target(btn) {
    var box = btn.closest("[data-image]");
    return box ? { box: box, gallery: false } : { box: btn.closest("[data-gallery]"), gallery: true };
  }

  document.addEventListener("click", function (e) {
    var b = e.target.closest ? e.target.closest("[data-pick],[data-clear],[data-library],[data-close-dialog]") : null;
    if (!b) return;
    if (b.hasAttribute("data-close-dialog")) { b.closest("dialog").close(); return; }
    var t = target(b);
    if (!t.box) return;
    if (b.hasAttribute("data-clear")) { setImage(t.box, "", null); return; }
    pending = t;
    if (b.hasAttribute("data-pick")) {
      fileInput.value = "";
      fileInput.click();
    } else {
      openLibrary();
    }
  });

  function openLibrary() {
    var list = $("[data-library-list]");
    list.innerHTML = '<p class="muted">Chargement…</p>';
    libraryDialog.showModal();
    fetch(urls.getAttribute("data-library-url"), { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        list.innerHTML = "";
        if (!data.photos || !data.photos.length) {
          list.innerHTML = '<p class="muted">Aucune photo pour l\'instant. Les photos que vous envoyez apparaîtront ici après leur publication.</p>';
          return;
        }
        data.photos.forEach(function (p) {
          var btn = document.createElement("button");
          btn.type = "button";
          btn.title = p.alt || p.name;
          var img = document.createElement("img");
          img.src = p.src;
          img.alt = p.alt || "";
          img.loading = "lazy";
          btn.appendChild(img);
          btn.addEventListener("click", function () {
            if (pending.gallery) addGalleryItem(pending.box, p.value, p.src);
            else setImage(pending.box, p.value, p.src);
            libraryDialog.close();
          });
          list.appendChild(btn);
        });
      })
      .catch(function () { list.innerHTML = '<p class="error-text">Impossible de charger vos photos.</p>'; });
  }

  // Recadrage : l'image couvre un cadre aux bonnes proportions ; on la déplace et on zoome.
  var crop = { img: null, zoom: 1, x: 0, y: 0, w: 0, h: 0, ratio: 4 / 3 };
  var canvas = $("[data-crop-canvas]");

  function ratioOf(box, img) {
    var a = (box.getAttribute("data-aspect") || "4:3").split(":");
    if (a.length !== 2) return img.naturalWidth / img.naturalHeight;
    return parseFloat(a[0]) / parseFloat(a[1]);
  }
  function baseScale() {
    return Math.max(crop.w / crop.img.naturalWidth, crop.h / crop.img.naturalHeight);
  }
  function clampCrop() {
    var s = baseScale() * crop.zoom;
    var iw = crop.img.naturalWidth * s, ih = crop.img.naturalHeight * s;
    crop.x = Math.min(0, Math.max(crop.w - iw, crop.x));
    crop.y = Math.min(0, Math.max(crop.h - ih, crop.y));
  }
  function drawCrop() {
    var ctx = canvas.getContext("2d");
    var s = baseScale() * crop.zoom;
    ctx.fillStyle = "#141210";
    ctx.fillRect(0, 0, crop.w, crop.h);
    ctx.drawImage(crop.img, crop.x, crop.y, crop.img.naturalWidth * s, crop.img.naturalHeight * s);
  }

  if (fileInput) {
    fileInput.addEventListener("change", function () {
      var file = fileInput.files && fileInput.files[0];
      if (!file || !pending) return;
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { window.alert("Format non accepté. Choisissez une photo JPEG, PNG ou WebP."); return; }
      if (file.size > 25 * 1024 * 1024) { window.alert("Cette photo est trop lourde."); return; }
      var img = new Image();
      img.onload = function () {
        crop.img = img;
        crop.ratio = pending.gallery && pending.box.getAttribute("data-aspect") === "libre" ? img.naturalWidth / img.naturalHeight : ratioOf(pending.box, img);
        var area = $("[data-crop-area]");
        cropDialog.showModal();
        var maxW = Math.min(area.clientWidth || 640, 760), maxH = Math.min(area.clientHeight || 420, 420);
        crop.w = Math.round(Math.min(maxW, maxH * crop.ratio));
        crop.h = Math.round(crop.w / crop.ratio);
        canvas.width = crop.w;
        canvas.height = crop.h;
        crop.zoom = 1;
        $("[data-crop-zoom]").value = "1";
        var s = baseScale();
        crop.x = (crop.w - img.naturalWidth * s) / 2;
        crop.y = (crop.h - img.naturalHeight * s) / 2;
        $("[data-crop-alt]").value = "";
        drawCrop();
      };
      img.onerror = function () { window.alert("Cette image est illisible."); };
      img.src = URL.createObjectURL(file);
    });

    var drag = null;
    canvas.addEventListener("pointerdown", function (e) { drag = { x: e.clientX, y: e.clientY, ox: crop.x, oy: crop.y }; canvas.setPointerCapture(e.pointerId); });
    canvas.addEventListener("pointermove", function (e) {
      if (!drag) return;
      var k = crop.w / canvas.getBoundingClientRect().width;
      crop.x = drag.ox + (e.clientX - drag.x) * k;
      crop.y = drag.oy + (e.clientY - drag.y) * k;
      clampCrop();
      drawCrop();
    });
    canvas.addEventListener("pointerup", function () { drag = null; });
    $("[data-crop-zoom]").addEventListener("input", function (e) {
      var old = baseScale() * crop.zoom;
      crop.zoom = parseFloat(e.target.value);
      var now = baseScale() * crop.zoom;
      // On zoome autour du centre du cadre.
      crop.x = crop.w / 2 - (crop.w / 2 - crop.x) * (now / old);
      crop.y = crop.h / 2 - (crop.h / 2 - crop.y) * (now / old);
      clampCrop();
      drawCrop();
    });
    $("[data-crop-cancel]").addEventListener("click", function () { cropDialog.close(); });
    $("[data-crop-ok]").addEventListener("click", function () {
      var okBtn = this;
      var maxWidth = parseInt(pending.box.getAttribute("data-max-width"), 10) || 2000;
      var s = baseScale() * crop.zoom;
      var srcW = crop.w / s; // largeur de la zone choisie, en pixels de la photo d'origine
      var outW = Math.round(Math.min(maxWidth, srcW));
      var outH = Math.round(outW / crop.ratio);
      var out = document.createElement("canvas");
      out.width = outW;
      out.height = outH;
      var k = outW / crop.w;
      var ctx = out.getContext("2d");
      ctx.imageSmoothingQuality = "high";
      ctx.drawImage(crop.img, crop.x * k, crop.y * k, crop.img.naturalWidth * s * k, crop.img.naturalHeight * s * k);
      okBtn.disabled = true;
      okBtn.textContent = "Envoi…";
      out.toBlob(function (blob) {
        var fd = new FormData();
        fd.append("file", blob, "photo.jpg");
        fd.append("alt", $("[data-crop-alt]").value);
        fd.append("maxWidth", String(maxWidth));
        fd.append("_csrf", csrf);
        var box = pending.box;
        var isGallery = pending.gallery;
        box.classList.add("is-busy");
        post(urls.getAttribute("data-upload-url"), fd).then(function (res) {
          box.classList.remove("is-busy");
          okBtn.disabled = false;
          okBtn.textContent = "Utiliser cette photo";
          if (res.error) { window.alert(res.error); return; }
          cropDialog.close();
          if (isGallery) addGalleryItem(box, res.token, res.url);
          else setImage(box, res.token, res.url);
        });
      }, "image/jpeg", 0.9);
    });
  }

  // ---------------------------------------------------------------- relier un site : test sans recharger, hébergeurs
  $$("[data-connect-form]").forEach(function (form) {
    var btn = $("[data-test-button]", form);
    var out = $("[data-report]", form);
    btn.addEventListener("click", function (e) {
      e.preventDefault();
      var fd = new FormData(form);
      fd.set("intent", "test");
      var label = btn.innerHTML;
      btn.disabled = true;
      btn.textContent = "Vérification…";
      $$(".field-invalid", form).forEach(function (f) { f.classList.remove("field-invalid"); });
      $$(".js-error", form).forEach(function (n) { n.remove(); });
      post(form.getAttribute("action"), fd).then(function (res) {
        btn.disabled = false;
        btn.innerHTML = label;
        if (res.html) {
          out.innerHTML = res.html;
        } else if (res.errors) {
          out.innerHTML = '<div class="notice notice-error" role="alert">Certains champs sont à corriger avant le test.</div>';
          Object.keys(res.errors).forEach(function (k) {
            var input = form.querySelector('[name="' + k + '"]');
            if (!input) return;
            var field = input.closest(".field");
            field.classList.add("field-invalid");
            var span = document.createElement("span");
            span.className = "error-text js-error";
            span.textContent = res.errors[k];
            field.appendChild(span);
            var details = input.closest("details");
            if (details) details.open = true;
          });
        } else {
          out.innerHTML = '<div class="notice notice-error" role="alert"></div>';
          out.firstChild.textContent = res.error || "Le test n'a pas pu être lancé.";
        }
        out.scrollIntoView({ behavior: "smooth", block: "nearest" });
      });
    });
  });

  $$("[data-host-preset]").forEach(function (select) {
    var hosts = JSON.parse(select.getAttribute("data-hosts") || "{}");
    var form = select.form;
    var connector = (form.querySelector('[name="connector"]') || {}).value || (form.querySelector('[name="c_tls"]') ? "ftp" : "sftp");
    var where = select.closest(".field").querySelector("[data-host-where]");
    var host = form.querySelector('[name="c_host"]');
    var root = form.querySelector('[name="c_remoteRoot"]');
    var port = form.querySelector('[name="c_port"]');
    var lastRoot = root ? root.value : "";
    function apply(initial) {
      var h = hosts[select.value];
      if (!h) return;
      where.textContent = "Où trouver vos accès : " + h.where;
      if (host) host.placeholder = connector === "ftp" ? h.ftp : h.sftp;
      if (root && (!initial || !root.value) && (root.value === lastRoot || !root.value)) {
        root.value = h.root;
        lastRoot = h.root;
      }
      if (port && connector === "sftp" && !initial) port.value = h.port === 22 ? "" : String(h.port);
    }
    select.addEventListener("change", function () { apply(false); });
    apply(true);
  });

  // ---------------------------------------------------------------- mise en route
  function enhance(root) {
    counters(root);
    richtext(root);
  }
  enhance(document);
})();
