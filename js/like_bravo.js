/* Like & Bravo: sets, changes and removes the vote of the current visitor
   and shows the names of the voters. Serves the widget of the photo page
   and the small displays of the thumbnails alike. */
(function() {
  'use strict';

  function setup(widget) {
    if (widget.getAttribute('data-ready')) {
      return;
    }
    widget.setAttribute('data-ready', '1');

    var buttons = widget.querySelectorAll('.like-bravo-button');
    var counters = widget.querySelectorAll('.like-bravo-count');
    var message = widget.querySelector('.like-bravo-message');
    var panel = widget.querySelector('.like-bravo-voters');
    var saving = false;
    var request = 0; // number of the latest request for names

    // singular only for exactly one, as like_bravo_count_text() in PHP
    function text(count, one, many) {
      var pattern = widget.getAttribute('data-str-' + (count === 1 ? one : many));
      return pattern.replace('%d', count);
    }

    function post(url, fields) {
      var body = new URLSearchParams();
      for (var name in fields) {
        body.append(name, fields[name]);
      }

      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body.toString()
      })
        .then(function(response) {
          return response.json();
        })
        .then(function(data) {
          if (data.stat !== 'ok') {
            throw new Error(data.message || 'error');
          }
          return data.result;
        });
    }

    // one counter: its text and, where names are shown, its short form
    function renderCounter(selector, count, one, many, summary) {
      var counter = widget.querySelector(selector);
      if (!counter) {
        return;
      }
      // thumbnails show the bare number, the text as tooltip
      if (counter.classList.contains('like-bravo-number')) {
        counter.textContent = count;
        counter.title = text(count, one, many);
        return;
      }
      counter.textContent = text(count, one, many);
      if (counter.hasAttribute('data-summary')) {
        counter.setAttribute('data-summary', summary || '');
        counter.disabled = count === 0;
      }
    }

    // the widget has a line for messages, a thumbnail only its tooltip
    function showError(failed) {
      if (message) {
        message.textContent = failed ? widget.getAttribute('data-str-error') : '';
      }
      else {
        widget.classList.toggle('has-error', failed);
        if (failed) {
          widget.title = widget.getAttribute('data-str-error');
        }
        else {
          widget.removeAttribute('title');
        }
      }
    }

    // shows the state the server answered with
    function render(state) {
      widget.setAttribute('data-level', state.level);

      for (var i = 0; i < buttons.length; i++) {
        var level = buttons[i].getAttribute('data-level');
        if (level === 'none') {
          buttons[i].hidden = state.level === 'none';
        }
        else {
          var active = level === state.level;
          buttons[i].classList.toggle('is-active', active);
          buttons[i].setAttribute('aria-pressed', active ? 'true' : 'false');
        }
      }

      var summaries = state.summaries || {};
      renderCounter('.like-bravo-count-likes', state.likes, 'like', 'likes', summaries.like);
      renderCounter('.like-bravo-count-bravos', state.bravos, 'bravo', 'bravos', summaries.bravo);

      if (panel && !panel.hidden) {
        if (state.likes + state.bravos > 0) {
          loadNames();
        }
        else {
          closeNames();
        }
      }
    }

    function send(level) {
      if (saving) {
        return;
      }
      saving = true;
      widget.classList.add('is-saving');
      showError(false);

      post(widget.getAttribute('data-url'), {
        image_id: widget.getAttribute('data-image-id'),
        level: level,
        pwg_token: widget.getAttribute('data-token')
      })
        .then(render)
        .catch(function() {
          showError(true);
        })
        .then(function() {
          saving = false;
          widget.classList.remove('is-saving');
        });
    }

    // complete lists of names, one line per level
    function renderNames(lists) {
      panel.textContent = '';

      for (var i = 0; i < lists.length; i++) {
        var line = document.createElement('p');
        line.className = 'like-bravo-voters-' + lists[i].level;

        var title = document.createElement('strong');
        title.textContent = lists[i].title;
        line.appendChild(title);

        line.appendChild(document.createTextNode(' ' + lists[i].names.concat(lists[i].extra).join(', ')));
        panel.appendChild(line);
      }
    }

    function setExpanded(expanded) {
      for (var i = 0; i < counters.length; i++) {
        counters[i].setAttribute('aria-expanded', expanded ? 'true' : 'false');
      }
    }

    function loadNames() {
      var current = ++request;
      message.textContent = '';

      post(widget.getAttribute('data-voters-url'), {
        image_id: widget.getAttribute('data-image-id')
      })
        .then(function(result) {
          if (current !== request) {
            return; // closed or asked again in the meantime
          }
          renderNames(result.lists);
          panel.hidden = false;
          setExpanded(true);
        })
        .catch(function() {
          if (current === request) {
            closeNames();
            message.textContent = widget.getAttribute('data-str-names-error');
          }
        });
    }

    function closeNames() {
      request++;
      if (panel) {
        panel.hidden = true;
        panel.textContent = '';
      }
      setExpanded(false);
    }

    widget.addEventListener('click', function(event) {
      var target = event.target.closest ? event.target : null;
      if (!target) {
        return;
      }

      var counter = target.closest('.like-bravo-count');
      if (counter && panel && widget.contains(counter)) {
        event.preventDefault();
        if (panel.hidden) {
          loadNames();
        }
        else {
          closeNames();
        }
        return;
      }

      var button = target.closest('.like-bravo-button');
      if (!button || !widget.contains(button)) {
        return;
      }
      event.preventDefault();
      // the thumbnail around the display must not open the photo
      event.stopPropagation();

      var level = button.getAttribute('data-level');
      // a click on the active icon removes the vote
      if (level === widget.getAttribute('data-level')) {
        level = 'none';
      }
      send(level);
    });

    if (panel) {
      document.addEventListener('click', function(event) {
        if (!panel.hidden && !widget.contains(event.target)) {
          closeNames();
        }
      });
      document.addEventListener('keydown', function(event) {
        if (!panel.hidden && (event.key === 'Escape' || event.key === 'Esc')) {
          closeNames();
        }
      });
    }
  }

  // Modus with justified rows builds its thumbnails without the template:
  // the displays wait in a hidden list and go into their thumbnail here
  function placePending() {
    var lists = document.querySelectorAll('.like-bravo-pending');
    for (var i = 0; i < lists.length; i++) {
      var items = lists[i].querySelectorAll('[data-href]');
      var links = lists[i].parentNode.querySelectorAll('li > a[href]');
      var byHref = {};
      for (var j = 0; j < links.length; j++) {
        byHref[links[j].getAttribute('href')] = links[j].parentNode;
      }
      for (var k = 0; k < items.length; k++) {
        var thumbnail = byHref[items[k].getAttribute('data-href')];
        var display = items[k].firstElementChild;
        if (thumbnail && display) {
          display.classList.add('like-bravo-badge');
          thumbnail.classList.add('like-bravo-has-badge');
          thumbnail.appendChild(display);
        }
      }
      lists[i].parentNode.removeChild(lists[i]);
    }
  }

  function init() {
    placePending();
    var widgets = document.querySelectorAll('#likeBravo, .like-bravo-thumb');
    for (var i = 0; i < widgets.length; i++) {
      setup(widgets[i]);
    }
  }

  // thumbnails loaded later, e.g. by an infinite scroll plugin (RV Thumb
  // Scroller): set up their displays as soon as they are in the page
  var DISPLAYS = '.like-bravo-thumb, .like-bravo-pending';
  var scheduled = false;

  function watch() {
    if (!window.MutationObserver) {
      return;
    }
    new MutationObserver(function(records) {
      if (scheduled) {
        return;
      }
      for (var i = 0; i < records.length; i++) {
        var nodes = records[i].addedNodes;
        for (var j = 0; j < nodes.length; j++) {
          var node = nodes[j];
          if (node.nodeType === 1 && (node.matches(DISPLAYS) || node.querySelector(DISPLAYS))) {
            scheduled = true;
            window.setTimeout(function() {
              scheduled = false;
              init();
            }, 0);
            return;
          }
        }
      }
    }).observe(document.body, {childList: true, subtree: true});
  }

  function start() {
    init();
    watch();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  }
  else {
    start();
  }
})();
