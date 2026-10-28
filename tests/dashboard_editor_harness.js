const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const editorSource = fs.readFileSync(
  path.join(__dirname, '..', 'public', 'assets', 'dashboard-editor.js'),
  'utf8'
);

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

class Element {
  constructor(tagName, document) {
    this.tagName = tagName;
    this.document = document;
    this.childNodes = [];
    this.parentElement = null;
    this.dataset = {};
    this.attributes = {};
    this.listeners = {};
    this.className = '';
    this.textContent = '';
    this.hidden = false;
    this.disabled = false;
    this.classList = {
      add: (name) => {
        this.className = (this.className + ' ' + name).trim();
      },
      contains: (name) => this.className.split(/\s+/).includes(name),
      toggle: (name, force) => {
        const shouldAdd = force === undefined ? !this.classList.contains(name) : force;
        if (shouldAdd && !this.classList.contains(name)) this.classList.add(name);
        if (!shouldAdd) {
          this.className = this.className.split(/\s+/).filter((entry) => entry !== name).join(' ');
        }
      }
    };
  }

  get children() {
    return this.childNodes.filter((child) => child.tagName !== '#comment');
  }

  appendChild(node) {
    this.detach(node);
    this.childNodes.push(node);
    node.parentElement = this;
    return node;
  }

  replaceChild(node, previous) {
    this.detach(node);
    const index = this.childNodes.indexOf(previous);
    if (index < 0) throw new Error('replaceChild target not found');
    this.childNodes[index] = node;
    node.parentElement = this;
    previous.parentElement = null;
    return previous;
  }

  detach(node) {
    if (!node.parentElement) return;
    const siblings = node.parentElement.childNodes;
    siblings.splice(siblings.indexOf(node), 1);
    node.parentElement = null;
  }

  hasAttribute(name) {
    return name === 'data-dashboard-widget'
      ? Boolean(this.dataset.dashboardWidget)
      : Object.prototype.hasOwnProperty.call(this.attributes, name);
  }

  getAttribute(name) {
    return this.attributes[name] || null;
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
  }

  matches() {
    return false;
  }

  addEventListener(name, callback) {
    (this.listeners[name] ||= []).push(callback);
  }

  click() {
    this.document.activeElement = this;
    (this.listeners.click || []).forEach((callback) => callback({ target: this }));
  }

  focus() {
    this.document.activeElement = this;
  }

  contains(node) {
    return node === this || this.childNodes.some((child) => child.contains(node));
  }

  querySelector(selector) {
    if (selector === ':scope > .card') return null;
    const matches = (node) => {
      if (selector === '.dashboard-widget-controls') {
        return node.classList.contains('dashboard-widget-controls');
      }
      return ['up', 'down', 'left', 'right'].some((direction) => (
        selector === '[data-dashboard-move-' + direction + ']'
        && node.hasAttribute('data-dashboard-move-' + direction)
      ));
    };
    const visit = (node) => {
      for (const child of node.childNodes) {
        if (matches(child)) return child;
        const found = visit(child);
        if (found) return found;
      }
      return null;
    };
    return visit(this);
  }

  querySelectorAll(selector) {
    if (selector === ':scope > [data-dashboard-widget]') {
      return this.children.filter((child) => child.hasAttribute('data-dashboard-widget'));
    }
    const found = [];
    const visit = (node) => node.childNodes.forEach((child) => {
      if (selector === '[data-dashboard-layout]' && child.dataset.dashboardLayout) found.push(child);
      visit(child);
    });
    visit(this);
    return found;
  }

  getBoundingClientRect() {
    const index = this.parentElement.children.indexOf(this);
    const columns = this.parentElement.columns || 1;
    const left = (index % columns) * 100;
    const top = Math.floor(index / columns) * 100;
    return { left, top, width: 90, height: 90, right: left + 90, bottom: top + 90 };
  }
}

function createHarness(layoutDefinitions) {
  const document = { activeElement: null };
  document.createElement = (tagName) => new Element(tagName, document);
  document.createComment = () => new Element('#comment', document);
  document.body = new Element('body', document);

  const panel = new Element('section', document);
  panel.dataset.dashboardUser = 'test-user';
  panel.dataset.dashboardRole = 'scolarite';
  const layouts = layoutDefinitions.map((definition) => {
    const root = new Element('div', document);
    root.dataset.dashboardLayout = definition.group;
    root.columns = definition.columns || 2;
    definition.widgets.forEach((id) => {
      const widget = new Element('article', document);
      widget.dataset.dashboardWidget = id;
      widget.setAttribute('aria-label', id);
      root.appendChild(widget);
    });
    panel.appendChild(root);
    return root;
  });

  const toolbar = new Element('div', document);
  const actions = {};
  ['edit', 'save', 'cancel', 'reset'].forEach((action) => {
    actions[action] = new Element('button', document);
  });
  const status = new Element('span', document);
  toolbar.querySelector = (selector) => {
    if (selector.includes('data-dashboard-layout-status')) return status;
    const action = selector.match(/edit|save|cancel|reset/)[0];
    return actions[action];
  };
  document.querySelectorAll = () => [];
  document.querySelector = (selector) => (
    selector.includes('data-dashboard-user') ? panel : toolbar
  );

  const storedValues = new Map();
  let failingKey = null;
  const localStorage = {
    getItem: (key) => storedValues.has(key) ? storedValues.get(key) : null,
    setItem: (key, value) => {
      if (key === failingKey) {
        failingKey = null;
        storedValues.set(key, String(value));
        throw new Error('simulated quota failure');
      }
      storedValues.set(key, String(value));
    },
    removeItem: (key) => storedValues.delete(key)
  };
  const window = {
    matchMedia: () => ({ matches: false }),
    getComputedStyle: () => ({ display: 'block' }),
    addEventListener() {},
    requestAnimationFrame: (callback) => {
      callback(0);
      return 1;
    },
    ResizeObserver: class {
      observe() {}
    }
  };
  class MutationObserver {
    observe() {}
  }

  vm.runInNewContext(editorSource, {
    document,
    window,
    localStorage,
    MutationObserver,
    console,
    Math,
    JSON,
    Map,
    Set,
    Array,
    Intl
  });

  function keyFor(group) {
    return [
      'upsis-dashboard-layout-v1',
      panel.dataset.dashboardUser,
      panel.dataset.dashboardRole,
      group,
      'original',
      'dark'
    ].map(encodeURIComponent).join(':');
  }

  return { actions, document, keyFor, layouts, localStorage, panel, status, storedValues, failNextWrite: (key) => { failingKey = key; } };
}

function widgetOrder(root) {
  return root.children.map((widget) => widget.dataset.dashboardWidget).join(',');
}

const focusHarness = createHarness([
  { group: 'office-summary', widgets: ['students', 'requests'] }
]);
focusHarness.actions.edit.click();
const movedWidget = focusHarness.layouts[0].children[0];
const movedRight = movedWidget.querySelector('[data-dashboard-move-right]');
movedRight.click();
assert(movedRight.disabled, 'the right arrow should become disabled after moving the widget to the right edge');
const enabledFallback = movedWidget.querySelector('[data-dashboard-move-left]');
assert(!enabledFallback.disabled, 'the same widget should retain another enabled direction');
assert(
  focusHarness.document.activeElement === enabledFallback,
  'focus should move off a newly disabled arrow to an enabled control on the same widget'
);

const toolbarFocusHarness = createHarness([
  { group: 'office-summary', widgets: ['students', 'requests'] }
]);
toolbarFocusHarness.actions.edit.click();
const toolbarFallbackWidget = toolbarFocusHarness.layouts[0].children[0];
toolbarFallbackWidget.getBoundingClientRect = () => (
  toolbarFocusHarness.layouts[0].children[0] === toolbarFallbackWidget
    ? { left: 0, top: 0, width: 90, height: 90, right: 90, bottom: 90 }
    : { left: 0, top: 0, width: 90, height: 90, right: 90, bottom: 90 }
);
const otherWidget = toolbarFocusHarness.layouts[0].children[1];
otherWidget.getBoundingClientRect = () => (
  toolbarFocusHarness.layouts[0].children[0] === toolbarFallbackWidget
    ? { left: 100, top: 0, width: 90, height: 90, right: 190, bottom: 90 }
    : { left: 0, top: 0, width: 90, height: 90, right: 90, bottom: 90 }
);
toolbarFallbackWidget.querySelector('[data-dashboard-move-right]').click();
assert(
  toolbarFocusHarness.document.activeElement === toolbarFocusHarness.actions.save,
  'if no move arrow remains enabled, focus should move to a stable visible toolbar action'
);

const saveHarness = createHarness([
  { group: 'office-summary', widgets: ['students', 'requests'] },
  { group: 'admin-home', widgets: ['hero', 'activity'] }
]);
const officeKey = saveHarness.keyFor('office-summary');
const adminKey = saveHarness.keyFor('admin-home');
saveHarness.storedValues.set(officeKey, 'previous-office-layout');
saveHarness.failNextWrite(adminKey);
saveHarness.actions.edit.click();
const officeOrderBeforeSave = widgetOrder(saveHarness.layouts[0]);
const adminOrderBeforeSave = widgetOrder(saveHarness.layouts[1]);
saveHarness.actions.save.click();
assert(
  saveHarness.storedValues.get(officeKey) === 'previous-office-layout',
  'a successful earlier write should be restored to its previous value after a later write fails'
);
assert(!saveHarness.storedValues.has(adminKey), 'a partially written new key should be removed during rollback');
assert(!saveHarness.actions.save.hidden && saveHarness.actions.edit.hidden, 'failed save should remain in edit mode');
assert(
  widgetOrder(saveHarness.layouts[0]) === officeOrderBeforeSave
    && widgetOrder(saveHarness.layouts[1]) === adminOrderBeforeSave,
  'failed save should leave the current widget layout unchanged'
);
assert(
  saveHarness.status.textContent.includes('restent en attente'),
  'failed save should report that edits remain pending'
);

console.log('PASS: widget/toolbar focus recovery and multi-root storage rollback');
