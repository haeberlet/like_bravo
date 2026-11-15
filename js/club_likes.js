/* Club Likes: sets, changes and removes the vote of the connected member. */
(function() {
  'use strict';

  function init() {
    var widget = document.getElementById('clubLikes');
    if (!widget || widget.getAttribute('data-ready')) {
      return;
    }
    widget.setAttribute('data-ready', '1');

    var buttons = widget.querySelectorAll('.club-likes-button');
    var message = widget.querySelector('.club-likes-message');
    var saving = false;

    // singular only for exactly one, as club_likes_count_text() in PHP
    function text(count, one, many) {
      var pattern = widget.getAttribute('data-str-' + (count === 1 ? one : many));
      return pattern.replace('%d', count);
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

      var likes = widget.querySelector('.club-likes-count-likes');
      if (likes) {
        likes.textContent = text(state.likes, 'like', 'likes');
      }
      var bravos = widget.querySelector('.club-likes-count-bravos');
      if (bravos) {
        bravos.textContent = text(state.bravos, 'bravo', 'bravos');
      }
    }

    function send(level) {
      if (saving) {
        return;
      }
      saving = true;
      widget.classList.add('is-saving');
      message.textContent = '';

      var body = new URLSearchParams();
      body.append('image_id', widget.getAttribute('data-image-id'));
      body.append('level', level);
      body.append('pwg_token', widget.getAttribute('data-token'));

      fetch(widget.getAttribute('data-url'), {
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
          render(data.result);
        })
        .catch(function() {
          message.textContent = widget.getAttribute('data-str-error');
        })
        .then(function() {
          saving = false;
          widget.classList.remove('is-saving');
        });
    }

    widget.addEventListener('click', function(event) {
      var button = event.target.closest ? event.target.closest('.club-likes-button') : null;
      if (!button || !widget.contains(button)) {
        return;
      }
      event.preventDefault();

      var level = button.getAttribute('data-level');
      // a click on the active icon removes the vote
      if (level === widget.getAttribute('data-level')) {
        level = 'none';
      }
      send(level);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  }
  else {
    init();
  }
})();
