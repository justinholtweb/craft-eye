/**
 * Eye — the control panel editor.
 *
 * Drives two screens with the same three behaviours: recognise a URL as it is typed, ask whether
 * it will let this site frame it, and render it for real. "For real" matters — the preview goes
 * through the same renderer and the same template as the front end, so what an author approves is
 * what a reader gets.
 */
(function () {
    'use strict';

    function debounce(fn, wait) {
        var timer = null;

        return function () {
            var args = arguments;
            var self = this;
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                fn.apply(self, args);
            }, wait);
        };
    }

    function post(action, data) {
        return Craft.sendActionRequest('POST', action, { data: data });
    }

    /** The edit screen. */
    function initEditScreen(root) {
        var urlField = document.getElementById(root.id ? root.id + '-url' : 'url') || root.querySelector('input[name="url"]');
        var providerBox = root.querySelector('#eye-provider');
        var providerName = root.querySelector('.eye-edit__provider-name');
        var providerUrl = root.querySelector('.eye-edit__provider-url');
        var checkButton = root.querySelector('#eye-check');
        var framability = root.querySelector('#eye-framability');
        var previewButton = root.querySelector('#eye-preview-button');
        var preview = root.querySelector('#eye-preview');
        var embedId = root.getAttribute('data-embed-id');

        if (!urlField) return;

        var resolve = debounce(function () {
            var url = urlField.value.trim();

            if (!url) {
                if (providerBox) providerBox.classList.add('hidden');
                return;
            }

            post('eye/embeds/resolve', { url: url })
                .then(function (response) {
                    var data = response.data;
                    if (!providerBox) return;

                    providerBox.classList.remove('hidden');
                    if (providerName) providerName.textContent = data.providerName || '';
                    if (providerUrl) providerUrl.textContent = data.embedUrl || '';

                    // Only the poster: overwriting the author's mode or ratio while they type
                    // would fight them for control of the form.
                    var poster = root.querySelector('[name="options[posterUrl]"]');
                    if (poster && !poster.value && data.posterUrl) poster.value = data.posterUrl;
                })
                .catch(function () {
                    if (providerBox) providerBox.classList.add('hidden');
                });
        }, 400);

        urlField.addEventListener('input', resolve);

        if (checkButton && framability) {
            checkButton.addEventListener('click', function () {
                var url = urlField.value.trim();
                if (!url) return;

                checkButton.classList.add('loading');

                post('eye/embeds/check', { url: url, embedId: embedId || '' })
                    .then(function (response) {
                        var data = response.data;
                        checkButton.classList.remove('loading');

                        var dot = framability.querySelector('.status');
                        if (dot) dot.className = 'status ' + data.color;

                        var label = framability.querySelector('.eye-edit__framability-label');
                        if (label) label.textContent = data.label;

                        var message = framability.querySelector('.eye-edit__framability-message');
                        if (message) message.textContent = [data.message, data.suggestion].filter(Boolean).join(' ');
                    })
                    .catch(function () {
                        checkButton.classList.remove('loading');
                    });
            });
        }

        if (previewButton && preview) {
            previewButton.addEventListener('click', function () {
                var url = urlField.value.trim();
                if (!url) return;

                previewButton.classList.add('loading');
                preview.innerHTML = '';

                post('eye/embeds/preview', { url: url, options: collectOptions(root) })
                    .then(function (response) {
                        previewButton.classList.remove('loading');
                        preview.innerHTML = response.data.html || '';

                        if (response.data.error) {
                            preview.textContent = response.data.error;
                        } else if (window.Eye) {
                            // The preview markup arrived after the runtime initialised, so it has
                            // to be told. (The runtime's own MutationObserver would find it too,
                            // but only if the runtime is loaded in the CP at all.)
                            window.Eye.init(preview);
                        }
                    })
                    .catch(function () {
                        previewButton.classList.remove('loading');
                    });
            });
        }
    }

    /** Everything named `options[…]` under a root, as a flat object. */
    function collectOptions(root) {
        var out = {};
        var inputs = root.querySelectorAll('[name^="options["], [name*="[options]["]');

        for (var i = 0; i < inputs.length; i++) {
            var input = inputs[i];
            var match = input.name.match(/options\]?\[([^\]]+)\]/);
            if (!match) continue;

            var key = match[1];

            if (input.type === 'checkbox') {
                if (input.name.indexOf('][]') > -1 || input.name.slice(-2) === '[]') {
                    out[key] = out[key] || [];
                    if (input.checked) out[key].push(input.value);
                } else {
                    out[key] = input.checked;
                }
                continue;
            }

            // Craft's lightswitch is a hidden input plus a button; the hidden input carries the
            // value, and it is the one the selector above found.
            if (input.type === 'hidden' && out[key] !== undefined) continue;

            out[key] = input.value;
        }

        return out;
    }

    /** The Embed field, on an entry. */
    function initField(root) {
        var urlField = root.querySelector('[data-eye-field-url]');
        var providerBox = root.querySelector('[data-eye-field-provider]');

        if (!urlField) return;

        var resolve = debounce(function () {
            var url = urlField.value.trim();

            if (!url || !providerBox) {
                if (providerBox) providerBox.textContent = '';
                return;
            }

            post('eye/embeds/resolve', { url: url })
                .then(function (response) {
                    providerBox.textContent = response.data.providerName || '';
                })
                .catch(function () {
                    providerBox.textContent = '';
                });
        }, 400);

        urlField.addEventListener('input', resolve);
    }

    function boot(scope) {
        var edit = (scope || document).querySelector('#eye-edit');
        if (edit && !edit.__eyeReady) {
            edit.__eyeReady = true;
            initEditScreen(edit);
        }

        var fields = (scope || document).querySelectorAll('[data-eye-field]');
        for (var i = 0; i < fields.length; i++) {
            if (fields[i].__eyeReady) continue;
            fields[i].__eyeReady = true;
            initField(fields[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            boot();
        });
    } else {
        boot();
    }

    // Matrix blocks and slideouts arrive after the page does.
    if (window.Garnish) {
        Garnish.on(Garnish.Modal, 'show', function () {
            boot();
        });
    }

    document.addEventListener('focusin', function () {
        boot();
    });
})();
