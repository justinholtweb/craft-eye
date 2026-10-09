/**
 * The front-end runtime's consent-manager adapters, run under Node with no dependencies:
 *
 *     node --test tests/js/runtime.test.mjs
 *
 * `eye.js` is executed in a `vm` context against a deliberately small fake DOM — just the
 * selectors and element behaviour the runtime uses for click-to-load. Each test builds the markup
 * `_render/embed.twig` produces for a click-to-load embed (a card, and the frame parked in a
 * `<template>`), plays a consent manager at it, and checks whether a frame exists.
 *
 * The Toss adapter is exercised against Toss's published contract (craft-toss
 * `docs/consent-api.md`): `window.Toss.onConsent()` when Toss's runtime has already run, the
 * `toss:consent` event when it has not.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const RUNTIME = readFileSync(join(here, '../../src/web/assets/runtime/dist/eye.js'), 'utf8');

// ------------------------------------------------------------------------------------------------
// A fake DOM, as small as the runtime allows.
// ------------------------------------------------------------------------------------------------

class Events {
  constructor() { this.listeners = {}; }
  addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
  removeEventListener(type, fn) { this.listeners[type] = (this.listeners[type] || []).filter((f) => f !== fn); }
  dispatchEvent(event) {
    event.target ||= this;
    for (const fn of [...(this.listeners[event.type] || [])]) { fn.call(this, event); }
    return true;
  }
}

class FakeElement extends Events {
  constructor(tag, attrs = {}) {
    super();
    this.tagName = tag.toUpperCase();
    this.attrs = {};
    this.children = [];
    this.parentNode = null;
    this.hidden = false;
    this.style = {};
    const classes = new Set();
    this.classList = {
      add: (c) => classes.add(c),
      remove: (c) => classes.delete(c),
      contains: (c) => classes.has(c),
      _set: classes,
    };
    for (const [k, v] of Object.entries(attrs)) { this.setAttribute(k, v); }
  }

  setAttribute(name, value) {
    this.attrs[name] = String(value);
    if (name === 'class') { String(value).split(/\s+/).filter(Boolean).forEach((c) => this.classList.add(c)); }
  }
  getAttribute(name) { return name in this.attrs ? this.attrs[name] : null; }
  removeAttribute(name) { delete this.attrs[name]; }
  hasAttribute(name) { return name in this.attrs; }

  appendChild(child) { return this.insertBefore(child, null); }

  insertBefore(child, before) {
    const nodes = child instanceof FakeFragment ? [...child.children] : [child];
    if (child instanceof FakeFragment) { child.children = []; }
    for (const node of nodes) {
      if (node.parentNode) { node.parentNode.removeChild(node); }
      node.parentNode = this;
      const index = before ? this.children.indexOf(before) : -1;
      if (index === -1) { this.children.push(node); } else { this.children.splice(index, 0, node); }
    }
    return child;
  }

  removeChild(child) {
    this.children = this.children.filter((c) => c !== child);
    child.parentNode = null;
    return child;
  }

  get firstChild() { return this.children[0] || null; }

  matches(selector) {
    const cls = /^\.([\w-]+)$/.exec(selector);
    if (cls) { return this.classList.contains(cls[1]); }
    const attr = /^\[([\w-]+)\]$/.exec(selector);
    if (attr) { return this.hasAttribute(attr[1]); }
    const tag = /^(\w+)$/.exec(selector);
    if (tag) { return this.tagName === tag[1].toUpperCase(); }
    throw new Error('unsupported selector ' + selector);
  }

  descendants() {
    const out = [];
    for (const c of this.children) { out.push(c, ...c.descendants()); }
    return out;
  }

  querySelectorAll(selector) { return this.descendants().filter((e) => e.matches(selector)); }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }

  cloneNode() {
    const copy = new FakeElement(this.tagName, this.attrs);
    copy.children = this.children.map((c) => { const k = c.cloneNode(true); k.parentNode = copy; return k; });
    return copy;
  }
}

class FakeFragment extends FakeElement {
  constructor(children = []) { super('#fragment'); this.children = children; }
  cloneNode() { return new FakeFragment(this.children.map((c) => c.cloneNode(true))); }
}

class FakeTemplate extends FakeElement {
  constructor(attrs, content) {
    super('template', attrs);
    // A template's content is not part of the document: querySelector never finds it.
    this.content = content;
  }
}

/** The markup `_render/embed.twig` writes for a click-to-load embed. */
function clickEmbed(document, consent) {
  const figure = new FakeElement('figure', {
    class: 'eye eye--ratio eye--click',
    'data-eye': JSON.stringify({ mode: 'ratio', loading: 'click', timeout: 0, consent }),
  });
  const stage = new FakeElement('div', { class: 'eye-stage', style: 'aspect-ratio:16 / 9' });
  const card = new FakeElement('button', { class: 'eye-consent', 'data-eye-consent': '' });
  const frame = new FakeElement('iframe', { src: 'https://www.youtube-nocookie.com/embed/x', 'data-eye-frame': '' });
  const template = new FakeTemplate({ 'data-eye-template': '' }, new FakeFragment([frame]));
  const fallback = new FakeElement('div', { class: 'eye-fallback', 'data-eye-fallback': '' });
  fallback.hidden = true;

  stage.appendChild(card);
  stage.appendChild(template);
  stage.appendChild(fallback);
  figure.appendChild(stage);
  document.body.appendChild(figure);

  return {
    figure,
    card,
    framed: () => figure.querySelector('[data-eye-frame]') !== null,
    carded: () => figure.querySelector('[data-eye-consent]') !== null,
    click: () => card.dispatchEvent({ type: 'click' }),
  };
}

function setup({ before } = {}) {
  const document = new Events();
  document.readyState = 'complete';
  document.body = new FakeElement('body');
  document.documentElement = new FakeElement('html');
  document.documentElement.appendChild(document.body);
  document.querySelectorAll = (s) => document.documentElement.querySelectorAll(s);
  document.querySelector = (s) => document.documentElement.querySelector(s);

  const storage = {};
  const window = new Events();
  Object.assign(window, {
    document,
    setTimeout: (fn) => { window.__timers.push(fn); return window.__timers.length; },
    clearTimeout: () => {},
    __timers: [],
    localStorage: {
      getItem: (k) => (k in storage ? storage[k] : null),
      setItem: (k, v) => { storage[k] = String(v); },
      removeItem: (k) => { delete storage[k]; },
      key: (i) => Object.keys(storage)[i] ?? null,
      get length() { return Object.keys(storage).length; },
    },
    JSON, Object, Array, String, Math, Number,
  });
  window.window = window;

  const context = vm.createContext(window);
  context.globalThis = context;

  return {
    window,
    document,
    context,
    embed: (consent) => clickEmbed(document, consent),
    run: () => {
      if (before) { before(window, document); }
      vm.runInContext(RUNTIME, context);
    },
    /** Run any timers the runtime scheduled (Klaro's retry). */
    tick: () => { const timers = window.__timers.splice(0); timers.forEach((fn) => fn()); },
  };
}

const youtube = (manager, category = 'marketing', extra = {}) => ({ remember: false, key: null, category, manager, provider: 'youtube', ...extra });

// ------------------------------------------------------------------------------------------------
// No manager
// ------------------------------------------------------------------------------------------------

test('with no manager the card waits for its click', () => {
  const page = setup();
  const embed = page.embed(youtube('none'));
  page.run();

  assert.equal(embed.framed(), false);
  embed.click();
  assert.equal(embed.framed(), true);
  assert.equal(embed.carded(), false);
});

test('Eye.setConsent opens matching cards and nothing else, whatever the manager setting', () => {
  const page = setup();
  const video = page.embed(youtube('none'));
  const tool = page.embed(youtube('none', 'preferences', { provider: 'codepen' }));
  page.run();

  page.window.Eye.setConsent('marketing', true);
  assert.equal(video.framed(), true);
  assert.equal(tool.framed(), false);
  assert.equal(page.window.Eye.consent('marketing'), true);
  assert.equal(page.window.Eye.consent('analytics'), null);

  page.window.Eye.setConsent({ marketing: false });
  assert.equal(video.framed(), false, 'withdrawn consent puts the card back');
  assert.equal(video.carded(), true);
});

test('a card put back still opens on click', () => {
  const page = setup();
  const embed = page.embed(youtube('none'));
  page.run();

  page.window.Eye.setConsent('marketing', true);
  page.window.Eye.setConsent('marketing', false);
  embed.click();
  assert.equal(embed.framed(), true);
});

test('a card the reader opened is not closed by a manager', () => {
  const page = setup();
  const embed = page.embed(youtube('none'));
  page.run();

  embed.click();
  page.window.Eye.setConsent('marketing', false);
  assert.equal(embed.framed(), true);
});

test('necessary opens only once a manager has spoken', () => {
  const page = setup();
  const embed = page.embed(youtube('none', 'necessary'));
  page.run();

  assert.equal(embed.framed(), false);
  page.window.Eye.setConsent('analytics', false);
  assert.equal(embed.framed(), true);
});

test('undecided keeps the card', () => {
  const page = setup();
  const embed = page.embed(youtube('none'));
  page.run();

  page.window.Eye.setConsent('marketing', null);
  assert.equal(embed.framed(), false);
});

test('putting the card back restores the stage and drops the state classes', () => {
  const page = setup();
  const embed = page.embed(youtube('none'));
  page.run();

  page.window.Eye.setConsent('marketing', true);
  const stage = embed.figure.querySelector('.eye-stage');
  stage.setAttribute('style', 'height:900px');
  embed.figure.classList.add('eye--measured');
  page.window.Eye.setConsent('marketing', false);

  assert.equal(stage.getAttribute('style'), 'aspect-ratio:16 / 9');
  assert.equal(embed.figure.classList.contains('eye--measured'), false);
  assert.equal(embed.figure.classList.contains('eye--loaded-on-demand'), false);
});

// ------------------------------------------------------------------------------------------------
// Toss (the family contract)
// ------------------------------------------------------------------------------------------------

function fakeToss(window, initial) {
  let state = initial;
  const subscribers = [];
  window.Toss = {
    version: 1,
    consent: () => state,
    onConsent(fn) {
      subscribers.push(fn);
      if (state) { fn(JSON.parse(JSON.stringify(state))); }
      return () => {};
    },
  };
  return (next) => { state = next; subscribers.forEach((fn) => fn(JSON.parse(JSON.stringify(next)))); };
}

const snapshot = (categories) => ({
  categories: { necessary: true, preferences: null, analytics: null, marketing: null, ...categories },
  decided: true,
});

test('Toss already running: its known state is replayed and followed', () => {
  let decide;
  const page = setup({ before: (window) => { decide = fakeToss(window, snapshot({ marketing: true })); } });
  const embed = page.embed(youtube('toss'));
  page.run();

  assert.equal(embed.framed(), true);
  decide(snapshot({ marketing: false }));
  assert.equal(embed.framed(), false);
  assert.equal(embed.carded(), true);
});

test('Toss not yet run: the toss:consent load event opens the card', () => {
  const page = setup();
  const embed = page.embed(youtube('toss'));
  page.run();

  assert.equal(embed.framed(), false);
  page.document.dispatchEvent({ type: 'toss:consent', detail: { ...snapshot({ marketing: true }), reason: 'load' } });
  assert.equal(embed.framed(), true);
});

test('Toss: an undecided visitor keeps the card', () => {
  const page = setup({ before: (window) => { fakeToss(window, { categories: { necessary: true, marketing: null }, decided: false }); } });
  const embed = page.embed(youtube('toss'));
  page.run();

  assert.equal(embed.framed(), false);
});

test('Toss: GPC refusing marketing keeps a marketing card, opens a preferences one', () => {
  const page = setup({ before: (window) => { fakeToss(window, snapshot({ marketing: false, preferences: true })); } });
  const video = page.embed(youtube('toss'));
  const tool = page.embed(youtube('toss', 'preferences', { provider: 'codepen' }));
  page.run();

  assert.equal(video.framed(), false);
  assert.equal(tool.framed(), true);
});

// ------------------------------------------------------------------------------------------------
// Cookiebot, CookieYes, Klaro
// ------------------------------------------------------------------------------------------------

test('Cookiebot: statistics, marketing and preferences map to the four', () => {
  const page = setup({
    before: (window) => {
      window.Cookiebot = { hasResponse: false, consent: { necessary: true, preferences: false, statistics: false, marketing: false } };
    },
  });
  const video = page.embed(youtube('cookiebot'));
  const stats = page.embed(youtube('cookiebot', 'analytics'));
  page.run();

  assert.equal(video.framed(), false);

  Object.assign(page.window.Cookiebot, { hasResponse: true, consent: { necessary: true, preferences: false, statistics: true, marketing: true } });
  page.window.dispatchEvent({ type: 'CookiebotOnAccept' });
  assert.equal(video.framed(), true);
  assert.equal(stats.framed(), true);

  page.window.Cookiebot.consent.marketing = false;
  page.window.dispatchEvent({ type: 'CookiebotOnDecline' });
  assert.equal(video.framed(), false);
  assert.equal(stats.framed(), true);
});

test('CookieYes: advertisement is marketing, functional is preferences', () => {
  let consent = { isUserActionCompleted: false, categories: { necessary: true, functional: false, analytics: false, advertisement: false } };
  const page = setup({ before: (window) => { window.getCkyConsent = () => consent; } });
  const video = page.embed(youtube('cookieyes'));
  const tool = page.embed(youtube('cookieyes', 'preferences', { provider: 'codepen' }));
  page.run();

  assert.equal(video.framed(), false);
  consent = { isUserActionCompleted: true, categories: { necessary: true, functional: true, analytics: false, advertisement: true } };
  page.document.dispatchEvent({ type: 'cookieyes_consent_update', detail: { accepted: ['functional', 'advertisement'] } });
  assert.equal(video.framed(), true);
  assert.equal(tool.framed(), true);
});

function fakeKlaro(window, services, consents, confirmed = true) {
  const watchers = [];
  const manager = {
    config: { services },
    confirmed,
    getConsent: (name) => !!consents[name],
    watch: (w) => watchers.push(w),
  };
  window.klaro = { getManager: () => manager };
  return (next) => { Object.assign(consents, next); watchers.forEach((w) => w.update(manager, 'consents', {})); };
}

test('Klaro: a service named after the provider decides for it', () => {
  let update;
  const page = setup({
    before: (window) => {
      update = fakeKlaro(window, [
        { name: 'youtube', purposes: ['marketing'] },
        { name: 'pixel', purposes: ['marketing'] },
      ], { youtube: false, pixel: true });
    },
  });
  const video = page.embed(youtube('klaro'));
  page.run();

  assert.equal(video.framed(), false, 'youtube refused, though another marketing service is allowed');
  update({ youtube: true });
  assert.equal(video.framed(), true);
});

test('Klaro: otherwise every service with the purpose has to be allowed', () => {
  let update;
  const page = setup({
    before: (window) => {
      update = fakeKlaro(window, [
        { name: 'ads', purposes: ['advertising'] },
        { name: 'pixel', purposes: ['marketing'] },
      ], { ads: true, pixel: false });
    },
  });
  const map = page.embed(youtube('klaro', 'marketing', { provider: 'googlemaps' }));
  page.run();

  assert.equal(map.framed(), false);
  update({ pixel: true });
  assert.equal(map.framed(), true);
});

test('Klaro loaded after Eye is picked up', () => {
  const page = setup();
  const video = page.embed(youtube('klaro'));
  page.run();

  fakeKlaro(page.window, [{ name: 'youtube', purposes: ['marketing'] }], { youtube: true });
  page.tick();
  assert.equal(video.framed(), true);
});

// ------------------------------------------------------------------------------------------------
// Google Consent Mode
// ------------------------------------------------------------------------------------------------

/** What gtag() pushes: its `arguments` object. */
function gtagArgs() { return arguments; }

test('Consent Mode: a denying default is undecided; an update is the answer', () => {
  const page = setup({
    before: (window) => {
      window.dataLayer = [gtagArgs('consent', 'default', { ad_storage: 'denied', analytics_storage: 'denied', wait_for_update: 500 })];
    },
  });
  const video = page.embed(youtube('consentmode'));
  const stats = page.embed(youtube('consentmode', 'analytics'));
  page.run();

  assert.equal(video.framed(), false);
  assert.equal(page.window.Eye.consent('marketing'), null);

  page.window.dataLayer.push(gtagArgs('consent', 'update', { ad_storage: 'granted', analytics_storage: 'denied' }));
  assert.equal(video.framed(), true);
  assert.equal(stats.framed(), false);
  assert.equal(page.window.Eye.consent('analytics'), false);

  page.window.dataLayer.push(gtagArgs('consent', 'update', { ad_storage: 'denied' }));
  assert.equal(video.framed(), false);
});

test('Consent Mode: pushes still reach the dataLayer', () => {
  const page = setup({ before: (window) => { window.dataLayer = []; } });
  page.embed(youtube('consentmode'));
  page.run();

  page.window.dataLayer.push({ event: 'x' });
  assert.equal(page.window.dataLayer.length, 1);
});

// ------------------------------------------------------------------------------------------------
// Automatic, and none
// ------------------------------------------------------------------------------------------------

test('automatic finds whichever manager the page has', () => {
  const page = setup({
    before: (window) => {
      window.Cookiebot = { hasResponse: true, consent: { necessary: true, preferences: false, statistics: false, marketing: true } };
    },
  });
  const video = page.embed(youtube('auto'));
  page.run();

  assert.equal(video.framed(), true);
});

test('automatic reads Consent Mode when the page has a dataLayer', () => {
  const page = setup({ before: (window) => { window.dataLayer = []; } });
  const video = page.embed(youtube('auto'));
  page.run();

  page.window.dataLayer.push(gtagArgs('consent', 'update', { ad_storage: 'granted' }));
  assert.equal(video.framed(), true);
});

test('none ignores a manager on the page', () => {
  const page = setup({
    before: (window) => {
      window.Cookiebot = { hasResponse: true, consent: { necessary: true, preferences: true, statistics: true, marketing: true } };
    },
  });
  const video = page.embed(youtube('none'));
  page.run();

  page.window.dispatchEvent({ type: 'CookiebotOnAccept' });
  assert.equal(video.framed(), false);
});

test('a remembered click still opens the card without a manager', () => {
  const page = setup({ before: (window) => { window.localStorage.setItem('eye:consent:www.youtube-nocookie.com', 'yes'); } });
  const video = page.embed(youtube('none', 'marketing', { remember: true, key: 'eye:consent:www.youtube-nocookie.com' }));
  page.run();

  assert.equal(video.framed(), true);
});
