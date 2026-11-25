/* Like & Bravo: the bell of the feedback for owners. Shows the number of
   unread likes, bravos and comments on the member's photos and opens a list
   of the newest ones, loaded when the bell is opened. The bell goes to a
   known place of the theme, or to the top right corner of the window. */
var LikeBravoBell = (function() {
  'use strict';

  var BELL = '<svg class="lb-bell-icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">'
    + '<path d="M18 16v-5a6 6 0 0 0-5-5.9V4a1 1 0 0 0-2 0v1.1A6 6 0 0 0 6 11v5l-2 2v1h16v-1z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>'
    + '<path d="M10 20a2 2 0 0 0 4 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';

  var cfg, button, badge, panel, list, count = 0, request = 0, icons = null;
  var bellMore = null; // loading of older entries in the list of the bell
  var markAlls = []; // buttons "Mark all as read": in the list of the bell and on the page

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
    for (var i = 0; i < markAlls.length; i++) {
      markAlls[i].disabled = count === 0;
    }
    // numbers of unread entries on the page "All feedback"
    var numbers = document.querySelectorAll('[data-lb-unread]');
    for (var j = 0; j < numbers.length; j++) {
      numbers[j].textContent = String(count);
    }
  }

  // --- the list --------------------------------------------------------------
  function load() {
    var current = ++request;
    if (bellMore) {
      bellMore.stop();
      bellMore = null;
    }
    list.setAttribute('aria-busy', 'true');

    call('likeBravo.feedbackList', {per_page: 20}, false)
      .then(function(result) {
        if (current !== request) {
          return;
        }
        icons = result.icons;
        render(result.entries);
        setCount(result.unread);
        if (result.has_more && result.entries.length) {
          // older entries while scrolling down the list
          var sentinel = el('li', 'lb-bell-more');
          list.appendChild(sentinel);
          bellMore = moreLoader({
            box: list,
            sentinel: sentinel,
            scroller: list,
            fields: {per_page: 20},
            append: function(item, li) { list.insertBefore(li, sentinel); }
          });
        }
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

  // the thumbnail leads to the photo, the name of a member (in the text from
  // the server) to his home album; the rest of the entry is no link
  function entry(item) {
    var li = el('li', 'lb-bell-entry' + (item.unread ? ' is-unread' : ''));
    li.dataset.key = JSON.stringify(item.key);
    li.dataset.cursor = item.cursor;

    var photo = el('a', 'lb-bell-photo');
    photo.href = item.url;
    photo.setAttribute('data-ajax', 'false'); // SmartPocket: no AJAX page load
    var img = el('img', 'lb-bell-thumb');
    img.src = item.thumb;
    img.alt = item.thumb_alt;
    img.width = 48;
    img.height = 48;
    img.loading = 'lazy';
    photo.appendChild(img);
    li.appendChild(photo);

    var body = el('span', 'lb-bell-body');
    var line = el('span', 'lb-bell-text');
    line.innerHTML = item.html; // built and escaped by the server
    body.appendChild(line);

    var meta = el('span', 'lb-bell-meta');
    meta.appendChild(el('span', 'lb-bell-when', item.when));
    if (item.likes !== undefined) { // votes only
      meta.appendChild(el('span', 'lb-bell-sep', '·'));
      meta.appendChild(totals(item));
    }
    if (item.unread) {
      meta.appendChild(el('span', 'lb-bell-sr lb-bell-unread-sr', text('unread')));
    }
    body.appendChild(meta);
    li.appendChild(body);

    if (item.unread) {
      var dot = el('button', 'lb-bell-dot');
      dot.type = 'button';
      dot.setAttribute('data-role', 'none');
      dot.title = text('mark_one');
      dot.setAttribute('aria-label', text('mark_one'));
      li.appendChild(dot);
    }

    return li;
  }

  // shows an entry as read
  function clearUnread(li) {
    li.classList.remove('is-unread');
    var dot = li.querySelector('.lb-bell-dot');
    if (dot) {
      dot.parentNode.removeChild(dot);
    }
    var sr = li.querySelector('.lb-bell-unread-sr');
    if (sr) {
      sr.parentNode.removeChild(sr);
    }
  }

  // the same entry may be in the list of the bell and on the page
  function markRead(li, keepalive) {
    var key = JSON.parse(li.dataset.key);
    var same = document.querySelectorAll('.lb-bell-entry.is-unread');
    for (var i = 0; i < same.length; i++) {
      if (same[i].dataset.key === li.dataset.key) {
        clearUnread(same[i]);
      }
    }
    setCount(count - 1);

    return call('likeBravo.feedbackMarkRead', key, true, keepalive)
      .then(function(result) { setCount(result.unread); })
      .catch(function() {});
  }

  function markAllRead() {
    var entries = document.querySelectorAll('.lb-bell-entry.is-unread');
    for (var i = 0; i < entries.length; i++) {
      clearUnread(entries[i]);
    }
    setCount(0);
    call('likeBravo.feedbackMarkAllRead', {}, true)
      .then(function(result) { setCount(result.unread); })
      .catch(function() {});
  }

  // --- older entries while scrolling ----------------------------------------
  // In the list of the bell and on the page "All feedback": when the sentinel
  // at the end comes near, the entries older than the last one are loaded,
  // until the list is full and while scrolling on.
  //   box: element with the entries; sentinel: element after them;
  //   scroller: the scrolling element, null for the window;
  //   fields: parameters of likeBravo.feedbackList besides the cursor;
  //   append(item, li): puts a new entry in place; failed(cursor): optional
  function moreLoader(options) {
    if (!('IntersectionObserver' in window)) {
      options.sentinel.parentNode.removeChild(options.sentinel);
      return {stop: function() {}};
    }
    var busy = false, stopped = false;
    var status = el('span', 'lb-feedback-loading');
    status.setAttribute('role', 'status');
    options.sentinel.appendChild(status);

    function near() {
      var bottom = options.scroller ? options.scroller.getBoundingClientRect().bottom : window.innerHeight;
      return options.sentinel.getBoundingClientRect().top < bottom + 300;
    }

    function stop() {
      stopped = true;
      observer.disconnect();
      if (options.sentinel.parentNode) {
        options.sentinel.parentNode.removeChild(options.sentinel);
      }
    }

    function loadMore() {
      var entries = options.box.querySelectorAll('.lb-bell-entry');
      var last = entries.length ? entries[entries.length - 1] : null;
      if (busy || stopped || !last) {
        return;
      }
      busy = true;
      status.textContent = text('loading');
      options.box.setAttribute('aria-busy', 'true');

      var fields = {before: last.dataset.cursor};
      for (var name in options.fields) {
        fields[name] = options.fields[name];
      }
      call('likeBravo.feedbackList', fields, false)
        .then(function(result) {
          if (stopped) {
            return;
          }
          icons = result.icons;
          var shown = {}, all = options.box.querySelectorAll('.lb-bell-entry');
          for (var i = 0; i < all.length; i++) {
            shown[all[i].dataset.key] = true;
          }
          for (var j = 0; j < result.entries.length; j++) {
            if (!shown[JSON.stringify(result.entries[j].key)]) {
              options.append(result.entries[j], entry(result.entries[j]));
            }
          }
          setCount(result.unread);
          status.textContent = '';
          busy = false;
          if (!result.has_more || !result.entries.length) {
            stop();
          }
          else if (near()) {
            loadMore(); // the list is not full yet
          }
        })
        .catch(function() {
          if (stopped) {
            return;
          }
          status.textContent = text('error');
          observer.disconnect();
          if (options.failed) {
            options.failed(last.dataset.cursor);
          }
        })
        .then(function() {
          options.box.removeAttribute('aria-busy');
        });
    }

    var observer = new IntersectionObserver(function(changes) {
      if (changes[changes.length - 1].isIntersecting) {
        loadMore();
      }
    }, {root: options.scroller, rootMargin: '0px 0px 300px 0px'});
    observer.observe(options.sentinel);

    return {stop: stop};
  }

  // the page "All feedback": the entries under headings of days; instead of
  // the link "Older" (for browsers without JavaScript) they load on scrolling
  function pageMore(feed, more) {
    var link = more.querySelector('a[rel="next"]');
    if (!link || !('IntersectionObserver' in window)) {
      return;
    }
    var days = {};
    try {
      days = JSON.parse(feed.dataset.days || '{}');
    }
    catch (e) {}
    link.hidden = true;

    // the list of the day of the entry: the last one, or a new one under its heading
    function listOf(item) {
      var lists = feed.querySelectorAll('.lb-feedback-list');
      var current = lists.length ? lists[lists.length - 1] : null;
      if (current && current.dataset.day === item.day) {
        return current;
      }
      feed.appendChild(el('h3', 'lb-feedback-day', days[item.day] || ''));
      var next = el('ul', 'lb-bell-list lb-feedback-list');
      next.dataset.day = item.day;
      feed.appendChild(next);
      return next;
    }

    moreLoader({
      box: feed,
      sentinel: more,
      scroller: null,
      fields: {
        per_page: feed.dataset.perPage || 20,
        unread: feed.dataset.unread || 'false',
        kind: feed.dataset.kind || 'all'
      },
      append: function(item, li) { listOf(item).appendChild(li); },
      failed: function(cursor) {
        // the link stays as a way on
        link.href = link.href.replace(/([?&](?:amp;)?before=)[^&]*/, '$1' + encodeURIComponent(cursor));
        link.hidden = false;
      }
    });
  }

  // the side column stays in view while scrolling, below the bars that some
  // themes keep at the top of the window
  function sideTop(page) {
    var bottom = 0;
    var bars = document.querySelectorAll('header, nav, .navbar, .sticky-top, .fixed-top, [data-role="header"]');
    for (var i = 0; i < bars.length; i++) {
      var style = window.getComputedStyle(bars[i]);
      if ((style.position === 'sticky' || style.position === 'fixed') && parseFloat(style.top) <= 1 && visible(bars[i])) {
        bottom = Math.max(bottom, bars[i].offsetHeight + Math.max(0, parseFloat(style.top) || 0));
      }
    }
    page.style.setProperty('--lb-feedback-top', bottom + 16 + 'px');
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

  function bindList(target) {
    if (!target) {
      return;
    }
    target.addEventListener('click', function(event) {
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
      // the photo or the home album of the member
      var link = event.target.closest('a[href]');
      if (!link || !li.contains(link) || !li.classList.contains('is-unread')) {
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
  }

  function build() {
    button = el('button', 'lb-bell-button');
    button.type = 'button';
    button.setAttribute('data-role', 'none'); // SmartPocket: no restyling by jQuery Mobile
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
    var markAll = el('button', 'lb-bell-markall', text('mark_all'));
    markAll.type = 'button';
    markAlls.push(markAll);
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
    if (cfg.all_url) {
      var foot = el('div', 'lb-bell-foot');
      var all = el('a', 'lb-bell-all', text('all'));
      all.href = cfg.all_url;
      all.setAttribute('data-ajax', 'false');
      foot.appendChild(all);
      panel.appendChild(foot);
    }
    document.body.appendChild(panel);

    // the page "All feedback" has the same list
    var page = document.querySelector('.lb-feedback-page');
    if (page) {
      var pageMarkAll = page.querySelector('.lb-bell-markall');
      if (pageMarkAll) {
        markAlls.push(pageMarkAll);
        pageMarkAll.addEventListener('click', markAllRead);
      }
      var feed = page.querySelector('.lb-feedback-feed');
      bindList(feed);
      var more = page.querySelector('.lb-feedback-more');
      if (feed && more) {
        pageMore(feed, more);
      }
      sideTop(page);
    }

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

    bindList(list);

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
