'use strict';
// Behaviour test for api/resources/widget/widget.js (P1-07), run by P1_07_WidgetTest through a
// plain `node` subprocess. No packages: a minimal fake DOM, a scripted fetch and fake timers.
// Adapted from the round-1 tester's harness.
//
//   node api/tests/js/widget.test.js api/resources/widget/widget.js
//
// Prints one PASS/FAIL line per check; exits 0 when all pass, 1 on a failed check, 2 when the
// harness itself breaks.
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(process.argv[2], 'utf8');

// Properties the widget may set on a node; anything else is reported.
const ALLOWED_SETS = new Set(['className', 'textContent', 'hidden', 'type', 'autocomplete', 'maxLength',
  'disabled', 'value', 'rel', 'href', 'scrollTop', 'parentNode']);
const SINK_PROPS = new Set(['innerHTML', 'outerHTML', 'srcdoc', 'src', 'style', 'cssText']);
const violations = [];

class FakeNode {
  constructor(tag, doc) {
    this.tagName = tag.toUpperCase();
    this.children = [];
    this.attrs = {};
    this.listeners = {};
    this._text = '';
    this.parentNode = null;
    this.hidden = false;
    this.disabled = false;
    this.value = '';
    this.scrollHeight = 100;
    this.scrollTop = 0;
    this.doc = doc;
    this.src = '';
    return new Proxy(this, {
      set(target, prop, value) {
        if (typeof prop === 'string') {
          if (SINK_PROPS.has(prop) || /^on/.test(prop)) {
            violations.push('sink ' + target.tagName + '.' + prop);
          } else if (!ALLOWED_SETS.has(prop) && !(prop in target)) {
            violations.push('set ' + target.tagName + '.' + prop);
          }
        }
        target[prop] = value;
        return true;
      },
    });
  }
  set textContent(v) { this._text = String(v); this.children = []; }
  get textContent() { return this._text + this.children.map((c) => c.textContent).join(''); }
  setAttribute(n, v) {
    if (/^on/i.test(n) || /^(style|srcdoc|href|src)/i.test(n)) violations.push('setAttribute ' + n);
    this.attrs[n] = String(v);
  }
  getAttribute(n) { return Object.prototype.hasOwnProperty.call(this.attrs, n) ? this.attrs[n] : null; }
  hasAttribute(n) { return Object.prototype.hasOwnProperty.call(this.attrs, n); }
  insertAdjacentHTML() { violations.push('sink insertAdjacentHTML'); }
  setHTML() { violations.push('sink setHTML'); }
  setHTMLUnsafe() { violations.push('sink setHTMLUnsafe'); }
  appendChild(c) { c.parentNode = this; this.children.push(c); return c; }
  insertBefore(c, ref) {
    c.parentNode = this;
    const i = ref ? this.children.indexOf(ref) : -1;
    if (i < 0) this.children.push(c); else this.children.splice(i, 0, c);
    return c;
  }
  get nextSibling() {
    if (!this.parentNode) return null;
    const s = this.parentNode.children;
    return s[s.indexOf(this) + 1] || null;
  }
  replaceChildren(...nodes) { this.children = []; nodes.forEach((n) => this.appendChild(n)); }
  contains(n) { if (n === this) return true; return this.children.some((c) => c.contains(n)); }
  addEventListener(t, f) { (this.listeners[t] = this.listeners[t] || []).push(f); }
  dispatch(t, ev) { (this.listeners[t] || []).forEach((f) => f(ev || { preventDefault() {} })); }
  isHidden() { let n = this; while (n) { if (n.hidden) return true; n = n.parentNode; } return false; }
  // Like a browser: a disabled or hidden control ignores clicks and focus.
  click() { if (!this.disabled && !this.isHidden()) this.dispatch('click'); }
  focus() {
    if (this.disabled || this.isHidden() || this.doc.activeElement === this) return;
    this.doc.activeElement = this;
    this.dispatch('focus');
  }
  all() { return [this].concat(...this.children.map((c) => c.all())); }
  querySelectorAll(sel) {
    if (sel !== 'button') throw new Error('unsupported selector ' + sel);
    return this.all().filter((n) => n !== this && n.tagName === 'BUTTON');
  }
  byClass(c) { return this.all().filter((n) => (' ' + (n.className || '') + ' ').includes(' ' + c + ' ')); }
}

function makeEnv(scriptSrc, opts) {
  const doc = {};
  const make = (t) => new FakeNode(t, doc);
  doc.head = make('head');
  doc.body = make('body');
  doc.readyState = 'complete';
  doc.activeElement = null;
  doc.createElement = make;
  doc.write = () => violations.push('sink document.write');
  doc.writeln = () => violations.push('sink document.writeln');
  doc.querySelector = (sel) => {
    if (sel === 'link.fxw-stylesheet') return doc.head.all().find((n) => n.tagName === 'LINK' && n.className === 'fxw-stylesheet') || null;
    throw new Error('unsupported ' + sel);
  };
  doc.getElementById = (id) => doc.body.all().find((n) => n.attrs.id === id) || null;
  doc.addEventListener = () => {};
  const script = make('script');
  const mark = violations.length;
  script.src = scriptSrc;
  violations.length = mark; // the harness's own set, not the widget's
  if (opts && opts.target) script.attrs['data-fx-target'] = opts.target;
  if (opts && opts.targetEl) { const t = make('div'); t.attrs.id = opts.targetEl; doc.body.appendChild(t); }
  if (opts && opts.inHead) doc.head.appendChild(script); else doc.body.appendChild(script);
  doc.currentScript = script;

  const calls = [];
  const timers = [];
  const env = {
    doc, calls, timers,
    queue: [],
    fetch(url, init) {
      const call = { url, init, body: JSON.parse(init.body) };
      calls.push(call);
      return new Promise((resolve, reject) => {
        call.resolve = resolve; call.reject = reject;
        init.signal.addEventListener('abort', () => { const e = new Error('aborted'); e.name = 'AbortError'; reject(e); });
        setImmediate(() => { const next = env.queue.shift(); if (next) next(call); });
      });
    },
  };
  const ctx = {
    document: doc, URL, AbortController, console, Array, JSON, Promise,
    fetch: env.fetch,
    setTimeout(fn, ms) { const t = { fn, ms, cleared: false }; timers.push(t); return t; },
    clearTimeout(t) { if (t) t.cleared = true; },
  };
  vm.createContext(ctx);
  vm.runInContext(source, ctx);
  return env;
}

const json = (status, obj) => ({ status, json: async () => obj });
const okReply = (data) => (call) => call.resolve(json(200, { ok: true, data }));
const errReply = (status, code, msg) => (call) => call.resolve(json(status, { ok: false, error: { code, message_for_user: msg } }));
const held = (data) => { const h = {}; h.handler = (call) => { h.release = () => call.resolve(json(200, { ok: true, data })); }; return h; };
const tick = async () => { for (let i = 0; i < 6; i++) await new Promise((r) => setImmediate(r)); };
const results = [];
function check(name, cond, extra) { results.push((cond ? 'PASS ' : 'FAIL ') + name + (cond || extra === undefined ? '' : ' :: ' + JSON.stringify(extra))); }

function parts(env) {
  const root = env.doc.body.byClass('fxw')[0];
  return {
    root,
    log: root.byClass('fxw-log')[0],
    choices: root.byClass('fxw-choices')[0],
    form: root.byClass('fxw-form')[0],
    input: root.byClass('fxw-input')[0],
    send: root.byClass('fxw-send')[0],
    ending: root.byClass('fxw-ending')[0],
    again: root.byClass('fxw-again')[0],
  };
}
// [class, visible text, screen-reader prefix] per message.
const msgs = (p) => p.log.children.map((line) => {
  const text = line.byClass('fxw-text')[0];
  const prefix = line.byClass('fxw-hidden')[0];
  return [line.className, text ? text.textContent : line.textContent, prefix ? prefix.textContent : ''];
});
const texts = (p) => msgs(p).map((m) => m[1]);
const last = (p) => msgs(p).slice(-1)[0];
const buttons = (p) => p.choices.children.map((b) => b.textContent);
function type(p, text) { p.input.value = text; p.form.dispatch('submit', { preventDefault() {} }); }

const SID = 'a'.repeat(32);
const SID2 = 'b'.repeat(32);
const GREETING = 'Hi!\nWhat was the total price in USD?';
const GENERIC = "Sorry, the chat can't be reached right now. Please try again later.";

(async () => {
  // 1. Lazy open, then a whole conversation.
  {
    const env = makeEnv('https://api.test/bot/widget.js?v=3');
    await tick();
    const p = parts(env);
    check('lazy: no request on page load', env.calls.length === 0, env.calls.length);
    check('lazy: Start button shown, box usable, nothing focused', p.again.textContent === 'Start' && p.ending.hidden === false
      && p.form.hidden === false && p.input.disabled === false && env.doc.activeElement === null);
    check('stylesheet link injected from script src', env.doc.head.all().some((n) => n.tagName === 'LINK' && n.href === 'https://api.test/bot/widget.css'));

    // First focus opens the session; the user types and submits before it answers.
    const opening = held({ session_id: SID, reply: GREETING, step: 'amount', done: false });
    env.queue.push(opening.handler);
    p.input.focus();
    await tick();
    const c0 = env.calls[0];
    check('lazy: first focus opens one session', env.calls.length === 1 && c0.url === 'https://api.test/bot/message' && c0.init.method === 'POST', env.calls.length);
    check('open: body is {"text":""}', c0.init.body === '{"text":""}', c0.init.body);
    check('open: Content-Type application/json, credentials omit', c0.init.headers['Content-Type'] === 'application/json' && c0.init.credentials === 'omit');
    check('open: Start hidden while opening', p.ending.hidden === true);
    check('open: the box stays usable while opening', p.input.disabled === false && env.doc.activeElement === p.input);
    p.input.dispatch('focus');
    await tick();
    check('lazy: a second focus opens nothing more', env.calls.length === 1, env.calls.length);
    env.queue.push(okReply({ session_id: SID, reply: 'Which currency?', buttons: ['BRL', 'COP'], step: 'currency', done: false }));
    type(p, '200');
    await tick();
    check('lazy: a message sent while opening waits for the open', env.calls.length === 1 && p.input.value === '200', [env.calls.length, p.input.value]);
    opening.release();
    await tick(); await tick();
    check('lazy: the waiting message is then sent as an answer with the new id', env.calls.length === 2 && env.calls[1].body.text === '200' && env.calls[1].body.session_id === SID, env.calls.map((c) => c.body));
    check('lazy: greeting first, then the answer, then the reply', JSON.stringify(texts(p)) === JSON.stringify([GREETING, '200', 'Which currency?']), texts(p));
    check('timeouts armed at 15 s and cleared', env.timers.length === 2 && env.timers.every((t) => t.ms === 15000 && t.cleared), env.timers);
    check('screen-reader prefixes', JSON.stringify(msgs(p).map((m) => m[2])) === JSON.stringify(['Bot: ', 'You: ', 'Bot: ']), msgs(p));
    check('buttons shown', JSON.stringify(buttons(p)) === '["BRL","COP"]' && p.choices.hidden === false);

    // Whitespace-only is not sent.
    type(p, '   \t ');
    await tick();
    check('whitespace-only not sent', env.calls.length === 2);

    // A script tag typed in; the reply holds markup too. Both stay text.
    const scripted = held({ session_id: SID, reply: '<img src=x onerror=alert(1)>', step: 'currency', done: false });
    env.queue.push(scripted.handler);
    type(p, '<script>alert(1)</script>');
    await tick();
    check('in flight: input and send locked', p.input.disabled === true && p.send.disabled === true);
    check('in flight: aria-busy', p.log.getAttribute('aria-busy') === 'true');
    check('in flight: buttons removed', p.choices.children.length === 0 && p.choices.hidden === true);
    type(p, '300');
    p.send.click();
    await tick();
    check('no double submit while in flight', env.calls.length === 3, env.calls.length);
    check('session id reused', env.calls[2].body.session_id === SID && env.calls[2].body.text === '<script>alert(1)</script>', env.calls[2].body);
    scripted.release();
    await tick(); await tick();
    check('user text shown verbatim as text', msgs(p).slice(-2)[0][1] === '<script>alert(1)</script>', msgs(p));
    check('server markup shown as text', last(p)[1] === '<img src=x onerror=alert(1)>', last(p));
    check('message lines are P with span children only', p.log.children.every((c) => c.tagName === 'P' && c.children.every((s) => s.tagName === 'SPAN' && s.children.length === 0)));
    check('after reply: input unlocked and focused', p.input.disabled === false && env.doc.activeElement === p.input);

    // Buttons: only non-empty strings, as text; a click sends the text once.
    env.queue.push(okReply({ session_id: SID, reply: 'Which currency?', buttons: ['BRL', 'COP', 7, '', '<b>MXN</b>'], step: 'currency', done: false }));
    type(p, 'x');
    await tick(); await tick();
    check('buttons only non-empty strings, as text', JSON.stringify(buttons(p)) === JSON.stringify(['BRL', 'COP', '<b>MXN</b>']), buttons(p));
    const pressed = held({ session_id: SID, reply: '', step: 'installments', done: false });
    env.queue.push(pressed.handler);
    const [brl, cop] = p.choices.children;
    brl.click();
    brl.click();
    p.choices.children.forEach((b) => b.click());
    // A click already queued before the buttons were disabled and removed still arrives.
    cop.dispatch('click');
    brl.dispatch('click');
    await tick();
    check('button text sent once', env.calls.length === 5 && env.calls[4].body.text === 'BRL', env.calls.map((c) => c.body.text));
    const before = p.log.children.length;
    pressed.release();
    await tick(); await tick();
    check('empty reply skipped', p.log.children.length === before, msgs(p));
    check('no buttons after a step without buttons', p.choices.hidden === true);

    // Errors verbatim; the step's buttons come back.
    env.queue.push(okReply({ session_id: SID, reply: 'How many?', buttons: ['Just one payment'], step: 'installments', done: false }));
    type(p, 'y');
    await tick(); await tick();
    for (const [status, code, text] of [[400, 'BAD_REQUEST', 'Sorry, that message could not be read. Please try again.'], [429, 'RATE_LIMITED', 'Too many requests <b>.</b>'], [403, 'IP_BLOCKED', 'Access blocked.'], [500, 'INTERNAL', 'Something went wrong on our side.']]) {
      env.queue.push(errReply(status, code, text));
      type(p, '3');
      await tick(); await tick();
      check(status + ' message_for_user verbatim', last(p)[0] === 'fxw-msg fxw-error' && last(p)[1] === text && last(p)[2] === 'Error: ', last(p));
      check(status + ' buttons come back, input open', JSON.stringify(buttons(p)) === '["Just one payment"]' && p.input.disabled === false && p.form.hidden === false);
    }
    env.queue.push((call) => call.reject(new TypeError('Failed to fetch')));
    type(p, '3');
    await tick(); await tick();
    check('network failure: generic text', last(p)[1] === GENERIC, last(p));
    env.queue.push((call) => call.resolve({ status: 502, json: async () => { throw new SyntaxError('bad json'); } }));
    type(p, '3');
    await tick(); await tick();
    check('non-JSON body: generic text', last(p)[1] === GENERIC);
    env.queue.push(() => {});
    type(p, '3');
    await tick();
    const t = env.timers[env.timers.length - 1];
    check('timeout armed', t.ms === 15000 && !t.cleared);
    t.fn();
    await tick(); await tick();
    check('timeout: generic text, unlocked', last(p)[1] === GENERIC && p.input.disabled === false);
    env.queue.push((call) => call.resolve(json(200, { ok: false, error: { code: 'X', message_for_user: null } })));
    type(p, '3');
    await tick(); await tick();
    check('null message_for_user: generic text', last(p)[1] === GENERIC);

    // done: true ends the conversation; restart opens a fresh session.
    env.queue.push(okReply({ session_id: SID, reply: 'Result: 1,234.56 BRL', buttons: ['ignored'], step: 'result', done: true }));
    type(p, '3');
    await tick(); await tick();
    const end = msgs(p).slice(-2);
    check('done: result verbatim, then the ended note', end[0][1] === 'Result: 1,234.56 BRL' && end[1][1] === 'This conversation has ended.', end);
    check('done: form hidden, restart shown and focused', p.form.hidden === true && p.ending.hidden === false && p.again.textContent === 'Start a new conversation' && env.doc.activeElement === p.again);
    check('done: no buttons', p.choices.hidden === true && p.choices.children.length === 0);
    const n = env.calls.length;
    type(p, 'more');
    await tick();
    check('done: submit ignored', env.calls.length === n);
    env.queue.push(okReply({ session_id: SID2, reply: GREETING, step: 'amount', done: false }));
    p.again.click();
    await tick(); await tick();
    check('restart: opens at once, without the old id', env.calls.length === n + 1 && env.calls[n].init.body === '{"text":""}', env.calls[n] && env.calls[n].init.body);
    check('restart: log cleared, greeting only', JSON.stringify(texts(p)) === JSON.stringify([GREETING]), texts(p));
    check('restart: form back and focused', p.form.hidden === false && p.ending.hidden === true && p.input.disabled === false && env.doc.activeElement === p.input);
    check('restart: no second open from the focus', env.calls.length === n + 1, env.calls.length);
    env.queue.push(okReply({ session_id: SID2, reply: 'ok', step: 'currency', done: false }));
    type(p, '5');
    await tick(); await tick();
    check('restart: the new session id is used', env.calls[env.calls.length - 1].body.session_id === SID2);
  }

  // 2. Start button; the first open fails, then Try again.
  {
    const env = makeEnv('https://api.test/bot/widget.js', { inHead: true });
    await tick();
    const p = parts(env);
    check('script in head mounts at the end of body', env.doc.body.children[env.doc.body.children.length - 1].className === 'fxw-mount');
    env.queue.push((call) => call.reject(new TypeError('CORS')));
    p.again.click();
    await tick(); await tick();
    check('Start opens a session', env.calls.length === 1 && env.calls[0].init.body === '{"text":""}');
    check('first open fails: generic text and Try again', last(p)[1] === GENERIC && p.again.textContent === 'Try again' && p.form.hidden === true && env.doc.activeElement === p.again);
    env.queue.push(okReply({ session_id: SID, reply: GREETING, step: 'amount', done: false }));
    p.again.click();
    await tick(); await tick();
    check('Try again opens a session and focuses the box', env.calls.length === 2 && env.calls[1].init.body === '{"text":""}' && p.form.hidden === false && env.doc.activeElement === p.input);
  }

  // 3. Submitting before any open (no focus event) opens first, then sends the answer.
  {
    const env = makeEnv('https://api.test/bot/widget.js');
    await tick();
    const p = parts(env);
    env.queue.push(okReply({ session_id: SID, reply: GREETING, step: 'amount', done: false }));
    env.queue.push(okReply({ session_id: SID, reply: 'Which currency?', step: 'currency', done: false }));
    type(p, '200');
    await tick(); await tick(); await tick();
    check('submit without focus: open, then the answer', env.calls.length === 2 && env.calls[0].init.body === '{"text":""}' && env.calls[1].body.text === '200' && env.calls[1].body.session_id === SID, env.calls.map((c) => c.body));
  }

  // 4. data-fx-target.
  {
    const env = makeEnv('https://api.test/bot/widget.js', { target: 'here', targetEl: 'here' });
    await tick();
    const target = env.doc.getElementById('here');
    check('data-fx-target mounts into the element', target.children.length === 1 && target.children[0].className === 'fxw');
  }

  // 5. Odd server data.
  {
    const env = makeEnv('https://api.test/bot/widget.js');
    await tick();
    const p = parts(env);
    env.queue.push(okReply({ session_id: SID, reply: 123, step: 'amount', done: false }));
    p.input.focus();
    await tick(); await tick();
    check('non-string reply not shown', p.log.children.length === 0);
    env.queue.push((call) => call.resolve(json(200, 'string')));
    type(p, 'a');
    await tick(); await tick();
    check('non-object envelope: generic text', last(p)[1] === GENERIC && p.input.disabled === false);
  }

  check('no HTML sink, inline handler or unexpected property set', violations.length === 0, violations);
  console.log(results.join('\n'));
  const failed = results.filter((r) => r.startsWith('FAIL')).length;
  console.log(failed + ' failed of ' + results.length);
  process.exitCode = failed === 0 ? 0 : 1;
})().catch((e) => {
  console.log(results.join('\n'));
  console.log('HARNESS ERROR ' + (e && e.stack));
  process.exitCode = 2;
});
