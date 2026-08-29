/**
 * Eye — the embed picker.
 *
 * `EyePicker.open(function (embed) { … })` puts a modal on screen and hands back the chosen
 * embed. Both the CKEditor plugin and the Redactor plugin call this, so the choosing experience
 * is identical wherever an author inserts an embed.
 *
 * Two ways in, because authors arrive with one of two things:
 *
 *   - a URL they just copied, which is turned into a library embed and inserted
 *   - a memory of one they made earlier, which is in the list
 *
 * Both come out the same way: a reference tag. There is one rendering path in Eye and this does
 * not add a second.
 */
(function () {
    'use strict';

    function el(tag, attrs, children) {
        var node = document.createElement(tag);

        Object.keys(attrs || {}).forEach(function (key) {
            if (key === 'text') {
                node.textContent = attrs[key];
            } else if (key === 'class') {
                node.className = attrs[key];
            } else if (attrs[key] !== null && attrs[key] !== false && attrs[key] !== undefined) {
                node.setAttribute(key, attrs[key]);
            }
        });

        (children || []).forEach(function (child) {
            if (child) node.appendChild(child);
        });

        return node;
    }

    function t(message, params) {
        return Craft.t('eye', message, params || {});
    }

    var EyePicker = {
        /**
         * The block both editors insert. Identical markup either way, so content can move
         * between a Redactor field and a CKEditor field with nothing to migrate.
         */
        embedHtml: function (embed) {
            var div = el('div', {
                class: 'eye-embed',
                'data-eye-handle': embed.handle,
                'data-eye-label': embed.title || embed.handle,
            });

            div.textContent = '{eye:' + embed.handle + ':render}';

            return div.outerHTML;
        },

        open: function (onSelect) {
            var body = el('div', { class: 'eye-picker' });
            var modal = null;

            var status = el('p', { class: 'eye-picker__status' });
            var urlInput = el('input', {
                type: 'url',
                class: 'text fullwidth',
                placeholder: 'https://…',
                'aria-label': t('Paste a URL'),
            });

            var urlButton = el('button', { type: 'button', class: 'btn submit', text: t('Embed') });
            var results = el('div', { class: 'eye-picker__results' });

            function fail(message) {
                status.textContent = message;
                status.className = 'eye-picker__status eye-picker__status--error';
            }

            function busy(message) {
                status.textContent = message;
                status.className = 'eye-picker__status';
            }

            function choose(embed) {
                if (modal) modal.hide();
                onSelect(embed);
            }

            /* Paste-a-URL ---------------------------------------------------- */

            function embedUrl() {
                var url = urlInput.value.trim();

                if (!url) {
                    urlInput.focus();
                    return;
                }

                urlButton.classList.add('loading');
                busy(t('Looking at that URL…'));

                Craft.sendActionRequest('POST', 'eye/embeds/quick-create', { data: { url: url } })
                    .then(function (response) {
                        urlButton.classList.remove('loading');
                        choose(response.data);
                    })
                    .catch(function (error) {
                        urlButton.classList.remove('loading');
                        fail((error.response && error.response.data && error.response.data.message) || t('That URL could not be embedded.'));
                    });
            }

            urlButton.addEventListener('click', embedUrl);
            urlInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    embedUrl();
                }
            });

            /* The library ---------------------------------------------------- */

            function renderList(embeds) {
                results.innerHTML = '';

                if (!embeds.length) {
                    results.appendChild(el('p', { class: 'eye-picker__empty', text: t('No embeds in the library yet. Paste a URL above to make one.') }));
                    return;
                }

                embeds.forEach(function (embed) {
                    var row = el('button', { type: 'button', class: 'eye-picker__row' }, [
                        el('span', { class: 'eye-picker__title', text: embed.title }),
                        el('span', { class: 'eye-picker__meta', text: embed.provider + ' · ' + embed.mode }),
                        el('code', { class: 'eye-picker__handle', text: embed.handle }),
                    ]);

                    if (!embed.enabled) {
                        row.classList.add('eye-picker__row--disabled');
                        row.appendChild(el('span', { class: 'eye-picker__badge', text: t('Disabled') }));
                    }

                    row.addEventListener('click', function () {
                        choose(embed);
                    });

                    results.appendChild(row);
                });
            }

            function load(search) {
                busy(t('Loading…'));

                Craft.sendActionRequest('GET', 'eye/embeds/list', { params: { search: search || '' } })
                    .then(function (response) {
                        status.textContent = '';
                        renderList(response.data.embeds || []);
                    })
                    .catch(function () {
                        fail(t('Could not load the embed library.'));
                    });
            }

            var search = el('input', { type: 'text', class: 'text fullwidth', placeholder: t('Search embeds') });
            var searchTimer = null;

            search.addEventListener('input', function () {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(function () {
                    load(search.value);
                }, 250);
            });

            body.appendChild(el('div', { class: 'eye-picker__section' }, [
                el('h2', { text: t('Embed a URL') }),
                el('div', { class: 'eye-picker__url' }, [urlInput, urlButton]),
                el('p', { class: 'eye-picker__hint', text: t('YouTube, Vimeo, Maps, Figma, Calendly and more are recognised automatically. Anything else is framed as-is.') }),
            ]));

            body.appendChild(el('div', { class: 'eye-picker__section' }, [
                el('h2', { text: t('Or choose one you have already made') }),
                search,
                results,
            ]));

            body.appendChild(status);

            modal = new Garnish.Modal(el('div', { class: 'modal elementselectormodal eye-picker-modal' }, [
                el('div', { class: 'body' }, [body]),
                el('div', { class: 'footer' }, [
                    el('div', { class: 'buttons right' }, [
                        (function () {
                            var cancel = el('button', { type: 'button', class: 'btn', text: t('Cancel') });
                            cancel.addEventListener('click', function () {
                                modal.hide();
                            });
                            return cancel;
                        })(),
                    ]),
                ]),
            ]), {
                onHide: function () {
                    window.setTimeout(function () {
                        modal.$container.remove();
                    }, 100);
                },
            });

            load('');
            window.setTimeout(function () {
                urlInput.focus();
            }, 50);
        },
    };

    window.EyePicker = EyePicker;
})();
