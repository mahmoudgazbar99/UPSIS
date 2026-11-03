(function () {
  'use strict';

  function initializeDistributionCharts() {
    const reducedMotion = typeof window.matchMedia === 'function'
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    document.querySelectorAll('[data-dashboard-distribution]').forEach(function (chart) {
      const categories = Array.from(chart.querySelectorAll('[data-chart-category]'));
      const segments = Array.from(chart.querySelectorAll('[data-chart-segment]'));
      let hoveredCategory = null;
      let focusedCategory = null;

      function updateHighlight() {
        const activeCategory = hoveredCategory !== null ? hoveredCategory : focusedCategory;

        categories.forEach(function (category) {
          const active = category.dataset.chartCategory === activeCategory;
          category.classList.toggle('is-active', active);
        });
        segments.forEach(function (segment) {
          segment.classList.toggle('is-active', segment.dataset.chartSegment === activeCategory);
        });
      }

      categories.forEach(function (category) {
        const key = category.dataset.chartCategory;
        category.addEventListener('pointerenter', function () {
          hoveredCategory = key;
          updateHighlight();
        });
        category.addEventListener('pointerleave', function () {
          if (hoveredCategory === key) hoveredCategory = null;
          updateHighlight();
        });
        category.addEventListener('focus', function () {
          focusedCategory = key;
          updateHighlight();
        });
        category.addEventListener('blur', function () {
          if (focusedCategory === key) focusedCategory = null;
          updateHighlight();
        });
      });

      segments.forEach(function (segment) {
        const key = segment.dataset.chartSegment;
        segment.addEventListener('pointerenter', function () {
          hoveredCategory = key;
          updateHighlight();
        });
        segment.addEventListener('pointerleave', function () {
          if (hoveredCategory === key) hoveredCategory = null;
          updateHighlight();
        });
      });

      const counter = chart.querySelector('[data-dashboard-count]');
      const target = Number(chart.dataset.countTarget);
      if (counter && target > 0 && !reducedMotion) {
        const finalValue = counter.textContent;
        const startTime = window.performance.now();
        const duration = 650;
        counter.textContent = '0';

        function formatCount(value) {
          try {
            return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(value);
          } catch (error) {
            return String(value);
          }
        }

        function countFrame(now) {
          const progress = Math.min(1, (now - startTime) / duration);
          const eased = 1 - Math.pow(1 - progress, 3);
          counter.textContent = formatCount(Math.round(target * eased));
          if (progress < 1) window.requestAnimationFrame(countFrame);
          else counter.textContent = finalValue;
        }

        window.requestAnimationFrame(countFrame);
      }
    });
  }

  initializeDistributionCharts();

  const panel = document.querySelector('[data-dashboard-user][data-dashboard-role]');
  const toolbar = document.querySelector('[data-dashboard-layout-toolbar]');
  if (!panel || !toolbar) return;

  function isWidgetVisible(item) {
    if (item.hidden) return false;
    return typeof window.getComputedStyle !== 'function' || window.getComputedStyle(item).display !== 'none';
  }

  function visibleItems(root) {
    return Array.from(root.element.children).filter(function (item) {
      return root.allItems.indexOf(item) !== -1 && isWidgetVisible(item);
    });
  }

  function defaultVisibleOrder(root) {
    const visibleIds = new Set(root.allItems.filter(isWidgetVisible).map(function (item) {
      return item.dataset.dashboardWidget;
    }));
    return root.defaults.filter(function (id) { return visibleIds.has(id); });
  }

  const roots = Array.from(panel.querySelectorAll('[data-dashboard-layout]')).map(function (element) {
    const allItems = Array.from(element.children).filter(function (child) {
      return child.hasAttribute('data-dashboard-widget');
    });
    return {
      element: element,
      group: element.dataset.dashboardLayout,
      allItems: allItems,
      items: [],
      defaults: allItems.map(function (item) { return item.dataset.dashboardWidget; })
    };
  }).filter(function (root) { return visibleItems(root).length > 1; });

  if (!roots.length) return;

  const editButton = toolbar.querySelector('[data-dashboard-layout-edit]');
  const saveButton = toolbar.querySelector('[data-dashboard-layout-save]');
  const cancelButton = toolbar.querySelector('[data-dashboard-layout-cancel]');
  const resetButton = toolbar.querySelector('[data-dashboard-layout-reset]');
  const status = toolbar.querySelector('[data-dashboard-layout-status]');
  let editing = false;
  let dragItem = null;
  let activeContext = '';

  function themeStyle() {
    const body = document.body;
    if (body.classList.contains('theme-glass')) return 'glass';
    if (body.classList.contains('theme-macos')) return 'macos';
    if (body.classList.contains('theme-clarity')) return 'clarity';
    if (body.classList.contains('theme-institut')) return 'institut';
    return 'original';
  }

  function themeMode() {
    return document.body.classList.contains('theme-light') ? 'light' : 'dark';
  }

  function contextId() {
    return [panel.dataset.dashboardUser, panel.dataset.dashboardRole, themeStyle(), themeMode()].join(':');
  }

  function storageKey(root) {
    return [
      'upsis-dashboard-layout-v1',
      panel.dataset.dashboardUser,
      panel.dataset.dashboardRole,
      root.group,
      themeStyle(),
      themeMode()
    ].map(encodeURIComponent).join(':');
  }

  function validOrder(root, order) {
    const defaults = defaultVisibleOrder(root);
    if (!Array.isArray(order)) return defaults;
    const expandedOrder = root.group === 'admin-home' ? order.reduce(function (expanded, id) {
      if (id === 'overview') {
        return expanded.concat([
          'hero',
          'stat-students',
          'stat-teachers',
          'stat-groups',
          'stat-requests',
          'requests',
          'distribution',
          'activity'
        ]);
      }
      if (id === 'student-management') return expanded.concat(['student-search', 'student-list']);
      expanded.push(id);
      return expanded;
    }, []) : order;
    const valid = expandedOrder.filter(function (id, index) {
      return defaults.indexOf(id) !== -1 && expandedOrder.indexOf(id) === index;
    });
    return valid.concat(defaults.filter(function (id) { return valid.indexOf(id) === -1; }));
  }

  function currentOrder(root) {
    return root.items.map(function (item) { return item.dataset.dashboardWidget; });
  }

  function applyOrder(root, order) {
    const itemsById = new Map(root.allItems.map(function (item) {
      return [item.dataset.dashboardWidget, item];
    }));
    validOrder(root, order).forEach(function (id) {
      const item = itemsById.get(id);
      if (item) root.element.appendChild(item);
    });
    root.items = visibleItems(root);
    updateItemControls(root);
  }

  function readOrder(root) {
    try {
      const saved = localStorage.getItem(storageKey(root));
      return saved ? validOrder(root, JSON.parse(saved)) : root.defaults.slice();
    } catch (error) {
      return root.defaults.slice();
    }
  }

  function restoreSavedOrders() {
    roots.forEach(function (root) {
      root.items = visibleItems(root);
      applyOrder(root, readOrder(root));
    });
  }

  function setStatus(message) {
    if (status) status.textContent = message;
  }

  function accessibleTitle(item) {
    const ariaLabel = item.getAttribute('aria-label');
    if (ariaLabel) return ariaLabel.trim();
    const heading = item.querySelector('h2, h3, h4')
      || item.querySelector('.text-secondary, small, label, .eyebrow, p, strong');
    return (heading ? heading.textContent : '').trim() || 'bloc du tableau de bord';
  }

  function announcePosition(item, root) {
    const position = root.items.indexOf(item) + 1;
    setStatus(accessibleTitle(item) + ' déplacé en position ' + position + ' sur ' + root.items.length + '.');
  }

  const directions = {
    left: { axis: 'x', sign: -1 },
    right: { axis: 'x', sign: 1 },
    up: { axis: 'y', sign: -1 },
    down: { axis: 'y', sign: 1 }
  };

  function boundsCenter(bounds) {
    return {
      x: bounds.left + bounds.width / 2,
      y: bounds.top + bounds.height / 2
    };
  }

  function getSpatialNeighbor(item, root, directionName) {
    const direction = directions[directionName];
    if (!direction) return null;
    const sourceBounds = item.getBoundingClientRect();
    const sourceCenter = boundsCenter(sourceBounds);
    const horizontal = direction.axis === 'x';
    const sourceCoordinate = horizontal ? sourceCenter.x : sourceCenter.y;
    const candidates = visibleItems(root).reduce(function (results, candidate) {
      if (candidate === item) return results;
      const bounds = candidate.getBoundingClientRect();
      const center = boundsCenter(bounds);
      const coordinate = horizontal ? center.x : center.y;
      if ((coordinate - sourceCoordinate) * direction.sign <= 1) return results;
      const overlap = horizontal
        ? Math.min(sourceBounds.bottom, bounds.bottom) - Math.max(sourceBounds.top, bounds.top)
        : Math.min(sourceBounds.right, bounds.right) - Math.max(sourceBounds.left, bounds.left);
      const primaryDistance = Math.abs(coordinate - sourceCoordinate);
      const perpendicularDistance = horizontal
        ? Math.abs(center.y - sourceCenter.y)
        : Math.abs(center.x - sourceCenter.x);
      results.push({
        item: candidate,
        overlaps: overlap > 0,
        angle: perpendicularDistance / Math.max(primaryDistance, 1),
        distance: Math.hypot(center.x - sourceCenter.x, center.y - sourceCenter.y)
      });
      return results;
    }, []);
    candidates.sort(function (a, b) {
      if (a.overlaps !== b.overlaps) return a.overlaps ? -1 : 1;
      if (a.overlaps) return a.distance - b.distance;
      return a.angle - b.angle || a.distance - b.distance;
    });
    return candidates.length ? candidates[0].item : null;
  }

  function swapItems(first, second) {
    if (!first || !second || first === second || first.parentElement !== second.parentElement) return false;
    const parent = first.parentElement;
    const placeholder = document.createComment('');
    parent.replaceChild(placeholder, first);
    parent.replaceChild(first, second);
    parent.replaceChild(second, placeholder);
    return true;
  }

  function restoreMoveFocus(item, focusedControl) {
    if (!focusedControl || typeof focusedControl.focus !== 'function') return;
    if (!focusedControl.disabled) {
      focusedControl.focus();
      return;
    }

    const enabledControl = Object.keys(directions).map(function (direction) {
      return item.querySelector('[data-dashboard-move-' + direction + ']');
    }).find(function (control) {
      return control && !control.disabled;
    });
    if (enabledControl) {
      enabledControl.focus();
      return;
    }

    [saveButton, cancelButton, resetButton, editButton].some(function (control) {
      if (!control || control.hidden || control.disabled || typeof control.focus !== 'function') return false;
      control.focus();
      return true;
    });
  }

  function moveItem(item, directionName) {
    const root = roots.find(function (candidate) { return candidate.element.contains(item); });
    if (!root) return;
    const target = getSpatialNeighbor(item, root, directionName);
    const focusedControl = item.contains(document.activeElement) ? document.activeElement : null;
    if (!target || !swapItems(item, target)) return;
    root.items = visibleItems(root);
    updateItemControls(root);
    restoreMoveFocus(item, focusedControl);
    announcePosition(item, root);
  }

  function updateItemControls(root) {
    root.items.forEach(function (item) {
      Object.keys(directions).forEach(function (direction) {
        const button = item.querySelector('[data-dashboard-move-' + direction + ']');
        if (button) button.disabled = !getSpatialNeighbor(item, root, direction);
      });
    });
  }

  function addItemControls(root) {
    root.items.forEach(function (item) {
      if (item.querySelector('.dashboard-widget-controls')) return;
      const label = accessibleTitle(item);
      const surface = item.matches('.dashboard-widget-group')
        ? item
        : item.matches('.card') ? item : item.querySelector(':scope > .card') || item;
      surface.classList.add('dashboard-widget-surface');
      const controls = document.createElement('div');
      controls.className = 'dashboard-widget-controls';

      const handle = document.createElement('button');
      handle.type = 'button';
      handle.className = 'dashboard-widget-handle';
      handle.textContent = '⠿';
      handle.draggable = true;
      handle.setAttribute('aria-label', 'Faire glisser ' + label);
      handle.setAttribute('title', 'Faire glisser pour déplacer');
      controls.appendChild(handle);

      [
        ['up', '↑', 'le haut'],
        ['down', '↓', 'le bas'],
        ['left', '←', 'la gauche'],
        ['right', '→', 'la droite']
      ].forEach(function (control) {
        const direction = control[0];
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'dashboard-widget-move';
        button.textContent = control[1];
        button.setAttribute('aria-label', 'Déplacer ' + label + ' vers ' + control[2]);
        button.setAttribute('title', 'Déplacer vers ' + control[2]);
        button.setAttribute('data-dashboard-move-' + direction, '');
        button.addEventListener('click', function () { moveItem(item, direction); });
        controls.appendChild(button);
      });
      surface.appendChild(controls);
    });
    updateItemControls(root);
  }

  function setEditing(next, returnFocus) {
    editing = next;
    document.body.classList.toggle('dashboard-layout-editing', editing);
    editButton.hidden = editing;
    saveButton.hidden = !editing;
    cancelButton.hidden = !editing;
    resetButton.hidden = !editing;
    if (editing) roots.forEach(addItemControls);
    setStatus(editing
      ? 'Déplacez les blocs avec la poignée ou les boutons. Enregistrez pour conserver vos changements.'
      : 'Vos blocs sont enregistrés pour ce thème et ce mode.');
    if (!editing && returnFocus && typeof editButton.focus === 'function') editButton.focus();
  }

  function saveOrders() {
    const updates = [];
    let attemptedWrites = 0;
    try {
      roots.forEach(function (root) {
        const key = storageKey(root);
        updates.push({
          key: key,
          previousValue: localStorage.getItem(key),
          nextValue: JSON.stringify(currentOrder(root))
        });
      });
      updates.forEach(function (update) {
        attemptedWrites += 1;
        localStorage.setItem(update.key, update.nextValue);
      });
      setEditing(false, true);
      setStatus('Votre disposition a été enregistrée.');
    } catch (error) {
      let rollbackFailed = false;
      for (let index = attemptedWrites - 1; index >= 0; index -= 1) {
        const update = updates[index];
        try {
          if (update.previousValue === null) localStorage.removeItem(update.key);
          else localStorage.setItem(update.key, update.previousValue);
        } catch (rollbackError) {
          rollbackFailed = true;
        }
      }
      setStatus(rollbackFailed
        ? 'Enregistrement impossible et restauration incomplète. Vos changements restent en attente.'
        : 'Enregistrement impossible dans ce navigateur. Vos changements restent en attente.');
    }
  }

  editButton.addEventListener('click', function () { setEditing(true); });
  saveButton.addEventListener('click', saveOrders);
  cancelButton.addEventListener('click', function () {
    restoreSavedOrders();
    setEditing(false, true);
    setStatus('Modifications annulées. La disposition enregistrée a été restaurée.');
  });
  resetButton.addEventListener('click', function () {
    roots.forEach(function (root) { applyOrder(root, root.defaults); });
    setStatus('Ordre par défaut restauré en attente d’enregistrement.');
  });

  panel.addEventListener('dragstart', function (event) {
    const handle = event.target.closest('.dashboard-widget-handle');
    if (!editing || !handle) return;
    dragItem = handle.closest('[data-dashboard-widget]');
    if (!dragItem) return;
    dragItem.classList.add('dashboard-widget-dragging');
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', dragItem.dataset.dashboardWidget);
    }
  });

  panel.addEventListener('dragover', function (event) {
    if (!editing || !dragItem) return;
    const target = event.target.closest('[data-dashboard-widget]');
    const rootElement = event.target.closest('[data-dashboard-layout]');
    if (!target || !rootElement || target === dragItem || dragItem.parentElement !== rootElement) return;
    event.preventDefault();
    const bounds = target.getBoundingClientRect();
    const sameRow = rootElement.querySelectorAll(':scope > [data-dashboard-widget]');
    const hasHorizontalPeer = Array.from(sameRow).some(function (sibling) {
      if (sibling === target || sibling === dragItem || !isWidgetVisible(sibling)) return false;
      const siblingBounds = sibling.getBoundingClientRect();
      const overlap = Math.min(bounds.bottom, siblingBounds.bottom) - Math.max(bounds.top, siblingBounds.top);
      return overlap > Math.min(bounds.height, siblingBounds.height) * 0.5;
    });
    const after = hasHorizontalPeer
      ? event.clientX > bounds.left + bounds.width / 2
      : event.clientY > bounds.top + bounds.height / 2;
    target.classList.toggle('dashboard-widget-drop-before', !after);
    target.classList.toggle('dashboard-widget-drop-after', after);
    rootElement.insertBefore(dragItem, after ? target.nextElementSibling : target);
  });

  panel.addEventListener('drop', function (event) {
    if (!editing || !dragItem) return;
    event.preventDefault();
    const root = roots.find(function (candidate) { return candidate.element.contains(dragItem); });
    if (root) {
      root.items = visibleItems(root);
      updateItemControls(root);
      announcePosition(dragItem, root);
    }
  });

  panel.addEventListener('dragend', function () {
    if (dragItem) dragItem.classList.remove('dashboard-widget-dragging');
    panel.querySelectorAll('.dashboard-widget-drop-before, .dashboard-widget-drop-after').forEach(function (item) {
      item.classList.remove('dashboard-widget-drop-before', 'dashboard-widget-drop-after');
    });
    dragItem = null;
  });

  roots.forEach(function (root) {
    root.items.forEach(function (item) {
      item.addEventListener('dragover', function (event) {
        if (editing && dragItem && dragItem.parentElement === root.element) event.preventDefault();
      });
    });
  });

  let controlsRefreshFrame = 0;
  function scheduleControlsRefresh() {
    if (controlsRefreshFrame) return;
    controlsRefreshFrame = window.requestAnimationFrame(function () {
      controlsRefreshFrame = 0;
      roots.forEach(updateItemControls);
    });
  }

  window.addEventListener('resize', scheduleControlsRefresh);
  let controlsResizeObserver = null;
  if (typeof window.ResizeObserver === 'function') {
    controlsResizeObserver = new window.ResizeObserver(scheduleControlsRefresh);
    roots.forEach(function (root) { controlsResizeObserver.observe(root.element); });
  }

  function syncContext() {
    const nextContext = contextId();
    if (nextContext === activeContext) return;
    if (editing) setEditing(false);
    activeContext = nextContext;
    restoreSavedOrders();
    setStatus('Disposition chargée pour le thème et le mode actuels.');
  }

  activeContext = contextId();
  restoreSavedOrders();
  toolbar.hidden = false;
  document.body.classList.add('dashboard-layout-page');

  new MutationObserver(syncContext).observe(document.body, {
    attributes: true,
    attributeFilter: ['class']
  });

  window.addEventListener('storage', function (event) {
    if (event.key && event.key.indexOf('upsis-dashboard-layout-v1:') === 0 && !editing) {
      restoreSavedOrders();
      setStatus('Disposition mise à jour dans un autre onglet.');
    }
  });
})();
