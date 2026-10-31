/* Course library explorer — tree, view toggle, teacher modals, PDF dropzone. ES5, no build. */
(function () {
  'use strict';

  var VIEW_KEY = 'course-explorer-view';
  var DESKTOP_QUERY = '(min-width: 992px)';

  function each(list, fn) {
    Array.prototype.forEach.call(list || [], fn);
  }

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function storageGet(key) {
    try { return window.localStorage.getItem(key); } catch (error) { return null; }
  }

  function storageSet(key, value) {
    try { window.localStorage.setItem(key, value); } catch (error) { /* private mode */ }
  }

  function bootstrapModal() {
    var bs = window.bootstrap;
    return bs && bs.Modal && typeof bs.Modal.getOrCreateInstance === 'function' ? bs.Modal : null;
  }

  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' o';
    if (bytes < 1048576) return Math.round(bytes / 1024) + ' Ko';
    return (bytes / 1048576).toFixed(1).replace(/\.0$/, '').replace('.', ',') + ' Mo';
  }

  function plural(count, word) {
    return count + ' ' + word + (count > 1 ? 's' : '');
  }

  /* ---------------------------------------------------------------- */
  /* Tree                                                              */
  /* ---------------------------------------------------------------- */

  function treeItemOf(el) {
    return el ? el.closest('[role="treeitem"]') : null;
  }

  function itemLink(item) {
    return item ? item.querySelector(':scope > .course-tree-row > [data-course-tree-link]') : null;
  }

  function itemGroup(item) {
    return item ? item.querySelector(':scope > [role="group"]') : null;
  }

  function setExpanded(item, expanded) {
    var group = itemGroup(item);
    if (!group) return;
    item.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    group.hidden = !expanded;
    var toggle = item.querySelector(':scope > .course-tree-row > [data-course-tree-toggle]');
    var link = itemLink(item);
    if (toggle && link) {
      var name = (link.textContent || '').replace(/\s+/g, ' ').trim();
      toggle.setAttribute('aria-label', (expanded ? 'Replier' : 'Déplier') + ' « ' + name + ' »');
    }
  }

  function visibleTreeLinks(scroll) {
    var links = [];
    each(scroll.querySelectorAll('[data-course-tree-link]'), function (link) {
      var hiddenGroup = link.closest('[role="group"][hidden]');
      if (!hiddenGroup) links.push(link);
    });
    return links;
  }

  function setRoving(links, active) {
    each(links, function (link) {
      link.setAttribute('tabindex', link === active ? '0' : '-1');
    });
  }

  function initTree(root) {
    var scroll = root.querySelector('.course-tree-scroll');
    if (!scroll) return;
    var allLinks = scroll.querySelectorAll('[data-course-tree-link]');
    if (!allLinks.length) return;

    each(scroll.querySelectorAll('[data-course-tree-toggle]'), function (toggle) {
      toggle.setAttribute('tabindex', '-1');
      toggle.addEventListener('click', function () {
        var item = treeItemOf(toggle);
        if (item) setExpanded(item, item.getAttribute('aria-expanded') !== 'true');
      });
    });

    var current = scroll.querySelector('[data-course-tree-link][aria-current="page"]') || allLinks[0];
    setRoving(allLinks, current);

    scroll.addEventListener('focusin', function (event) {
      var link = event.target.closest && event.target.closest('[data-course-tree-link]');
      if (link) setRoving(scroll.querySelectorAll('[data-course-tree-link]'), link);
    });

    scroll.addEventListener('keydown', function (event) {
      var link = event.target.closest && event.target.closest('[data-course-tree-link]');
      if (!link || event.altKey || event.ctrlKey || event.metaKey) return;
      var links = visibleTreeLinks(scroll);
      var index = links.indexOf(link);
      var item = treeItemOf(link);
      var target = null;

      switch (event.key) {
        case 'ArrowDown':
          target = links[index + 1] || null;
          break;
        case 'ArrowUp':
          target = links[index - 1] || null;
          break;
        case 'Home':
          target = links[0];
          break;
        case 'End':
          target = links[links.length - 1];
          break;
        case 'ArrowRight':
          if (item && itemGroup(item)) {
            if (item.getAttribute('aria-expanded') !== 'true') {
              setExpanded(item, true);
            } else {
              target = itemLink(itemGroup(item).querySelector('[role="treeitem"]'));
            }
          }
          break;
        case 'ArrowLeft':
          if (item && itemGroup(item) && item.getAttribute('aria-expanded') === 'true') {
            setExpanded(item, false);
          } else if (item) {
            var parentGroup = item.parentElement && item.parentElement.closest('[role="group"]');
            target = parentGroup ? itemLink(treeItemOf(parentGroup)) : scroll.querySelector('.course-tree-root');
          }
          break;
        case ' ':
          link.click();
          break;
        default:
          return;
      }
      event.preventDefault();
      if (target && target !== link) {
        setRoving(scroll.querySelectorAll('[data-course-tree-link]'), target);
        target.focus();
      }
    });
  }

  function initTreeDetails(root) {
    var details = root.querySelector('[data-course-tree-details]');
    if (!details || !window.matchMedia) return;
    var query = window.matchMedia(DESKTOP_QUERY);
    function sync() {
      if (query.matches) details.open = true;
    }
    if (!query.matches) details.open = false;
    if (query.addEventListener) query.addEventListener('change', sync);
    else if (query.addListener) query.addListener(sync);
  }

  /* ---------------------------------------------------------------- */
  /* Grid / list toggle                                                */
  /* ---------------------------------------------------------------- */

  function applyView(root, view) {
    var mode = view === 'list' ? 'list' : 'grid';
    each(root.querySelectorAll('[data-course-grid]'), function (grid) {
      grid.setAttribute('data-view', mode);
    });
    each(root.querySelectorAll('[data-course-view]'), function (button) {
      button.setAttribute('aria-pressed', button.getAttribute('data-course-view') === mode ? 'true' : 'false');
    });
  }

  function initViewToggle(root) {
    applyView(root, storageGet(VIEW_KEY));
    each(root.querySelectorAll('[data-course-view]'), function (button) {
      button.addEventListener('click', function () {
        var view = button.getAttribute('data-course-view');
        storageSet(VIEW_KEY, view);
        each(document.querySelectorAll('[data-course-explorer]'), function (explorer) {
          applyView(explorer, view);
        });
      });
    });
  }

  /* ---------------------------------------------------------------- */
  /* Modals                                                            */
  /* ---------------------------------------------------------------- */

  var fallbackOpen = null;
  var lastOpener = null;

  function focusFirst(modal) {
    var target = modal.querySelector('[data-course-autofocus]:not([disabled])')
      || modal.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), button:not([disabled])');
    if (!target) return;
    target.focus();
    if (target.tagName === 'INPUT' && typeof target.select === 'function') target.select();
  }

  function restoreOpener() {
    if (lastOpener && document.body.contains(lastOpener)) lastOpener.focus();
    lastOpener = null;
  }

  function closeFallback() {
    if (!fallbackOpen) return;
    var modal = fallbackOpen;
    fallbackOpen = null;
    modal.classList.remove('is-fallback-open', 'show');
    modal.style.display = '';
    modal.setAttribute('aria-hidden', 'true');
    modal.removeAttribute('aria-modal');
    modal.removeAttribute('role');
    document.body.classList.remove('modal-open');
    restoreOpener();
  }

  function openModal(modal, opener) {
    lastOpener = opener || null;
    var Modal = bootstrapModal();
    if (Modal) {
      Modal.getOrCreateInstance(modal).show();
      return;
    }
    closeFallback();
    fallbackOpen = modal;
    modal.classList.add('is-fallback-open', 'show');
    modal.style.display = 'block';
    modal.removeAttribute('aria-hidden');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('role', 'dialog');
    document.body.classList.add('modal-open');
    focusFirst(modal);
  }

  function prepareModal(modal) {
    if (modal.parentElement !== document.body) document.body.appendChild(modal);
    modal.addEventListener('shown.bs.modal', function () { focusFirst(modal); });
    modal.addEventListener('hidden.bs.modal', restoreOpener);
    modal.addEventListener('click', function (event) {
      if (!fallbackOpen || fallbackOpen !== modal) return;
      if (event.target === modal || (event.target.closest && event.target.closest('[data-bs-dismiss="modal"]'))) {
        event.preventDefault();
        closeFallback();
      }
    });
    each(modal.querySelectorAll('form[data-course-form]'), function (form) {
      form.addEventListener('submit', function (event) {
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;
        if (form.getAttribute('aria-busy') === 'true') {
          event.preventDefault();
          return;
        }
        form.setAttribute('aria-busy', 'true');
        each(form.querySelectorAll('button[type="submit"]'), function (button) {
          var label = button.getAttribute('data-busy-label');
          var text = button.querySelector('span') || button;
          if (label) {
            button.setAttribute('data-idle-label', text.textContent);
            text.textContent = label;
          }
          window.setTimeout(function () { button.disabled = true; }, 0);
        });
      });
    });
  }

  function resetBusy(scope) {
    each(scope.querySelectorAll('form[aria-busy="true"]'), function (form) {
      form.removeAttribute('aria-busy');
      each(form.querySelectorAll('button[type="submit"]'), function (button) {
        var idle = button.getAttribute('data-idle-label');
        var text = button.querySelector('span') || button;
        if (idle) text.textContent = idle;
        if (!button.hasAttribute('data-course-upload-submit')) button.disabled = false;
      });
    });
  }

  function field(modal, name) {
    return modal.querySelector('[data-course-field="' + name + '"]');
  }

  var KIND_TEXT = {
    course: { rename: 'Renommer le cours', del: 'Supprimer le cours « %s » et tout son contenu ?' },
    folder: { rename: 'Renommer le dossier', del: 'Supprimer le dossier « %s » et tout son contenu ?' },
    file: { rename: 'Renommer le fichier', del: 'Supprimer le fichier « %s » ?' }
  };

  function fillActionModal(modal, button, root, type) {
    var kind = button.getAttribute('data-kind');
    if (!KIND_TEXT[kind]) kind = 'folder';
    var isFile = kind === 'file';
    var id = button.getAttribute('data-id') || '';
    var name = button.getAttribute('data-name') || '';
    var back = button.hasAttribute('data-return')
      ? button.getAttribute('data-return')
      : (root.getAttribute('data-current-folder') || '0');

    var action = field(modal, 'action');
    var idInput = field(modal, 'id');
    var returnInput = field(modal, 'return');
    var title = modal.querySelector('[data-course-modal-title]');

    if (action) action.value = (type === 'rename' ? 'rename_' : 'delete_') + (isFile ? 'file' : 'folder');
    if (idInput) {
      idInput.name = isFile ? 'id_fichier' : 'id_dossier';
      idInput.value = id;
    }
    if (returnInput) returnInput.value = back;

    if (type === 'rename') {
      if (title) title.textContent = KIND_TEXT[kind].rename;
      var nameInput = field(modal, 'name');
      if (nameInput) nameInput.value = name;
    } else {
      var message = field(modal, 'message');
      if (message) message.textContent = KIND_TEXT[kind].del.replace('%s', function () { return name; });
    }
  }

  function initModals(root) {
    each(root.querySelectorAll('[data-course-modal]'), prepareModal);

    root.addEventListener('click', function (event) {
      var opener = event.target.closest && event.target.closest('[data-course-open], [data-course-action]');
      if (!opener || !root.contains(opener)) return;
      var modal;
      if (opener.hasAttribute('data-course-open')) {
        modal = document.getElementById(opener.getAttribute('data-course-open'));
      } else {
        var type = opener.getAttribute('data-course-action') === 'delete' ? 'delete' : 'rename';
        modal = document.getElementById(type === 'delete' ? 'courseDeleteModal' : 'courseRenameModal');
        if (modal) fillActionModal(modal, opener, root, type);
      }
      if (!modal) return;
      event.preventDefault();
      resetBusy(modal);
      openModal(modal, opener);
    });
  }

  /* ---------------------------------------------------------------- */
  /* Upload dropzone                                                   */
  /* ---------------------------------------------------------------- */

  function makePdfIcon() {
    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    var attrs = {
      'class': 'ce-icon ce-icon-pdf', viewBox: '0 0 24 24', width: '16', height: '16', fill: 'none',
      stroke: 'currentColor', 'stroke-width': '1.75', 'stroke-linecap': 'round', 'stroke-linejoin': 'round',
      'aria-hidden': 'true', focusable: 'false'
    };
    Object.keys(attrs).forEach(function (key) { svg.setAttribute(key, attrs[key]); });
    ['M14 3.5H7.5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2V8z', 'M14 3.5V8h4.5', 'M9 13h6M9 16.5h4'].forEach(function (d) {
      var path = document.createElementNS(ns, 'path');
      path.setAttribute('d', d);
      svg.appendChild(path);
    });
    return svg;
  }

  function isPdf(file) {
    return /\.pdf$/i.test(file.name || '') && (!file.type || file.type === 'application/pdf' || file.type === 'application/x-pdf');
  }

  function initUpload(root) {
    var form = root.querySelector('[data-course-upload]');
    if (!form) return;
    var input = form.querySelector('[data-course-upload-input]');
    var zone = form.querySelector('[data-course-dropzone]');
    var submit = form.querySelector('[data-course-upload-submit]');
    var clear = form.querySelector('[data-course-upload-clear]');
    var summary = form.querySelector('[data-course-upload-summary]');
    var list = form.querySelector('[data-course-upload-list]');
    var errorsEl = form.querySelector('[data-course-upload-errors]');
    var trigger = root.querySelector('[data-course-upload-trigger]');
    var pane = root.querySelector('[data-course-pane]');
    var maxFiles = parseInt(form.getAttribute('data-max-files'), 10) || 20;
    var maxBytes = parseInt(form.getAttribute('data-max-bytes'), 10) || 26214400;
    var maxTotal = parseInt(form.getAttribute('data-max-total'), 10) || 0;
    var maxLabel = formatSize(maxBytes);
    var pdfIcon = makePdfIcon();
    if (!input || !submit) return;

    function clearChildren(el) {
      while (el && el.firstChild) el.removeChild(el.firstChild);
    }

    function validate(files) {
      var errors = [];
      if (files.length > maxFiles) {
        errors.push('Vous pouvez importer ' + maxFiles + ' fichiers au maximum (' + files.length + ' sélectionnés).');
      }
      if (maxTotal > 0) {
        var selectedTotal = 0;
        each(files, function (file) { selectedTotal += file.size; });
        if (selectedTotal > maxTotal) {
          errors.push('L’import dépasse ' + formatSize(maxTotal) + ' au total (' + formatSize(selectedTotal) + '). Importez en plusieurs fois.');
        }
      }
      each(files, function (file) {
        if (!isPdf(file)) {
          errors.push('« ' + file.name + ' » n’est pas un fichier PDF.');
        } else if (file.size > maxBytes) {
          errors.push('« ' + file.name + ' » dépasse ' + maxLabel + ' (' + formatSize(file.size) + ').');
        } else if (file.size === 0) {
          errors.push('« ' + file.name + ' » est vide.');
        }
      });
      return errors;
    }

    function render() {
      var files = input.files || [];
      var errors = validate(files);
      var total = 0;
      clearChildren(list);
      clearChildren(errorsEl);

      each(files, function (file) {
        total += file.size;
        var li = document.createElement('li');
        if (pdfIcon) li.appendChild(pdfIcon.cloneNode(true));
        var nameEl = document.createElement('span');
        nameEl.className = 'course-upload-file-name';
        nameEl.setAttribute('dir', 'auto');
        nameEl.textContent = file.name;
        nameEl.title = file.name;
        var sizeEl = document.createElement('span');
        sizeEl.className = 'course-upload-file-size';
        sizeEl.textContent = formatSize(file.size);
        li.appendChild(nameEl);
        li.appendChild(sizeEl);
        list.appendChild(li);
      });

      each(errors, function (message) {
        var li = document.createElement('li');
        li.textContent = message;
        errorsEl.appendChild(li);
      });

      if (summary) {
        summary.hidden = files.length === 0;
        summary.textContent = files.length ? plural(files.length, 'fichier') + ' sélectionné' + (files.length > 1 ? 's' : '') + ' · ' + formatSize(total) : '';
      }
      list.hidden = files.length === 0;
      errorsEl.hidden = errors.length === 0;
      if (clear) clear.hidden = files.length === 0;
      if (zone) zone.classList.toggle('is-invalid', errors.length > 0);
      submit.disabled = files.length === 0 || errors.length > 0;
      return errors;
    }

    function assignFiles(fileList) {
      if (!fileList || !fileList.length) return;
      try {
        input.files = fileList;
      } catch (error) {
        clearChildren(errorsEl);
        var li = document.createElement('li');
        li.textContent = 'Le glisser-déposer n’est pas pris en charge par ce navigateur : utilisez « parcourez vos fichiers ».';
        errorsEl.appendChild(li);
        errorsEl.hidden = false;
        return;
      }
      render();
    }

    function hasFiles(event) {
      var types = event.dataTransfer && event.dataTransfer.types;
      if (!types) return false;
      for (var i = 0; i < types.length; i += 1) {
        if (types[i] === 'Files') return true;
      }
      return false;
    }

    var depth = 0;
    var dropTarget = pane || zone;
    if (dropTarget && zone) {
      dropTarget.addEventListener('dragenter', function (event) {
        if (!hasFiles(event)) return;
        event.preventDefault();
        depth += 1;
        zone.classList.add('is-dragover');
      });
      dropTarget.addEventListener('dragover', function (event) {
        if (!hasFiles(event)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
      });
      dropTarget.addEventListener('dragleave', function () {
        depth = Math.max(0, depth - 1);
        if (depth === 0) zone.classList.remove('is-dragover');
      });
      dropTarget.addEventListener('drop', function (event) {
        if (!hasFiles(event)) return;
        event.preventDefault();
        depth = 0;
        zone.classList.remove('is-dragover');
        assignFiles(event.dataTransfer.files);
      });
    }

    input.addEventListener('change', render);

    if (clear) {
      clear.addEventListener('click', function () {
        input.value = '';
        render();
        input.focus();
      });
    }

    if (trigger) {
      trigger.hidden = false;
      trigger.addEventListener('click', function () {
        input.click();
      });
    }

    form.addEventListener('submit', function (event) {
      if (form.getAttribute('aria-busy') === 'true') {
        event.preventDefault();
        return;
      }
      var files = input.files || [];
      var errors = render();
      if (!files.length || errors.length) {
        event.preventDefault();
        if (!files.length) {
          clearChildren(errorsEl);
          var li = document.createElement('li');
          li.textContent = 'Choisissez au moins un fichier PDF.';
          errorsEl.appendChild(li);
          errorsEl.hidden = false;
        }
        input.focus();
        return;
      }
      form.setAttribute('aria-busy', 'true');
      var label = submit.querySelector('span') || submit;
      submit.setAttribute('data-idle-label', label.textContent);
      label.textContent = submit.getAttribute('data-busy-label') || 'Import en cours…';
      window.setTimeout(function () { submit.disabled = true; }, 0);
    });

    window.addEventListener('pageshow', function (event) {
      if (!event.persisted) return;
      form.removeAttribute('aria-busy');
      var label = submit.querySelector('span') || submit;
      var idle = submit.getAttribute('data-idle-label');
      if (idle) label.textContent = idle;
      render();
    });

    render();
  }

  /* ---------------------------------------------------------------- */
  /* PDF preview                                                       */
  /* ---------------------------------------------------------------- */

  var pdfLayer = null;
  var pdfLastOpener = null;
  var pdfPreviousOverflow = '';

  function downloadHref(href) {
    if (!href) return href;
    if (/[?&]action=download\b/.test(href)) return href;
    if (/[?&]action=view\b/.test(href)) return href.replace(/([?&]action=)view\b/, '$1download');
    return href + (href.indexOf('?') === -1 ? '?' : '&') + 'action=download';
  }

  function previewTitleOf(el, fallbackHref) {
    return (el && (el.getAttribute('data-course-preview-title') || el.getAttribute('title') || el.getAttribute('aria-label')))
      || fallbackHref
      || 'Aperçu PDF';
  }

  function ensurePdfLayer() {
    if (pdfLayer && document.body.contains(pdfLayer)) return pdfLayer;
    pdfLayer = document.createElement('div');
    pdfLayer.className = 'course-pdf-layer course-explorer';
    pdfLayer.setAttribute('hidden', '');
    pdfLayer.innerHTML =
      '<div class="course-pdf-backdrop" data-course-pdf-dismiss></div>' +
      '<div class="course-pdf-dialog" role="dialog" aria-modal="true" aria-labelledby="coursePdfTitle">' +
        '<div class="course-pdf-toolbar">' +
          '<h2 class="course-pdf-title" id="coursePdfTitle">Aperçu PDF</h2>' +
          '<a class="course-icon-btn" data-course-pdf-download href="#" download aria-label="Télécharger" title="Télécharger"></a>' +
          '<button type="button" class="course-icon-btn" data-course-pdf-dismiss aria-label="Fermer l’aperçu" title="Fermer"></button>' +
        '</div>' +
        '<iframe class="course-pdf-frame" title="Aperçu PDF"></iframe>' +
      '</div>';
    var download = pdfLayer.querySelector('[data-course-pdf-download]');
    var close = pdfLayer.querySelector('button[data-course-pdf-dismiss]');
    download.appendChild(makePdfIcon());
    close.appendChild((function () {
      var ns = 'http://www.w3.org/2000/svg';
      var svg = document.createElementNS(ns, 'svg');
      var attrs = {
        'class': 'ce-icon', viewBox: '0 0 24 24', width: '16', height: '16', fill: 'none',
        stroke: 'currentColor', 'stroke-width': '1.75', 'stroke-linecap': 'round', 'stroke-linejoin': 'round',
        'aria-hidden': 'true', focusable: 'false'
      };
      Object.keys(attrs).forEach(function (key) { svg.setAttribute(key, attrs[key]); });
      var path = document.createElementNS(ns, 'path');
      path.setAttribute('d', 'M6 6l12 12M18 6L6 18');
      svg.appendChild(path);
      return svg;
    })());
    pdfLayer.addEventListener('click', function (event) {
      if (event.target.closest && event.target.closest('[data-course-pdf-dismiss]')) {
        event.preventDefault();
        closePdfPreview();
      }
    });
    document.body.appendChild(pdfLayer);
    return pdfLayer;
  }

  function closePdfPreview() {
    if (!pdfLayer || pdfLayer.hasAttribute('hidden')) return;
    var frame = pdfLayer.querySelector('iframe');
    pdfLayer.setAttribute('hidden', '');
    pdfLayer.setAttribute('aria-hidden', 'true');
    if (frame) frame.removeAttribute('src');
    document.body.classList.remove('course-pdf-open');
    document.body.style.overflow = pdfPreviousOverflow;
    if (pdfLastOpener && document.body.contains(pdfLastOpener) && typeof pdfLastOpener.focus === 'function') {
      pdfLastOpener.focus();
    }
    pdfLastOpener = null;
  }

  function openPdfPreview(href, title, opener) {
    if (!href) return;
    var layer = ensurePdfLayer();
    var frame = layer.querySelector('iframe');
    var heading = layer.querySelector('#coursePdfTitle');
    var download = layer.querySelector('[data-course-pdf-download]');
    pdfLastOpener = opener || document.activeElement;
    if (heading) heading.textContent = title || 'Aperçu PDF';
    if (frame) {
      frame.setAttribute('title', title || 'Aperçu PDF');
      frame.src = href;
    }
    if (download) download.href = downloadHref(href);
    layer.removeAttribute('hidden');
    layer.removeAttribute('aria-hidden');
    pdfPreviousOverflow = document.body.style.overflow;
    document.body.classList.add('course-pdf-open');
    document.body.style.overflow = 'hidden';
    var close = layer.querySelector('button[data-course-pdf-dismiss]');
    if (close) close.focus();
  }

  /* ---------------------------------------------------------------- */
  /* Student in-place navigation                                       */
  /* ---------------------------------------------------------------- */

  var navToken = 0;
  var navAbort = null;

  function currentStudentExplorer() {
    return document.querySelector('[data-course-explorer][data-mode="student"]');
  }

  function isCourseNavHref(href) {
    if (!href) return false;
    try {
      var url = new URL(href, window.location.href);
      var leaf = url.pathname.split('/').pop();
      if (leaf !== 'dashboard.php' && leaf !== 'dashboard') return false;
      if (url.searchParams.get('panel') !== 'cours') return false;
      var allowed = { panel: true, cours: true, dossier: true, fragment: true };
      var keys = [];
      url.searchParams.forEach(function (_value, key) { keys.push(key); });
      for (var i = 0; i < keys.length; i += 1) {
        if (!allowed[keys[i]]) return false;
      }
      var course = url.searchParams.get('cours');
      var folder = url.searchParams.get('dossier');
      var fragment = url.searchParams.get('fragment');
      if (course !== null && !/^\d{1,10}$/.test(course)) return false;
      if (folder !== null && !/^\d{1,10}$/.test(folder)) return false;
      if (folder !== null && course === null) return false;
      if (fragment !== null && fragment !== 'explorer') return false;
      return true;
    } catch (error) {
      return false;
    }
  }

  function fragmentHref(href) {
    var url = new URL(href, window.location.href);
    url.searchParams.set('panel', 'cours');
    url.searchParams.set('fragment', 'explorer');
    return url.pathname + url.search;
  }

  function displayHref(href) {
    var next = new URL(href, window.location.href);
    var current = new URL(window.location.href);
    current.search = next.search;
    current.searchParams.set('panel', 'cours');
    current.searchParams.delete('fragment');
    return current.pathname + current.search + current.hash;
  }

  function announceExplorer(root) {
    var title = root && root.querySelector('#courseExplorerTitle');
    var message = title ? String(title.textContent || '').replace(/\s+/g, ' ').trim() : '';
    if (!message) return;
    if (window.studentSpace && typeof window.studentSpace.announce === 'function') {
      window.studentSpace.announce('Dossier ouvert : ' + message);
    }
  }

  function swapStudentExplorer(html, href, push) {
    var current = currentStudentExplorer();
    var host = current && current.parentNode;
    if (!host) {
      window.location.assign(href);
      return;
    }
    var wrap = document.createElement('div');
    wrap.innerHTML = String(html || '').trim();
    var next = wrap.querySelector('[data-course-explorer][data-mode="student"]');
    if (!next) {
      window.location.assign(href);
      return;
    }
    current.replaceWith(next);
    next.classList.add('is-entering');
    init(next);
    announceExplorer(next);
    if (push && window.history && typeof window.history.pushState === 'function') {
      window.history.pushState({ courseExplorer: true }, '', displayHref(href));
    }
    var hint = document.getElementById('studentCourseFresh');
    if (hint) hint.hidden = true;
  }

  function navigateExplorer(href, options) {
    var opts = options || {};
    if (!isCourseNavHref(href)) {
      if (href) window.location.assign(href);
      return;
    }
    if (window.studentSpace && typeof window.studentSpace.openPanel === 'function') {
      window.studentSpace.openPanel('cours', { silent: true });
    }
    var current = currentStudentExplorer();
    if (!current) {
      window.location.assign(displayHref(href));
      return;
    }
    var token = (navToken += 1);
    if (navAbort && typeof navAbort.abort === 'function') navAbort.abort();
    navAbort = typeof AbortController === 'function' ? new AbortController() : null;
    current.setAttribute('aria-busy', 'true');
    var request = {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'text/html' }
    };
    if (navAbort) request.signal = navAbort.signal;
    fetch(fragmentHref(href), request)
      .then(function (response) {
        if (!response.ok) throw new Error('explorer');
        return response.text();
      })
      .then(function (html) {
        if (token !== navToken) return;
        swapStudentExplorer(html, href, opts.push !== false);
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') return;
        if (token !== navToken) return;
        if (opts.fallback !== false) window.location.assign(displayHref(href));
        else {
          var explorer = currentStudentExplorer();
          if (explorer) explorer.removeAttribute('aria-busy');
        }
      });
  }

  function reloadExplorer() {
    navigateExplorer(window.location.pathname + window.location.search, { push: false, fallback: true });
  }

  function initStudentNav(root) {
    if (root.getAttribute('data-mode') !== 'student') return;
    root.addEventListener('click', function (event) {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      var nav = event.target.closest && event.target.closest('a[data-course-nav]');
      if (!nav || !root.contains(nav)) return;
      var href = nav.getAttribute('href');
      if (!isCourseNavHref(href)) return;
      event.preventDefault();
      navigateExplorer(href, { push: true });
    });
  }

  function init(root) {
    if (root.getAttribute('data-course-ready') === '1') return;
    root.setAttribute('data-course-ready', '1');
    initTreeDetails(root);
    initTree(root);
    initViewToggle(root);
    initModals(root);
    initUpload(root);
    initStudentNav(root);
  }

  document.addEventListener('click', function (event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    var preview = event.target.closest && event.target.closest('[data-course-preview]');
    if (!preview) return;
    var href = preview.getAttribute('data-course-preview') || preview.getAttribute('href');
    if (!href || preview.hasAttribute('download')) return;
    event.preventDefault();
    openPdfPreview(href, previewTitleOf(preview, href), preview);
  });

  document.addEventListener('keydown', function (event) {
    if (pdfLayer && !pdfLayer.hasAttribute('hidden')) {
      if (event.key === 'Escape') {
        event.preventDefault();
        closePdfPreview();
        return;
      }
      if (event.key === 'Tab') {
        var focusables = pdfLayer.querySelectorAll('button, a[href]');
        if (!focusables.length) return;
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
        return;
      }
    }
    if (event.key === 'Escape' && fallbackOpen) closeFallback();
  });

  window.addEventListener('popstate', function () {
    var params = new URLSearchParams(window.location.search);
    var panel = params.get('panel') || 'profil';
    if (window.studentSpace && typeof window.studentSpace.openPanel === 'function') {
      window.studentSpace.openPanel(panel, { silent: true });
    }
    if (panel === 'cours' && currentStudentExplorer()) {
      navigateExplorer(window.location.pathname + window.location.search, { push: false, fallback: false });
    }
  });

  window.addEventListener('pageshow', function (event) {
    if (event.persisted) resetBusy(document);
  });

  window.courseExplorer = {
    navigate: navigateExplorer,
    reload: reloadExplorer,
    preview: openPdfPreview,
    closePreview: closePdfPreview
  };

  ready(function () {
    each(document.querySelectorAll('[data-course-explorer]'), init);
  });
})();
