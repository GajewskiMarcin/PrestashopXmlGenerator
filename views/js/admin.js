/**
 * views/js/admin.js
 * Drobne ulepszenia UI w BO: kopia URL-i, walidacja JSON, podpowiedzi.
 */
(function () {
  'use strict';

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function copyToClipboard(text) {
    try {
      navigator.clipboard.writeText(text);
      showToast('Skopiowano do schowka.');
    } catch (e) {
      // fallback
      const ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      showToast('Skopiowano (fallback).');
    }
  }

  function showToast(msg, type) {
    // prosta, niezależna od BO, mini notyfikacja
    const toast = document.createElement('div');
    toast.className = 'mgxml-toast ' + (type || '');
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(function () { toast.classList.add('show'); }, 10);
    setTimeout(function () {
      toast.classList.remove('show');
      setTimeout(function () { document.body.removeChild(toast); }, 200);
    }, 2200);
  }

  function bindCopyButtons() {
    // pola free w HelperForm zawierają <div class="well">URL</div>
    $all('label.control-label').forEach(function (label) {
      const txt = label.textContent.trim().toLowerCase();
      if (txt.indexOf('cron url') !== -1 || txt.indexOf('feed url') !== -1) {
        const formGroup = label.closest('.form-group');
        if (!formGroup) return;
        const well = $('div.well', formGroup);
        if (!well) return;

        // dodaj przycisk kopiuj
        let btn = $('.btn-mgxml-copy', formGroup);
        if (!btn) {
          btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn btn-default btn-mgxml-copy';
          btn.style.marginLeft = '10px';
          btn.innerHTML = '<i class="icon-copy"></i> Kopiuj';
          label.appendChild(btn);
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            copyToClipboard(well.textContent.trim());
          });
        }
      }
    });
  }

  function validateJsonField(id, friendlyName) {
    const el = document.getElementById(id);
    if (!el) return true;
    const val = el.value.trim();
    const hintId = id + '_hint';
    let hint = document.getElementById(hintId);

    if (!hint) {
      hint = document.createElement('div');
      hint.id = hintId;
      hint.className = 'mgxml-json-hint';
      el.parentNode.appendChild(hint);
    }

    if (val === '') {
      hint.textContent = friendlyName + ': (puste – użyta będzie konfiguracja domyślna)';
      hint.className = 'mgxml-json-hint hint-ok';
      return true;
    }

    try {
      JSON.parse(val);
      hint.textContent = friendlyName + ': poprawny JSON';
      hint.className = 'mgxml-json-hint hint-ok';
      return true;
    } catch (e) {
      hint.textContent = friendlyName + ': błąd JSON – ' + e.message;
      hint.className = 'mgxml-json-hint hint-err';
      return false;
    }
  }

  function bindJsonValidation() {
    const map = [
      { id: 'languages', name: 'Languages' },
      { id: 'currencies', name: 'Currencies' },
      { id: 'filters', name: 'Filters' },
      { id: 'field_map', name: 'Field map / aliases' }
    ];
    map.forEach(function (m) {
      const ok = validateJsonField(m.id, m.name);
      const el = document.getElementById(m.id);
      if (el) {
        el.addEventListener('input', function () { validateJsonField(m.id, m.name); });
        if (!ok) {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      }
    });
  }

  function injectExamples() {
    const examples = {
      'languages': '["pl","en"]',
      'currencies': '["PLN","EUR"]',
      'filters': JSON.stringify({
        categories: [44, 51],
        manufacturers: [3, 5],
        only_active: true,
        only_available: false,
        min_price: 0
      }, null, 2),
      'field_map': JSON.stringify({
        price_tax_incl: "price_brutto",
        ean13: true,
        upc: false,
        names: true,
        descriptions: true
      }, null, 2)
    };

    Object.keys(examples).forEach(function (id) {
      const el = document.getElementById(id);
      if (!el) return;
      // dodaj link "wstaw przykład"
      const a = document.createElement('a');
      a.href = '#';
      a.className = 'btn btn-default btn-xs';
      a.style.marginLeft = '10px';
      a.innerHTML = '<i class="icon-lightbulb"></i> Wstaw przykład';
      const label = el.closest('.form-group')?.querySelector('label.control-label');
      if (label) {
        label.appendChild(a);
        a.addEventListener('click', function (e) {
          e.preventDefault();
          el.value = examples[id];
          el.dispatchEvent(new Event('input'));
          showToast('Wstawiono przykład do: ' + id);
        });
      }
    });
  }

  function init() {
    bindCopyButtons();
    bindJsonValidation();
    injectExamples();
  }

  // style toasta + hints (minimalne, by nie zależeć od admin.css)
  const css = `
    .mgxml-toast {
      position: fixed; left: 50%; top: 20px; transform: translateX(-50%);
      background: #2e7d32; color: #fff; padding: 8px 12px; border-radius: 4px;
      opacity: 0; transition: opacity .2s ease, transform .2s ease; z-index: 9999; font-size: 13px;
      box-shadow: 0 2px 8px rgba(0,0,0,.2);
    }
    .mgxml-toast.show { opacity: 1; transform: translateX(-50%) translateY(6px); }
    .mgxml-json-hint { margin-top: 4px; font-size: 12px; }
    .mgxml-json-hint.hint-ok { color: #2e7d32; }
    .mgxml-json-hint.hint-err { color: #c62828; font-weight: 600; }
  `;
  const style = document.createElement('style');
  style.textContent = css;
  document.head.appendChild(style);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
