/*
 * FX Installments chat widget (docs/PLAN.md section 7, P1-07). Plain JavaScript, no build step.
 *
 * Three ways to use it:
 *   - its own page, GET /bot/widget;
 *   - that page in an iframe (the host origin must be in cors.allowed_origins);
 *   - one tag on another site (same origin rule):
 *       <script src="https://API_HOST/bot/widget.js" defer></script>
 *     Add data-fx-target="some-id" to render into that element instead of after the tag.
 *
 * It talks to bot/message next to its own URL (POST /bot/message, ADR 0021). Nothing is sent
 * when the page loads: the session opens with an empty text when the user first focuses the
 * message box or presses Start chat. Every text, the server's and the user's, is shown as plain text
 * through textContent; nothing is parsed as markup. The widget shows the bot's reply exactly as
 * sent: it never computes, rounds or formats money (INV-01).
 */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script || !script.src) {
    return;
  }

  // Widget chrome only. The bot's wording comes from the server (flows/default.yaml).
  var TEXT = {
    region: 'Installments chat',
    log: 'Conversation',
    choices: 'Suggested answers',
    label: 'Your message',
    send: 'Send',
    start: 'Start chat',
    restart: 'Start a new conversation',
    retry: 'Try again',
    ended: 'This conversation has ended.',
    unreachable: "Sorry, the chat can't be reached right now. Please try again later.",
    // Read by screen readers before each message; hidden on screen.
    you: 'You: ',
    bot: 'Bot: ',
    error: 'Error: '
  };
  var MAX_LENGTH = 500;
  var TIMEOUT_MS = 15000;
  var endpoint = new URL('message', script.src).href;
  var styleSheet = new URL('widget.css', script.src).href;

  function element(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  }

  function addStyleSheet() {
    if (document.querySelector('link.fxw-stylesheet')) {
      return;
    }
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.className = 'fxw-stylesheet';
    link.href = styleSheet;
    document.head.appendChild(link);
  }

  function mountPoint() {
    var id = script.getAttribute('data-fx-target');
    var target = id ? document.getElementById(id) : null;
    if (target) {
      return target;
    }
    var box = element('div', 'fxw-mount');
    if (document.body.contains(script)) {
      script.parentNode.insertBefore(box, script.nextSibling);
    } else {
      document.body.appendChild(box);
    }
    return box;
  }

  function speaker(kind) {
    if (kind === 'user') {
      return TEXT.you;
    }
    if (kind === 'bot') {
      return TEXT.bot;
    }
    if (kind === 'error') {
      return TEXT.error;
    }
    return '';
  }

  function start() {
    addStyleSheet();

    var root = element('section', 'fxw');
    root.setAttribute('aria-label', TEXT.region);

    var log = element('div', 'fxw-log');
    log.setAttribute('role', 'log');
    log.setAttribute('aria-live', 'polite');
    log.setAttribute('aria-label', TEXT.log);

    var choices = element('div', 'fxw-choices');
    choices.setAttribute('role', 'group');
    choices.setAttribute('aria-label', TEXT.choices);
    choices.hidden = true;

    var form = element('form', 'fxw-form');
    var label = element('label', 'fxw-label');
    var labelText = element('span', 'fxw-hidden', TEXT.label);
    var input = element('input', 'fxw-input');
    input.type = 'text';
    input.autocomplete = 'off';
    input.maxLength = MAX_LENGTH;
    label.appendChild(labelText);
    label.appendChild(input);
    var send = element('button', 'fxw-send', TEXT.send);
    send.type = 'submit';
    form.appendChild(label);
    form.appendChild(send);

    // Start chat, Try again and Start a new conversation: one button, three labels.
    var ending = element('div', 'fxw-ending');
    var again = element('button', 'fxw-again', TEXT.start);
    again.type = 'button';
    ending.appendChild(again);

    root.appendChild(log);
    root.appendChild(choices);
    root.appendChild(form);
    root.appendChild(ending);
    mountPoint().appendChild(root);

    var sessionId = null;
    var busy = false;
    var closed = false;
    var lastButtons = null;
    var pending = false;

    function say(kind, text) {
      var line = element('p', 'fxw-msg fxw-' + kind);
      var who = speaker(kind);
      if (who !== '') {
        line.appendChild(element('span', 'fxw-hidden', who));
      }
      line.appendChild(element('span', 'fxw-text', text));
      log.appendChild(line);
      log.scrollTop = log.scrollHeight;
    }

    function setBusy(on) {
      busy = on;
      log.setAttribute('aria-busy', on ? 'true' : 'false');
      // While the session is opening the box stays usable, so nothing typed is lost; while a
      // message is in flight it is locked (no double submit).
      var locked = closed || (on && sessionId !== null);
      input.disabled = locked;
      send.disabled = locked;
      choices.querySelectorAll('button').forEach(function (button) {
        button.disabled = on;
      });
    }

    function showButtons(list) {
      choices.replaceChildren();
      var labels = Array.isArray(list) ? list.filter(function (item) {
        return typeof item === 'string' && item !== '';
      }) : [];
      labels.forEach(function (text) {
        var button = element('button', 'fxw-choice', text);
        button.type = 'button';
        button.addEventListener('click', function () {
          submit(text, true);
        });
        choices.appendChild(button);
      });
      choices.hidden = labels.length === 0;
    }

    function close(buttonText, withNote) {
      closed = true;
      showButtons(null);
      if (withNote) {
        say('note', TEXT.ended);
      }
      again.textContent = buttonText;
      ending.hidden = false;
      form.hidden = true;
    }

    async function post(text) {
      var body = { text: text };
      if (sessionId !== null) {
        body.session_id = sessionId;
      }
      var controller = new AbortController();
      var timer = setTimeout(function () {
        controller.abort();
      }, TIMEOUT_MS);
      try {
        var response = await fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(body),
          credentials: 'omit',
          cache: 'no-store',
          signal: controller.signal
        });
        return await response.json();
      } catch (error) {
        // No answer in time, a body that isn't JSON, or a cross-origin answer without CORS
        // headers.
        return null;
      } finally {
        clearTimeout(timer);
      }
    }

    function handle(envelope) {
      var data = envelope && envelope.ok === true ? envelope.data : null;
      if (data && typeof data === 'object') {
        if (typeof data.session_id === 'string') {
          sessionId = data.session_id;
        }
        if (typeof data.reply === 'string' && data.reply !== '') {
          say('bot', data.reply);
        }
        if (data.done === true) {
          close(TEXT.restart, true);
          return;
        }
        lastButtons = Array.isArray(data.buttons) ? data.buttons : null;
        showButtons(lastButtons);
        return;
      }

      var error = envelope && envelope.ok === false ? envelope.error : null;
      say('error', error && typeof error.message_for_user === 'string' ? error.message_for_user : TEXT.unreachable);
      if (sessionId === null) {
        // The conversation never opened: offer to try again.
        close(TEXT.retry, false);
      } else {
        showButtons(lastButtons);
      }
    }

    function focusNext() {
      if (closed) {
        again.focus();
      } else {
        input.focus();
      }
    }

    // One answer in an open session.
    async function submit(text, byUser) {
      if (busy || closed || sessionId === null) {
        return;
      }
      setBusy(true);
      showButtons(null);
      say('user', text);
      handle(await post(text));
      setBusy(false);
      if (byUser) {
        focusNext();
      }
    }

    // Opens a new session with an empty text (ADR 0021: the first message of a session is
    // never an answer). A message submitted while it opens is sent right after, as the first
    // answer; it stays in the box until then.
    async function open(byUser) {
      if (busy) {
        return;
      }
      sessionId = null;
      closed = false;
      lastButtons = null;
      log.replaceChildren();
      showButtons(null);
      ending.hidden = true;
      form.hidden = false;
      setBusy(true);
      handle(await post(''));
      setBusy(false);
      var waiting = pending;
      pending = false;
      var text = input.value;
      if (waiting && !closed && sessionId !== null && text.trim() !== '') {
        input.value = '';
        submit(text, true);
        return;
      }
      if (byUser) {
        focusNext();
      }
    }

    input.addEventListener('focus', function () {
      if (sessionId === null && !busy && !closed) {
        open(false);
      }
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var text = input.value;
      if (text.trim() === '' || closed) {
        return;
      }
      if (sessionId === null) {
        pending = true;
        open(true);
        return;
      }
      if (busy) {
        return;
      }
      input.value = '';
      submit(text, true);
    });

    again.addEventListener('click', function () {
      open(true);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
