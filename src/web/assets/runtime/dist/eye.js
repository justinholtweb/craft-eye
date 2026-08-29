/**
 * Eye — the front-end runtime.
 *
 * Zero dependencies, no build step, and registered only when an embed on the page actually needs
 * it. Everything presentational has already happened in CSS by the time this runs; what is left
 * is the three things CSS cannot do:
 *
 *   1. not loading a third party until the reader asks (click-to-load)
 *   2. giving a frame the height of its own content (auto height)
 *   3. noticing that a frame never arrived, and saying so
 *
 * A note on what is *not* possible: a cross-origin page blocked by `X-Frame-Options` still fires
 * `load` in most browsers, and gives the parent no way to tell. That is why Eye checks framing
 * headers when the embed is saved rather than pretending it can detect it here — and why the
 * timeout below is a safety net for slow and dead frames, not a blocked-content detector.
 */
(function () {
    'use strict';

    var PROTOCOL = 1;
    var INITIALISED = '__eyeReady';

    function parseConfig(el) {
        try {
            return JSON.parse(el.getAttribute('data-eye') || '{}');
        } catch (e) {
            return {};
        }
    }

    function clamp(value, min, max) {
        if (min && value < min) value = min;
        if (max && value > max) value = max;
        return Math.round(value);
    }

    function stored(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    }

    function store(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (e) {
            /* Private browsing, or storage disabled. Consent simply is not remembered. */
        }
    }

    // -----------------------------------------------------------------------

    function Embed(el) {
        this.el = el;
        this.config = parseConfig(el);
        this.stage = el.querySelector('.eye-stage') || el;
        this.loader = el.querySelector('[data-eye-loader]');
        this.fallbackEl = el.querySelector('[data-eye-fallback]');
        this.template = el.querySelector('[data-eye-template]');
        this.consentEl = el.querySelector('[data-eye-consent]');
        this.frame = el.querySelector('[data-eye-frame]');
        this.timer = null;
        this.observer = null;
    }

    Embed.prototype.init = function () {
        var self = this;

        if (this.consentEl) {
            var consent = this.config.consent || {};

            if (consent.remember && consent.key && stored(consent.key) === 'yes') {
                this.load();
            } else {
                this.consentEl.addEventListener('click', function () {
                    if (consent.remember && consent.key) store(consent.key, 'yes');
                    self.load();
                });
            }

            return;
        }

        if (this.frame) this.watchFrame();
    };

    /** Swap the consent card for the real frame. Nothing has been requested before this point. */
    Embed.prototype.load = function () {
        if (!this.template) return;

        var fragment = this.template.content.cloneNode(true);

        if (this.consentEl) {
            this.consentEl.parentNode.removeChild(this.consentEl);
            this.consentEl = null;
        }

        this.stage.insertBefore(fragment, this.stage.firstChild);
        this.frame = this.el.querySelector('[data-eye-frame]');

        // A frame that was going to be clicked into existence should not then be lazy about it.
        if (this.frame) this.frame.setAttribute('loading', 'eager');

        this.el.classList.add('eye--loaded-on-demand');
        this.watchFrame();
    };

    Embed.prototype.watchFrame = function () {
        var self = this;
        var frame = this.frame;

        frame.addEventListener('load', function () {
            self.onLoad();
        });

        if (this.config.timeout > 0) {
            this.timer = window.setTimeout(function () {
                if (!self.el.classList.contains('eye--ready')) self.showFallback();
            }, this.config.timeout);
        }

        if (this.config.auto) this.startAutoHeight();
    };

    Embed.prototype.onLoad = function () {
        window.clearTimeout(this.timer);
        this.el.classList.add('eye--ready');
        if (this.loader) this.loader.hidden = true;
        if (this.fallbackEl) this.fallbackEl.hidden = true;

        if (this.config.auto) {
            this.measure();
            // Navigation inside the frame replaces the document, so anything observed on the old
            // one is now watching a detached node.
            this.attachObserver();
            if (this.config.auto.scrollToTop && this.hasNavigated) this.scrollIntoViewIfAbove();
            this.hasNavigated = true;
        }
    };

    Embed.prototype.showFallback = function () {
        this.el.classList.add('eye--failed');
        if (this.loader) this.loader.hidden = true;
        if (this.fallbackEl) this.fallbackEl.hidden = false;
        if (this.frame) this.frame.style.visibility = 'hidden';
    };

    // Auto height
    // -----------------------------------------------------------------------

    Embed.prototype.startAutoHeight = function () {
        var self = this;

        if (this.config.auto.sameOrigin) {
            this.attachObserver();
            return;
        }

        // Cross-origin: the frame has to tell us. It can only do that if the remote page includes
        // `eye-child.js`, so this listener may never fire — in which case the frame keeps its
        // starting height and nothing breaks.
        this.messageHandler = function (event) {
            if (!self.frame || event.source !== self.frame.contentWindow) return;

            var data = event.data;
            if (!data || data.eye !== 'height' || data.v !== PROTOCOL) return;

            self.setHeight(data.height);
        };

        window.addEventListener('message', this.messageHandler);

        this.frame.addEventListener('load', function () {
            try {
                self.frame.contentWindow.postMessage({ eye: 'ping', v: PROTOCOL }, '*');
            } catch (e) {
                /* Nothing to do — the frame will report on its own if it can. */
            }
        });
    };

    /**
     * Same-origin only: watch the framed document and follow it.
     *
     * ResizeObserver on the framed `<html>` catches everything a load event misses — an image
     * arriving late, an accordion opening, a web font reflowing the page.
     */
    Embed.prototype.attachObserver = function () {
        if (!this.config.auto || !this.config.auto.sameOrigin) return;

        var self = this;
        var doc = this.document();
        if (!doc) return;

        if (this.observer) this.observer.disconnect();

        var target = this.config.auto.selector ? doc.querySelector(this.config.auto.selector) : doc.documentElement;
        if (!target) target = doc.documentElement;

        if (typeof window.ResizeObserver === 'function') {
            this.observer = new window.ResizeObserver(function () {
                self.measure();
            });
            this.observer.observe(target);
        }

        // The framed document's own margins would otherwise be measured as content and grow the
        // frame by a few pixels on every pass.
        if (doc.body) doc.body.style.margin = doc.body.style.margin || '0';
    };

    Embed.prototype.document = function () {
        try {
            return this.frame.contentDocument || this.frame.contentWindow.document;
        } catch (e) {
            // Cross-origin after a redirect. Not an error; just not measurable.
            return null;
        }
    };

    Embed.prototype.measure = function () {
        var doc = this.document();
        if (!doc) return;

        var target = this.config.auto.selector ? doc.querySelector(this.config.auto.selector) : null;
        var height;

        if (target) {
            var box = target.getBoundingClientRect();
            height = box.height;
        } else {
            var body = doc.body;
            var html = doc.documentElement;
            height = Math.max(
                body ? body.scrollHeight : 0,
                body ? body.offsetHeight : 0,
                html ? html.clientHeight : 0,
                html ? html.scrollHeight : 0,
                html ? html.offsetHeight : 0
            );
        }

        this.setHeight(height);
    };

    Embed.prototype.setHeight = function (height) {
        height = clamp(Number(height) || 0, this.config.auto.min, this.config.auto.max);
        if (!height) return;

        this.stage.style.height = height + 'px';
        this.el.classList.add('eye--measured');
    };

    Embed.prototype.scrollIntoViewIfAbove = function () {
        var top = this.el.getBoundingClientRect().top;

        // Only when the frame's top has gone off the top of the window — otherwise a reader who
        // deliberately scrolled past a tall embed gets yanked back to it.
        if (top < 0) this.el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    // -----------------------------------------------------------------------

    var Eye = {
        /** Initialise every embed under `root` that has not been initialised already. */
        init: function (root) {
            var nodes = (root || document).querySelectorAll('[data-eye]');

            for (var i = 0; i < nodes.length; i++) {
                var el = nodes[i];
                if (el[INITIALISED]) continue;

                el[INITIALISED] = true;
                var embed = new Embed(el);
                el.eye = embed;
                embed.init();
            }
        },

        /** Load a click-to-load embed programmatically — a "load everything" button, a test. */
        load: function (el) {
            if (el && el.eye) el.eye.load();
        },

        /** Re-measure, for a template that changed an embed's surroundings. */
        refresh: function (el) {
            if (el && el.eye && el.eye.config.auto) el.eye.measure();
        },

        /** Forget every remembered consent. For a "privacy settings" link. */
        forgetConsent: function () {
            try {
                var keys = [];
                for (var i = 0; i < window.localStorage.length; i++) {
                    var key = window.localStorage.key(i);
                    if (key && key.indexOf('eye:consent:') === 0) keys.push(key);
                }
                keys.forEach(function (key) {
                    window.localStorage.removeItem(key);
                });
            } catch (e) {
                /* Nothing stored, nothing to forget. */
            }
        },
    };

    window.Eye = Eye;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            Eye.init();
        });
    } else {
        Eye.init();
    }

    // Embeds that arrive later — live preview, an AJAX-loaded tab, an infinite scroll — get
    // picked up without the site having to call anything.
    if (typeof window.MutationObserver === 'function') {
        var pending = false;
        new window.MutationObserver(function () {
            if (pending) return;
            pending = true;
            window.requestAnimationFrame(function () {
                pending = false;
                Eye.init();
            });
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
})();
