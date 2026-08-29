/**
 * Eye — the Redactor plugin.
 *
 * Inserts exactly the same block the CKEditor plugin does:
 *
 *     <div class="eye-embed" data-eye-handle="promo-video">{eye:promo-video:render}</div>
 *
 * so content can move between a Redactor field and a CKEditor field with nothing to migrate, and
 * either way it is Craft's own reference-tag parsing that renders the embed.
 */
(function ($R) {
    'use strict';

    $R.add('plugin', 'eye', {
        translations: {
            en: {
                eye: {
                    title: 'Insert an embed',
                },
            },
        },

        init: function (app) {
            this.app = app;
            this.toolbar = app.toolbar;
            this.insertion = app.insertion;
            this.lang = app.lang;
        },

        start: function () {
            var button = this.toolbar.addButton('eye', {
                title: this.lang.get('eye.title'),
                api: 'plugin.eye.open',
            });

            button.setIcon('<i class="re-icon-video"></i>');
        },

        open: function () {
            var insertion = this.insertion;

            window.EyePicker.open(function (embed) {
                insertion.insertHtml(window.EyePicker.embedHtml(embed));
            });
        },
    });
})(Redactor);
