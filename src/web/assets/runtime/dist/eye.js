/**
 * Eye — the front-end runtime.
 *
 * Zero dependencies, no build step, and registered only when an embed on the page actually needs
 * it. Everything presentational has already happened in CSS by the time this runs; what is left
 * is the three things CSS cannot do:
 *
 *   1. not loading a third party until the reader asks (click-to-load) — or until the site's
 *      consent manager says they already have, and putting the card back when they withdraw it
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

    // Consent managers
    // -----------------------------------------------------------------------
    //
    // A click-to-load card opens by itself when the site's consent manager grants the embed's
    // category, and closes again when that is withdrawn. Eye reads the manager; it never decides
    // anything about consent itself, and it never writes to a manager.
    //
    // Categories are the family's four: necessary, preferences, analytics, marketing. Each value is
    // true (granted), false (refused) or null (not decided yet) — the shape Toss publishes — and
    // only true opens a card.

    var consentState = {};
    var heard = false;
    var watching = {};

    var hasOwn = function (object, key) {
        return Object.prototype.hasOwnProperty.call(object, key);
    };

    function tristate(value) {
        return value === true ? true : value === false ? false : null;
    }

    /** What the managers have said about a category (or, for Klaro, about one provider). */
    function granted(category, provider) {
        if (provider && hasOwn(consentState, 'provider:' + provider)) return consentState['provider:' + provider];

        // Necessary is always granted — but only once a consent manager has spoken, so an embed
        // marked necessary on a site with no manager still waits for its click.
        if (category === 'necessary') return heard ? true : null;

        return hasOwn(consentState, category) ? consentState[category] : null;
    }

    function setConsent(values) {
        var changed = !heard;
        heard = true;

        for (var key in values) {
            if (!hasOwn(values, key)) continue;

            var value = tristate(values[key]);

            if (consentState[key] !== value) {
                consentState[key] = value;
                changed = true;
            }
        }

        if (changed) applyConsent();
    }

    function applyConsent() {
        var nodes = document.querySelectorAll('[data-eye]');

        for (var i = 0; i < nodes.length; i++) {
            if (nodes[i].eye) nodes[i].eye.applyConsent();
        }
    }

    /** Toss, through its published contract: `window.Toss.onConsent()`, or the `toss:consent` event. */
    function watchToss() {
        var apply = function (snapshot) {
            if (snapshot && snapshot.categories) setConsent(snapshot.categories);
        };

        // onConsent replays the known state, then follows changes. Before Toss's runtime has run,
        // its load event is still to come, so listening for it misses nothing.
        if (window.Toss && typeof window.Toss.onConsent === 'function') {
            window.Toss.onConsent(apply);
        } else {
            document.addEventListener('toss:consent', function (event) {
                apply(event.detail);
            });
        }
    }

    function watchCookiebot() {
        var read = function () {
            var cookiebot = window.Cookiebot;
            if (!cookiebot || !cookiebot.consent) return;

            var decided = !!cookiebot.hasResponse;
            var consent = cookiebot.consent;

            setConsent({
                preferences: decided ? !!consent.preferences : null,
                analytics: decided ? !!consent.statistics : null,
                marketing: decided ? !!consent.marketing : null,
            });
        };

        ['CookiebotOnConsentReady', 'CookiebotOnLoad', 'CookiebotOnAccept', 'CookiebotOnDecline'].forEach(function (name) {
            window.addEventListener(name, read);
        });

        read();
    }

    function watchCookieYes() {
        var read = function () {
            if (typeof window.getCkyConsent !== 'function') return;

            var consent;

            try {
                consent = window.getCkyConsent();
            } catch (e) {
                return;
            }

            if (!consent || !consent.categories) return;

            var decided = !!consent.isUserActionCompleted;
            var categories = consent.categories;

            setConsent({
                preferences: decided ? !!categories.functional : null,
                analytics: decided ? !!categories.analytics : null,
                marketing: decided ? !!categories.advertisement : null,
            });
        };

        document.addEventListener('cookieyes_consent_update', read);
        document.addEventListener('cookieyes_banner_load', read);

        read();
    }

    /** Klaro's purposes that stand for each category, when no service is named after it. */
    var KLARO_PURPOSES = {
        preferences: ['preferences', 'functional'],
        analytics: ['analytics', 'statistics', 'performance'],
        marketing: ['marketing', 'advertising', 'advertisement'],
    };

    /**
     * Klaro consents per service, not per category. A service named after the embed's provider
     * (`youtube`) decides for that provider; otherwise a service named after the category
     * (`marketing`) decides for it; otherwise the category is granted only when every service
     * with a matching purpose is.
     */
    function klaroState(manager) {
        var config = manager.config || {};
        var services = config.services || config.apps || [];
        var decided = !!manager.confirmed;
        var out = {};

        var consentOf = function (name) {
            try {
                return !!manager.getConsent(name);
            } catch (e) {
                return false;
            }
        };

        services.forEach(function (service) {
            if (service && service.name) out['provider:' + service.name] = decided ? consentOf(service.name) : null;
        });

        Object.keys(KLARO_PURPOSES).forEach(function (category) {
            var members = services.filter(function (service) {
                return service && service.name === category;
            });

            if (!members.length) {
                members = services.filter(function (service) {
                    return service && service.name && (service.purposes || []).some(function (purpose) {
                        return KLARO_PURPOSES[category].indexOf(String(purpose).toLowerCase()) !== -1;
                    });
                });
            }

            if (members.length) {
                out[category] = decided ? members.every(function (service) {
                    return consentOf(service.name);
                }) : null;
            }
        });

        return out;
    }

    function watchKlaro() {
        var attached = false;
        var tries = 0;

        var attach = function () {
            if (attached) return true;

            var klaro = window.klaro;
            if (!klaro || typeof klaro.getManager !== 'function') return false;

            var manager;

            try {
                manager = klaro.getManager();
            } catch (e) {
                return false;
            }

            if (!manager) return false;

            attached = true;

            var read = function () {
                setConsent(klaroState(manager));
            };

            if (typeof manager.watch === 'function') manager.watch({ update: read });
            read();

            return true;
        };

        // Klaro is often loaded after this script; give it ten seconds to turn up.
        var retry = function () {
            if (attach() || ++tries > 40) return;
            window.setTimeout(retry, 250);
        };

        retry();
    }

    /** The Consent Mode type each category reads. */
    var CONSENT_MODE = {
        marketing: 'ad_storage',
        analytics: 'analytics_storage',
        preferences: 'functionality_storage',
    };

    /**
     * Google Consent Mode has no public read API, so Eye reads the `gtag('consent', …)` calls as
     * they go through `dataLayer` — the ones already there, and every one pushed after. A `default`
     * that denies is "not decided yet"; only an `update` is the reader's answer.
     */
    function watchConsentMode(create) {
        if (!window.dataLayer && !create) return false;

        var dataLayer = (window.dataLayer = window.dataLayer || []);
        if (typeof dataLayer.push !== 'function' || dataLayer.__eyeWatched) return true;

        var read = function (entry) {
            if (!entry || typeof entry !== 'object' || entry[0] !== 'consent') return;

            var command = entry[1];
            var values = entry[2];

            if ((command !== 'default' && command !== 'update') || !values || typeof values !== 'object') return;

            var out = {};
            var any = false;

            for (var category in CONSENT_MODE) {
                var value = values[CONSENT_MODE[category]];

                if (value === 'granted') {
                    out[category] = true;
                    any = true;
                } else if (value === 'denied') {
                    out[category] = command === 'update' ? false : null;
                    any = true;
                }
            }

            if (any) setConsent(out);
        };

        for (var i = 0; i < dataLayer.length; i++) read(dataLayer[i]);

        var push = dataLayer.push;
        dataLayer.push = function () {
            for (var j = 0; j < arguments.length; j++) read(arguments[j]);
            return push.apply(dataLayer, arguments);
        };
        dataLayer.__eyeWatched = true;

        return true;
    }

    /** Start listening to a manager. Once per page, however many embeds ask. */
    function watch(manager) {
        if (!manager || manager === 'none' || watching[manager]) return;
        watching[manager] = true;

        switch (manager) {
            case 'toss':
                watchToss();
                break;
            case 'cookiebot':
                watchCookiebot();
                break;
            case 'cookieyes':
                watchCookieYes();
                break;
            case 'klaro':
                watchKlaro();
                break;
            case 'consentmode':
                watchConsentMode(true);
                break;
            case 'auto':
                // Whichever the page turns out to have. Each watcher is a no-op without its
                // manager, so listening for all of them costs nothing.
                watchCookiebot();
                watchCookieYes();
                watchKlaro();

                if (!watchConsentMode(false)) {
                    var late = function () {
                        watchConsentMode(false);
                    };
                    document.addEventListener('DOMContentLoaded', late);
                    window.addEventListener('load', late);
                }
                break;
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
        // Kept so a withdrawn consent can put the card back exactly as it was.
        this.card = this.consentEl;
        this.stageStyle = this.stage.getAttribute('style');
        this.loaded = false;
        this.loadedBy = null;
    }

    Embed.prototype.init = function () {
        var self = this;

        if (this.consentEl) {
            var consent = this.config.consent || {};

            // Bound once, and kept: a card put back after a withdrawal has to work again.
            this.consentEl.addEventListener('click', function () {
                if (consent.remember && consent.key) store(consent.key, 'yes');
                self.load('reader');
            });

            watch(consent.manager);

            if (consent.remember && consent.key && stored(consent.key) === 'yes') {
                this.load('reader');
            } else {
                this.applyConsent();
            }

            return;
        }

        if (this.frame) this.watchFrame();
    };

    /**
     * Swap the consent card for the real frame. Nothing has been requested before this point.
     *
     * `by` says who opened it — the reader's click, the consent manager, or a script — because
     * only a card the manager opened is closed again when the manager withdraws consent. A reader
     * who clicked "load" asked for this one embed themselves.
     */
    Embed.prototype.load = function (by) {
        if (!this.template || this.loaded) return;

        var fragment = this.template.content.cloneNode(true);

        if (this.consentEl) {
            if (this.consentEl.parentNode) this.consentEl.parentNode.removeChild(this.consentEl);
            this.consentEl = null;
        }

        this.loaded = true;
        this.loadedBy = by || 'api';

        this.stage.insertBefore(fragment, this.stage.firstChild);
        this.frame = this.el.querySelector('[data-eye-frame]');

        // A frame that was going to be clicked into existence should not then be lazy about it.
        if (this.frame) this.frame.setAttribute('loading', 'eager');

        this.el.classList.add('eye--loaded-on-demand');
        this.watchFrame();
    };

    /** Put the card back and drop the frame — and with it the connection to the third party. */
    Embed.prototype.unload = function () {
        if (!this.loaded || !this.card) return;

        window.clearTimeout(this.timer);

        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }

        if (this.messageHandler) {
            window.removeEventListener('message', this.messageHandler);
            this.messageHandler = null;
        }

        if (this.frame && this.frame.parentNode) this.frame.parentNode.removeChild(this.frame);
        this.frame = null;

        this.stage.insertBefore(this.card, this.stage.firstChild);
        this.consentEl = this.card;

        if (this.stageStyle === null) {
            this.stage.removeAttribute('style');
        } else {
            this.stage.setAttribute('style', this.stageStyle);
        }

        if (this.fallbackEl) this.fallbackEl.hidden = true;

        ['eye--ready', 'eye--loaded-on-demand', 'eye--measured', 'eye--failed'].forEach(function (name) {
            this.el.classList.remove(name);
        }, this);

        this.loaded = false;
        this.loadedBy = null;
        this.hasNavigated = false;
    };

    /** Open or close the card to match what the consent manager has said. */
    Embed.prototype.applyConsent = function () {
        var consent = this.config.consent;
        if (!consent || !consent.category || !this.card) return;

        var answer = granted(consent.category, consent.provider);

        if (answer === true) {
            if (!this.loaded) this.load('manager');
        } else if (this.loaded && this.loadedBy === 'manager') {
            this.unload();
        }
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
            if (el && el.eye) el.eye.load('api');
        },

        /** Put a click-to-load embed's card back, dropping its frame. */
        unload: function (el) {
            if (el && el.eye) el.eye.unload();
        },

        /**
         * The generic consent adapter, for a consent manager Eye has no adapter for.
         *
         *     Eye.setConsent('marketing', true);
         *     Eye.setConsent({ marketing: false, analytics: true });
         *
         * `true` opens every card waiting for that category; `false` or `null` closes the ones a
         * consent manager opened. Works whatever the consent manager setting says.
         */
        setConsent: function (category, value) {
            if (category && typeof category === 'object') {
                setConsent(category);
            } else if (typeof category === 'string' && category !== '') {
                var values = {};
                values[category] = value;
                setConsent(values);
            }
        },

        /** What Eye has been told about a category: true, false, or null when nothing has. */
        consent: function (category) {
            return granted(category);
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
