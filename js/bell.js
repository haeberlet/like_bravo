/* Like & Bravo: the bell of the feedback for owners. Shows the number of
   unread likes, bravos and comments on the member's photos and opens a list
   of the newest ones, loaded when the bell is opened. The bell goes to a
   known place of the theme, or to the top right corner of the window. */
var LikeBravoBell = (function() {
  'use strict';

  var BELL = '<svg class="lb-bell-icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">'
    + '<path d="M18 16v-5a6 6 0 0 0-5-5.9V4a1 1 0 0 0-2 0v1.1A6 6 0 0 0 6 11v5l-2 2v1h16v-1z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>'
    + '<path d="M10 20a2 2 0 0 0 4 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';

  var cfg, button, badge, panel, list, markAll, count = 0, request = 0, icons = null;

  function text(key) {
    return cfg.strings[key] || '';
  }

  function el(tag, className, content) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (content !== undefined) {
      node.textContent = content;
    }
    return node;
  }

  // --- the web service -------------------------------------------------------
  function call(method, fields, post, keepalive) {
    var params = new URLSearchParams();
    params.append('pwg_token', cfg.token);
    for (var name in fields) {
      params.append(name, fields[name]);
    }
    var url = cfg.url + '&method=' + method;
    var options = {credentials: 'same-origin'};
    if (post) {
      options.method = 'POST';
      options.headers = {'Content-Type': 'application/x-www-form-urlencoded'};
      options.body = params.toString();
      options.keepalive = !!keepalive;
    }
    else {
      url += '&' + params.toString();
    }

    return fetch(url, options)
      .then(function(response) { return response.json(); })
      .then(function(data) {
        if (data.stat !== 'ok') {
          throw new Error(data.message || 'error');
        }
        return data.result;
      });
  }

  // --- the number ----------------------------------------------------------
  function setCount(value) {
    count = Math.max(0, parseInt(value, 10) || 0);
    badge.textContent = count > 99 ? '99+' : String(count);
    badge.hidden = count === 0;
    var label = count > 0 ? text('unread_count').replace('%d', count) : text('title');
    button.setAttribute('aria-label', label);
    button.title = label;
    if (markAll) {
      markAll.disabled = count === 0;
    }
  }

  // --- the list --------------------------------------------------------------
  function load() {
    var current = ++request;
    list.setAttribute('aria-busy', 'true');

    call('likeBravo.feedbackList', {per_page: 20}, false)
      .then(function(result) {
        if (current !== request) {
          return;
        }
        icons = result.icons;
        render(result.entries);
        setCount(result.unread);
      })
      .catch(function() {
        if (current === request) {
          list.textContent = '';
          list.appendChild(el('li', 'lb-bell-message', text('error')));
        }
      })
      .then(function() {
        list.removeAttribute('aria-busy');
      });
  }

  function render(entries) {
    list.textContent = '';
    if (!entries.length) {
      list.appendChild(el('li', 'lb-bell-message', text('empty')));
      return;
    }
    for (var i = 0; i < entries.length; i++) {
      list.appendChild(entry(entries[i]));
    }
  }

  function totals(item) {
    var box = el('span', 'lb-bell-totals');
    var parts = [['like', item.likes]];
    if (item.with_bravo) {
      parts.push(['bravo', item.bravos]);
    }
    for (var i = 0; i < parts.length; i++) {
      var part = el('span', 'lb-bell-total lb-bell-total-' + parts[i][0]);
      part.setAttribute('aria-hidden', 'true');
      part.innerHTML = icons ? icons[parts[i][0]] : '';
      part.appendChild(document.createTextNode(String(parts[i][1])));
      box.appendChild(part);
    }
    box.appendChild(el('span', 'lb-bell-sr', item.totals_text));
    return box;
  }

  function entry(item) {
    var li = el('li', 'lb-bell-entry' + (item.unread ? ' is-unread' : ''));
    li.dataset.key = JSON.stringify(item.key);

    var link = el('a', 'lb-bell-link');
    link.href = item.url;

    var img = el('img', 'lb-bell-thumb');
    img.src = item.thumb;
    img.alt = item.thumb_alt;
    img.width = 48;
    img.height = 48;
    img.loading = 'lazy';
    link.appendChild(img);

    var body = el('span', 'lb-bell-body');
    var line = el('span', 'lb-bell-text');
    line.innerHTML = item.html; // built and escaped by the server
    body.appendChild(line);

    var meta = el('span', 'lb-bell-meta');
    meta.appendChild(el('span', 'lb-bell-when', item.when));
    if (item.kind !== 'comment') {
      meta.appendChild(el('span', 'lb-bell-sep', '·'));
      meta.appendChild(totals(item));
    }
    body.appendChild(meta);
    link.appendChild(body);

    if (item.unread) {
      link.appendChild(el('span', 'lb-bell-sr', text('unread')));
    }
    li.appendChild(link);

    if (item.unread) {
      var dot = el('button', 'lb-bell-dot');
      dot.type = 'button';
      dot.title = text('mark_one');
      dot.setAttribute('aria-label', text('mark_one'));
      li.appendChild(dot);
    }

    return li;
  }

  function markRead(li, keepalive) {
    var key = JSON.parse(li.dataset.key);
    li.classList.remove('is-unread');
    var dot = li.querySelector('.lb-bell-dot');
    if (dot) {
      dot.parentNode.removeChild(dot);
    }
    var sr = li.querySelector('.lb-bell-link > .lb-bell-sr');
    if (sr) {
      sr.parentNode.removeChild(sr);
    }
    setCount(count - 1);

    return call('likeBravo.feedbackMarkRead', key, true, keepalive)
      .then(function(result) { setCount(result.unread); })
      .catch(function() {});
  }

  function markAllRead() {
    var entries = list.querySelectorAll('.lb-bell-entry.is-unread');
    for (var i = 0; i < entries.length; i++) {
      entries[i].classList.remove('is-unread');
      var dot = entries[i].querySelector('.lb-bell-dot');
      if (dot) {
        dot.parentNode.removeChild(dot);
      }
      var sr = entries[i].querySelector('.lb-bell-link > .lb-bell-sr');
      if (sr) {
        sr.parentNode.removeChild(sr);
      }
    }
    setCount(0);
    call('likeBravo.feedbackMarkAllRead', {}, true)
      .then(function(result) { setCount(result.unread); })
      .catch(function() {});
  }

  // --- opening and closing ---------------------------------------------------
  function isOpen() {
    return !panel.hidden;
  }

  // colours and font of the page around the bell, so that the list fits light
  // and dark themes (the list hangs in the body, outside the theme's containers)
  function adoptColours() {
    var node = button.parentNode, bg = '';
    while (node && node.nodeType === 1) {
      var c = window.getComputedStyle(node).backgroundColor;
      var m = c.match(/rgba?\(([^)]+)\)/);
      if (m) {
        var v = m[1].split(',');
        if (v.length < 4 || parseFloat(v[3]) > 0.5) {
          bg = 'rgb(' + v[0] + ',' + v[1] + ',' + v[2] + ')';
          break;
        }
      }
      node = node.parentNode;
    }
    if (!bg) {
      bg = window.getComputedStyle(document.body).backgroundColor || '#fff';
    }
    panel.style.setProperty('--lb-bell-bg', bg);
    panel.style.setProperty('--lb-bell-fg', window.getComputedStyle(button).color);
    panel.style.fontFamily = window.getComputedStyle(button.parentNode).fontFamily;
  }

  function position() {
    var rect = button.getBoundingClientRect();
    var width = Math.min(380, document.documentElement.clientWidth - 16);
    var left = Math.min(rect.right - width, document.documentElement.clientWidth - width - 8);
    panel.style.width = width + 'px';
    panel.style.left = Math.max(8, left) + window.pageXOffset + 'px';
    panel.style.top = rect.bottom + 6 + window.pageYOffset + 'px';
  }

  function open() {
    adoptColours();
    position();
    panel.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    document.documentElement.classList.add('lb-bell-open');
    panel.focus();
    load();
  }

  function close(returnFocus) {
    if (!isOpen()) {
      return;
    }
    panel.hidden = true;
    button.setAttribute('aria-expanded', 'false');
    document.documentElement.classList.remove('lb-bell-open');
    if (returnFocus) {
      button.focus();
    }
  }

  // --- the place of the bell -------------------------------------------------
  function visible(node) {
    return !!(node && (node.offsetWidth || node.offsetHeight || node.getClientRects().length));
  }

  function themePlace() {
    var target;
    switch (cfg.theme) {
      case 'bootstrap_darkroom':
        target = document.querySelector('nav.navbar-main .navbar-toggler');
        return target ? {parent: target.parentNode, before: target, cls: 'lb-bell-darkroom'} : null;
      case 'modus':
        target = document.querySelector('#menubar a[href*="act=logout"]');
        return target ? {parent: target.parentNode, before: target.parentNode.firstChild, cls: 'lb-bell-modus'} : null;
      case 'elegant':
        target = document.querySelector('#mbIdentification > dt');
        return target ? {parent: target, before: null, cls: 'lb-bell-elegant'} : null;
      case 'smartpocket':
        target = document.querySelector('[data-role="header"] .title a[href="#menubar"]');
        return target ? {parent: target.parentNode, before: target, cls: 'lb-bell-smartpocket'} : null;
    }
    return null;
  }

  // in the theme's place if it is visible (menus can be folded away), else in the corner
  function place() {
    var spot = themePlace();
    button.className = 'lb-bell-button';
    if (spot) {
      spot.parent.insertBefore(button, spot.before);
      if (visible(spot.parent)) {
        button.classList.add(spot.cls);
        return;
      }
    }
    document.body.appendChild(button);
    button.classList.add('lb-bell-fixed');
  }

  function build() {
    button = el('button', 'lb-bell-button');
    button.type = 'button';
    button.innerHTML = BELL;
    button.setAttribute('aria-haspopup', 'dialog');
    button.setAttribute('aria-expanded', 'false');
    badge = el('span', 'lb-bell-badge');
    button.appendChild(badge);

    panel = el('div', 'lb-bell-panel');
    panel.hidden = true;
    panel.tabIndex = -1;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', text('title'));

    var head = el('div', 'lb-bell-head');
    head.appendChild(el('span', 'lb-bell-title', text('title')));
    markAll = el('button', 'lb-bell-markall', text('mark_all'));
    markAll.type = 'button';
    head.appendChild(markAll);
    var closer = el('button', 'lb-bell-close');
    closer.type = 'button';
    closer.innerHTML = '<span aria-hidden="true">×</span>';
    closer.setAttribute('aria-label', text('close'));
    closer.title = text('close');
    head.appendChild(closer);
    panel.appendChild(head);

    list = el('ul', 'lb-bell-list');
    panel.appendChild(list);
    document.body.appendChild(panel);

    setCount(cfg.count);
    place();

    button.addEventListener('click', function(event) {
      event.preventDefault();
      event.stopPropagation();
      if (isOpen()) {
        close(false);
      }
      else {
        open();
      }
    });
    markAll.addEventListener('click', markAllRead);
    closer.addEventListener('click', function() { close(true); });

    list.addEventListener('click', function(event) {
      var li = event.target.closest('.lb-bell-entry');
      if (!li) {
        return;
      }
      if (event.target.closest('.lb-bell-dot')) {
        // the dot leaves the page: the click must not count as one outside the list
        event.stopPropagation();
        markRead(li, false);
        return;
      }
      var link = event.target.closest('.lb-bell-link');
      if (!link || !li.classList.contains('is-unread')) {
        return;
      }
      // a new tab or window: mark read and stay
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) {
        markRead(li, true);
        return;
      }
      event.preventDefault();
      var go = function() { window.location.href = link.href; };
      markRead(li, true).then(go, go);
    });

    document.addEventListener('click', function(event) {
      if (isOpen() && event.target.isConnected && !panel.contains(event.target) && !button.contains(event.target)) {
        close(false);
      }
    });
    document.addEventListener('keydown', function(event) {
      if (isOpen() && (event.key === 'Escape' || event.key === 'Esc')) {
        close(true);
      }
    });

    var timer = null;
    window.addEventListener('resize', function() {
      window.clearTimeout(timer);
      timer = window.setTimeout(function() {
        place();
        if (isOpen()) {
          position();
        }
      }, 150);
    });
    // menus that themes fold away and back
    var switcher = document.getElementById('menuSwitcher');
    if (switcher) {
      switcher.addEventListener('click', function() { window.setTimeout(place, 450); });
    }
  }

  function start(config) {
    cfg = config;
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', build);
    }
    else {
      build();
    }
  }

  return {start: start};
})();
