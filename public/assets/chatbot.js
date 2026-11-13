(function () {
  'use strict';

  var cfg = window.UPSIS_CHATBOT || null;
  if (!cfg || !cfg.endpoint) return;

  var state = {
    open: false,
    view: 'chat',
    conversationId: 0,
    busy: false,
    conversations: [],
    messages: [],
    suggestions: cfg.suggestions || [],
    lastUserMessage: ''
  };

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (key) {
      if (key === 'className') node.className = attrs[key];
      else if (key === 'text') node.textContent = attrs[key];
      else if (key === 'html') node.innerHTML = attrs[key];
      else if (key.indexOf('on') === 0) node.addEventListener(key.slice(2).toLowerCase(), attrs[key]);
      else if (attrs[key] !== null && attrs[key] !== undefined) node.setAttribute(key, attrs[key]);
    });
    (children || []).forEach(function (child) {
      if (child == null) return;
      node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    });
    return node;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderMarkdown(text) {
    var safe = escapeHtml(text);
    safe = safe.replace(/```([\s\S]*?)```/g, function (_, code) {
      return '<pre><code>' + code + '</code></pre>';
    });
    safe = safe.replace(/^### (.+)$/gm, '<strong>$1</strong>');
    safe = safe.replace(/\[([^\]]+)\]\((\/[^)\s]+)\)/g, '<a href="$2">$1<\/a>');
    safe = safe.replace(/^\- (.+)$/gm, '• $1');
    safe = safe.replace(/\n/g, '<br>');
    return safe;
  }

  function api(action, payload, method) {
    method = method || (payload ? 'POST' : 'GET');
    var url = cfg.endpoint + '?action=' + encodeURIComponent(action);
    if (method === 'GET' && payload) {
      Object.keys(payload).forEach(function (key) {
        url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(payload[key]);
      });
    }
    var options = {
      method: method,
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-CSRF-Token': cfg.csrf_token || ''
      }
    };
    if (method !== 'GET') {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(Object.assign({
        csrf_token: cfg.csrf_token,
        audience: cfg.audience
      }, payload || {}));
    }
    return fetch(url, options).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || (data && data.ok === false && !data.message)) {
          throw new Error((data && data.error) || 'request_failed');
        }
        return data;
      });
    });
  }

  var root = document.getElementById('upsisChatbotRoot');
  if (!root) {
    root = el('div', { id: 'upsisChatbotRoot' });
    document.body.appendChild(root);
  }
  root.hidden = false;

  var launcher = el('button', {
    className: 'chatbot-launcher',
    type: 'button',
    'aria-label': 'Ouvrir University AI Assistant',
    'aria-expanded': 'false'
  });
  launcher.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 12a8 8 0 0114.5-4.7L20 6v4h-4l1.3-1.3A6.5 6.5 0 105.5 14.2L4 16v-4z"/><circle cx="9" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="15" cy="12" r="1"/></svg>';
  if (!cfg.provider_ready) {
    launcher.appendChild(el('span', { className: 'chatbot-launcher-dot', 'aria-hidden': 'true' }));
  }

  var panel = el('section', {
    className: 'chatbot-panel group-roster-panel',
    role: 'dialog',
    'aria-modal': 'false',
    'aria-label': cfg.assistant_name || 'University AI Assistant',
    hidden: 'hidden'
  });

  var bodyEl = el('div', { className: 'chatbot-body', 'data-chatbot-body': '1' });
  var inputEl = el('textarea', {
    rows: '1',
    placeholder: 'Posez votre question…',
    'aria-label': 'Message à l’assistant'
  });
  var sendBtn = el('button', {
    className: 'chatbot-send',
    type: 'button',
    title: 'Envoyer',
    'aria-label': 'Envoyer',
    html: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h12"/><path d="M13 6l6 6-6 6"/></svg>'
  });

  function setOpen(open) {
    state.open = open;
    panel.hidden = !open;
    launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      inputEl.focus();
      refreshBootstrap();
    }
  }

  function renderTitlebar() {
    return el('header', {
      className: 'chatbot-titlebar group-roster-titlebar',
      'aria-hidden': 'true'
    }, [
      el('div', { className: 'chatbot-traffic group-roster-traffic' }, [
        el('button', {
          type: 'button',
          className: 'chatbot-dot chatbot-dot-close group-roster-dot group-roster-dot-close',
          'data-chatbot-close': '1',
          tabindex: '-1',
          'aria-hidden': 'true',
          onClick: function () { setOpen(false); }
        }),
        el('span', {
          className: 'chatbot-dot chatbot-dot-min group-roster-dot group-roster-dot-min',
          'aria-hidden': 'true'
        }),
        el('span', {
          className: 'chatbot-dot chatbot-dot-max group-roster-dot group-roster-dot-max',
          'aria-hidden': 'true'
        })
      ]),
      el('p', {
        className: 'chatbot-titlebar-label group-roster-titlebar-label',
        text: cfg.assistant_name || 'University AI Assistant'
      })
    ]);
  }

  function renderHead() {
    var iconNew = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
    var iconHist = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg>';
    var iconClose = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
    var head = el('header', { className: 'chatbot-head' }, [
      el('div', { className: 'chatbot-avatar', 'aria-hidden': 'true', html: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="7"/><path d="M9.5 12h5M12 9.5v5"/></svg>' }),
      el('div', { className: 'chatbot-head-copy' }, [
        el('strong', { text: cfg.assistant_name || 'University AI Assistant' }),
        el('span', { text: cfg.provider_ready ? ('En ligne · ' + (cfg.role || 'guest')) : 'Mode documentation locale' })
      ]),
      el('div', { className: 'chatbot-head-actions' }, [
        el('button', { className: 'chatbot-icon-btn', type: 'button', title: 'Nouvelle conversation', 'aria-label': 'Nouvelle conversation', html: iconNew, onClick: function () { newConversation(); } }),
        el('button', { className: 'chatbot-icon-btn', type: 'button', title: 'Historique', 'aria-label': 'Historique', html: iconHist, onClick: function () { state.view = state.view === 'history' ? 'chat' : 'history'; renderBody(); } }),
        el('button', { className: 'chatbot-icon-btn', type: 'button', title: 'Fermer', 'aria-label': 'Fermer', 'data-chatbot-close': '1', html: iconClose, onClick: function () { setOpen(false); } })
      ])
    ]);
    return head;
  }

  function appendMessage(msg, isUser) {
    var wrap = el('article', { className: 'chatbot-msg ' + (isUser ? 'is-user' : 'is-assistant') });
    var bubble = el('div', { className: 'chatbot-msg-bubble' });
    if (isUser) bubble.textContent = msg.content || '';
    else bubble.innerHTML = renderMarkdown(msg.content || '');
    wrap.appendChild(bubble);
    if (!isUser) {
      var actions = el('div', { className: 'chatbot-msg-actions' });
      actions.appendChild(el('button', { type: 'button', text: 'Copier', onClick: function () {
        if (navigator.clipboard) navigator.clipboard.writeText(msg.content || '');
      }}));
      if (msg.id_message) {
        actions.appendChild(el('button', { type: 'button', text: 'Utile', onClick: function () {
          api('feedback', { message_id: msg.id_message, rating: 'up' }).catch(function () {});
        }}));
        actions.appendChild(el('button', { type: 'button', text: 'Pas utile', onClick: function () {
          api('feedback', { message_id: msg.id_message, rating: 'down' }).catch(function () {});
        }}));
      }
      if (state.lastUserMessage) {
        actions.appendChild(el('button', { type: 'button', text: 'Régénérer', onClick: function () {
          sendMessage(state.lastUserMessage, true);
        }}));
      }
      wrap.appendChild(actions);
    }
    bodyEl.appendChild(wrap);
    bodyEl.scrollTop = bodyEl.scrollHeight;
  }

  function renderBody() {
    bodyEl.innerHTML = '';
    if (state.view === 'history') {
      var list = el('div', { className: 'chatbot-history' });
      if (!state.conversations.length) {
        list.appendChild(el('p', { text: 'Aucune conversation enregistrée.' }));
      }
      state.conversations.forEach(function (item) {
        list.appendChild(el('button', {
          type: 'button',
          text: item.title || ('Conversation #' + item.id_conversation),
          onClick: function () {
            loadConversation(item.id_conversation);
          }
        }));
      });
      list.appendChild(el('button', {
        type: 'button',
        text: 'Effacer tout l’historique',
        onClick: function () {
          if (!confirm('Effacer toutes vos conversations ?')) return;
          api('clear', {}).then(function () {
            state.conversations = [];
            state.conversationId = 0;
            state.messages = [];
            state.view = 'chat';
            renderBody();
          });
        }
      }));
      bodyEl.appendChild(list);
      return;
    }

    if (!state.messages.length) {
      var welcome = el('div', { className: 'chatbot-welcome' }, [
        el('h3', { text: 'Bonjour — comment puis-je vous aider ?' }),
        el('p', { text: cfg.audience === 'staff'
          ? 'Je connais les modules UPSIS autorisés pour votre rôle et la page en cours.'
          : 'Je réponds aux questions générales sur la plateforme (sans données privées).' })
      ]);
      var chips = el('div', { className: 'chatbot-suggestions' });
      (state.suggestions || []).forEach(function (q) {
        chips.appendChild(el('button', {
          className: 'chatbot-chip',
          type: 'button',
          text: q,
          onClick: function () { sendMessage(q); }
        }));
      });
      welcome.appendChild(chips);
      bodyEl.appendChild(welcome);
      return;
    }

    state.messages.forEach(function (msg) {
      appendMessage(msg, msg.role_name === 'user');
    });
  }

  function render() {
    panel.innerHTML = '';
    panel.appendChild(renderTitlebar());
    panel.appendChild(renderHead());
    panel.appendChild(bodyEl);
    var foot = el('footer', { className: 'chatbot-foot' }, [
      el('div', { className: 'chatbot-compose' }, [inputEl, sendBtn]),
      el('p', { className: 'chatbot-footnote', text: 'Assistant informatif — aucune modification automatique des dossiers.' })
    ]);
    panel.appendChild(foot);
    renderBody();
  }

  function refreshBootstrap() {
    api('bootstrap', {
      audience: cfg.audience,
      page_path: location.pathname + location.search,
      page_title: document.title
    }, 'GET').then(function (data) {
      if (data.csrf_token) cfg.csrf_token = data.csrf_token;
      if (data.suggestions) state.suggestions = data.suggestions;
      if (data.conversations) state.conversations = data.conversations;
      if (!state.messages.length) renderBody();
    }).catch(function () {});
  }

  function loadConversation(id) {
    api('history', { conversation_id: id }, 'GET').then(function (data) {
      state.conversationId = id;
      state.messages = data.messages || [];
      state.view = 'chat';
      renderBody();
    }).catch(function () {});
  }

  function newConversation() {
    state.conversationId = 0;
    state.messages = [];
    state.view = 'chat';
    renderBody();
    inputEl.focus();
  }

  function sendMessage(text, regenerate) {
    text = String(text || '').trim();
    if (!text || state.busy) return;
    state.busy = true;
    state.lastUserMessage = text;
    sendBtn.disabled = true;
    if (!regenerate) {
      state.messages.push({ role_name: 'user', content: text });
    }
    state.view = 'chat';
    renderBody();
    var typing = el('div', { className: 'chatbot-typing', 'aria-label': 'Réponse en cours' }, [
      el('i'), el('i'), el('i')
    ]);
    bodyEl.appendChild(typing);
    bodyEl.scrollTop = bodyEl.scrollHeight;

    api('chat', {
      message: text,
      conversation_id: state.conversationId || 0,
      page_path: location.pathname + location.search,
      page_title: document.title,
      audience: cfg.audience
    }).then(function (data) {
      if (data.conversation_id) state.conversationId = data.conversation_id;
      if (data.message) state.messages.push(data.message);
      renderBody();
    }).catch(function () {
      state.messages.push({
        role_name: 'assistant',
        content: 'Impossible de joindre l’assistant pour le moment. Réessayez dans un instant.'
      });
      renderBody();
    }).finally(function () {
      state.busy = false;
      sendBtn.disabled = false;
      inputEl.value = '';
      inputEl.focus();
    });
  }

  launcher.addEventListener('click', function () { setOpen(!state.open); });
  sendBtn.addEventListener('click', function () { sendMessage(inputEl.value); });
  inputEl.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      sendMessage(inputEl.value);
    }
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && state.open) setOpen(false);
  });

  root.appendChild(launcher);
  root.appendChild(panel);
  render();
})();
