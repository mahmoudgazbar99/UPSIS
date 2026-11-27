(function () {
  'use strict';

  var AUTO_MIN_OPTIONS = 8;
  var NATIVE_NAME_RE = /^(role|sexe|etat_civil|specialite_bac|mode_paiement|period_type|periode_code|categorie|request_type|bulk_type|week_pattern|jour|scope_type|type_seance|responsabilite|period_code)$/i;

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function ensureDialog() {
    var existing = document.getElementById('appListPromptDialog');
    if (existing) return existing;

    var dialog = document.createElement('dialog');
    dialog.id = 'appListPromptDialog';
    dialog.className = 'app-list-prompt group-roster-dialog';
    dialog.setAttribute('aria-labelledby', 'appListPromptTitle');
    dialog.innerHTML =
      '<div class="app-list-prompt-panel group-roster-panel app-card">' +
        '<header class="app-list-prompt-titlebar group-roster-titlebar" aria-hidden="true">' +
          '<div class="group-roster-traffic">' +
            '<button type="button" class="group-roster-dot group-roster-dot-close" data-app-list-close tabindex="-1" aria-hidden="true"></button>' +
            '<span class="group-roster-dot group-roster-dot-min" aria-hidden="true"></span>' +
            '<span class="group-roster-dot group-roster-dot-max" aria-hidden="true"></span>' +
          '</div>' +
          '<p class="group-roster-titlebar-label" data-app-list-titlebar>Liste</p>' +
        '</header>' +
        '<div class="app-list-prompt-head group-roster-panel-head">' +
          '<div>' +
            '<p class="eyebrow">Sélection</p>' +
            '<h2 id="appListPromptTitle" data-app-list-title>Choisir</h2>' +
            '<p class="app-list-prompt-meta" data-app-list-meta></p>' +
          '</div>' +
        '</div>' +
        '<div class="app-list-prompt-search">' +
          '<input type="search" data-app-list-search placeholder="Rechercher…" autocomplete="off" aria-label="Rechercher dans la liste">' +
        '</div>' +
        '<div class="app-list-prompt-body group-roster-panel-body" data-app-list-body role="listbox"></div>' +
        '<div class="app-list-prompt-footer">' +
          '<button type="button" class="btn btn-outline-light group-roster-close-btn" data-app-list-close>Annuler</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(dialog);
    return dialog;
  }

  function normalizeOptions(options) {
    return (options || []).map(function (item) {
      if (typeof item === 'string') {
        return { value: item, label: item, search: item.toLowerCase(), meta: '' };
      }
      var label = String(item.label || item.text || item.value || '');
      return {
        value: String(item.value == null ? '' : item.value),
        label: label,
        search: String(item.search || label).toLowerCase(),
        meta: String(item.meta || ''),
        semester: item.semester || item['data-semester'] || ''
      };
    }).filter(function (item) {
      return item.value !== '';
    });
  }

  function openPrompt(config) {
    var dialog = ensureDialog();
    if (typeof dialog.showModal !== 'function') {
      return Promise.reject(new Error('dialog-unsupported'));
    }

    var titleEl = dialog.querySelector('[data-app-list-title]');
    var titlebarEl = dialog.querySelector('[data-app-list-titlebar]');
    var metaEl = dialog.querySelector('[data-app-list-meta]');
    var searchEl = dialog.querySelector('[data-app-list-search]');
    var bodyEl = dialog.querySelector('[data-app-list-body]');
    var options = normalizeOptions(config.options);
    var currentValue = String(config.value || '');
    var activeIndex = -1;
    var visible = [];
    var previousFocus = document.activeElement;

    if (titleEl) titleEl.textContent = config.title || 'Choisir';
    if (titlebarEl) titlebarEl.textContent = config.title || 'Choisir';
    if (metaEl) metaEl.textContent = config.subtitle || (options.length + ' élément(s)');
    if (searchEl) {
      searchEl.value = '';
      searchEl.placeholder = config.searchPlaceholder || 'Rechercher…';
    }

    function render(filter) {
      var q = String(filter || '').trim().toLowerCase();
      visible = options.filter(function (item) {
        return q === '' || item.search.indexOf(q) !== -1;
      });
      if (metaEl && !config.subtitle) {
        metaEl.textContent = visible.length + ' résultat(s)';
      }
      if (!bodyEl) return;
      if (!visible.length) {
        bodyEl.innerHTML = '<div class="app-list-prompt-empty">Aucun résultat.</div>';
        activeIndex = -1;
        return;
      }
      bodyEl.innerHTML = visible.map(function (item, index) {
        var selected = item.value === currentValue;
        return '<button type="button" class="app-list-prompt-item' + (selected ? ' is-active' : '') + '"' +
          ' role="option" data-index="' + index + '" data-value="' + escapeHtml(item.value) + '"' +
          ' aria-selected="' + (selected ? 'true' : 'false') + '">' +
          '<span><span class="app-list-prompt-item-label">' + escapeHtml(item.label) + '</span>' +
          (item.meta ? '<span class="app-list-prompt-item-meta">' + escapeHtml(item.meta) + '</span>' : '') +
          '</span></button>';
      }).join('');
      activeIndex = Math.max(0, visible.findIndex(function (item) {
        return item.value === currentValue;
      }));
      paintActive();
    }

    function paintActive() {
      var items = bodyEl ? bodyEl.querySelectorAll('.app-list-prompt-item') : [];
      items.forEach(function (item, index) {
        item.classList.toggle('is-active', index === activeIndex);
        item.setAttribute('aria-selected', index === activeIndex ? 'true' : 'false');
      });
      if (activeIndex >= 0 && items[activeIndex]) {
        items[activeIndex].scrollIntoView({ block: 'nearest' });
      }
    }

    return new Promise(function (resolve) {
      var settled = false;

      function finish(result) {
        if (settled) return;
        settled = true;
        cleanup();
        if (dialog.open) dialog.close();
        if (previousFocus && typeof previousFocus.focus === 'function') {
          setTimeout(function () {
            previousFocus.focus();
          }, 0);
        }
        resolve(result);
      }

      function onPick(value) {
        var match = options.find(function (item) {
          return item.value === String(value);
        });
        finish(match || null);
      }

      function onBodyClick(event) {
        var button = event.target.closest('.app-list-prompt-item');
        if (!button) return;
        onPick(button.getAttribute('data-value'));
      }

      function onSearchInput() {
        render(searchEl.value);
      }

      function onKeyDown(event) {
        if (event.key === 'Escape') {
          finish(null);
          return;
        }
        if (event.key === 'ArrowDown') {
          event.preventDefault();
          if (!visible.length) return;
          activeIndex = Math.min(activeIndex + 1, visible.length - 1);
          paintActive();
        } else if (event.key === 'ArrowUp') {
          event.preventDefault();
          if (!visible.length) return;
          activeIndex = Math.max(activeIndex - 1, 0);
          paintActive();
        } else if (event.key === 'Enter') {
          if (activeIndex >= 0 && visible[activeIndex]) {
            event.preventDefault();
            onPick(visible[activeIndex].value);
          }
        }
      }

      function onCloseClick() {
        finish(null);
      }

      function onDialogClose() {
        finish(null);
      }

      function cleanup() {
        if (bodyEl) bodyEl.removeEventListener('click', onBodyClick);
        if (searchEl) searchEl.removeEventListener('input', onSearchInput);
        dialog.removeEventListener('keydown', onKeyDown);
        dialog.removeEventListener('close', onDialogClose);
        dialog.querySelectorAll('[data-app-list-close]').forEach(function (btn) {
          btn.removeEventListener('click', onCloseClick);
        });
      }

      if (bodyEl) bodyEl.addEventListener('click', onBodyClick);
      if (searchEl) searchEl.addEventListener('input', onSearchInput);
      dialog.addEventListener('keydown', onKeyDown);
      dialog.addEventListener('close', onDialogClose);
      dialog.querySelectorAll('[data-app-list-close]').forEach(function (btn) {
        btn.addEventListener('click', onCloseClick);
      });

      render('');
      dialog.showModal();
      if (searchEl) {
        setTimeout(function () {
          searchEl.focus();
        }, 20);
      }
    });
  }

  function optionsFromSelect(select) {
    return Array.from(select.options).filter(function (option) {
      return option.value !== '' && !option.hidden && !option.disabled;
    }).map(function (option) {
      return {
        value: option.value,
        label: option.textContent.trim(),
        search: (option.getAttribute('data-label') || option.textContent).toLowerCase(),
        meta: option.getAttribute('data-meta') || '',
        semester: option.getAttribute('data-semester') || ''
      };
    });
  }

  function valuedOptionCount(select) {
    return Array.from(select.options).filter(function (option) {
      return option.value !== '';
    }).length;
  }

  function inferTitle(select) {
    if (select.getAttribute('data-app-list-title')) {
      return select.getAttribute('data-app-list-title');
    }
    if (select.getAttribute('aria-label')) {
      return select.getAttribute('aria-label');
    }
    if (select.id) {
      var label = document.querySelector('label[for="' + select.id + '"]');
      if (label) return label.textContent.replace(/\*$/, '').trim() || 'Choisir';
    }
    return 'Choisir';
  }

  function selectedLabel(select) {
    var option = select.selectedOptions && select.selectedOptions[0];
    if (!option || !option.value || option.hidden || option.disabled) {
      return select.getAttribute('data-app-list-placeholder') || 'Choisir…';
    }
    return option.textContent.trim();
  }

  function shouldAutoEnhance(select) {
    if (!select || select.tagName !== 'SELECT') return false;
    if (select.hasAttribute('data-app-list-prompt')) return true;
    if (select.hasAttribute('data-app-list-native')) return false;
    if (select.multiple || select.size > 1) return false;
    if (select.classList.contains('theme-style-select')) return false;
    if (select.hasAttribute('data-style-select')) return false;
    if (select.hasAttribute('data-course-finder-teacher') || select.hasAttribute('data-course-finder-subject')) return false;
    if (NATIVE_NAME_RE.test(select.name || '') || NATIVE_NAME_RE.test(select.id || '')) return false;
    if (select.closest('[data-app-list-skip]')) return false;
    return valuedOptionCount(select) >= AUTO_MIN_OPTIONS;
  }

  function enhanceSelect(select) {
    if (!select || select.dataset.appListReady === '1') return;
    if (!shouldAutoEnhance(select) && !select.hasAttribute('data-app-list-prompt')) return;
    select.dataset.appListReady = '1';
    select.setAttribute('data-app-list-prompt', '');

    var wrap = document.createElement('div');
    wrap.className = 'app-list-prompt-field';
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(select);

    select.classList.add('visually-hidden');
    select.setAttribute('tabindex', '-1');
    select.setAttribute('aria-hidden', 'true');

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'form-control form-select app-list-prompt-trigger';
    if (select.classList.contains('form-select-sm')) {
      trigger.classList.add('form-select-sm');
    } else if (select.classList.contains('form-select-lg')) {
      trigger.classList.add('form-select-lg');
    }
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.innerHTML =
      '<span class="app-list-prompt-trigger-label"></span>' +
      '<span class="app-list-prompt-trigger-hint" aria-hidden="true">liste</span>';
    wrap.insertBefore(trigger, select);

    var labelEl = trigger.querySelector('.app-list-prompt-trigger-label');

    function syncLabel() {
      labelEl.textContent = selectedLabel(select);
      trigger.disabled = !!select.disabled;
      trigger.setAttribute('aria-label', inferTitle(select) + ' : ' + labelEl.textContent);
    }

    trigger.addEventListener('click', function () {
      if (select.disabled) return;
      openPrompt({
        title: inferTitle(select),
        subtitle: select.getAttribute('data-app-list-subtitle') || '',
        searchPlaceholder: select.getAttribute('data-app-list-search') || 'Rechercher…',
        options: optionsFromSelect(select),
        value: select.value
      }).then(function (picked) {
        if (!picked) return;
        select.value = picked.value;
        syncLabel();
        select.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });

    select.addEventListener('change', syncLabel);

    if (typeof MutationObserver === 'function') {
      var observer = new MutationObserver(function () {
        syncLabel();
      });
      observer.observe(select, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'hidden', 'selected', 'value'] });
    }

    syncLabel();
  }

  function boot(root) {
    var scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll('select').forEach(function (select) {
      if (shouldAutoEnhance(select)) enhanceSelect(select);
    });
  }

  window.AppListPrompt = {
    open: openPrompt,
    enhanceSelect: enhanceSelect,
    boot: boot
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      boot();
    });
  } else {
    boot();
  }
})();
