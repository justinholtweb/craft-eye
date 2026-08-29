/**
 * Eye — the CKEditor plugin.
 *
 * What it stores is a plain block:
 *
 *     <div class="eye-embed" data-eye-handle="promo-video">{eye:promo-video:render}</div>
 *
 * That is the whole integration. The reference tag inside is what renders the embed, and Craft
 * parses reference tags over every rich-text value on its own — so this file only helps an author
 * write that tag, and shows something better than raw braces while they edit. If the plugin is
 * missing, or the content moves to a Redactor field, or the editor is swapped out entirely, the
 * embed still renders.
 *
 * It also handles the thing authors actually do, which is paste a URL: a YouTube link dropped on
 * its own line becomes an embed without anyone opening a dialog.
 */

import { ButtonView, Command, Plugin, Widget, toWidget } from 'ckeditor5';

const ICON = `<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
    <path d="M10 4c-3.6 0-6.7 2.1-8.2 5 1.5 2.9 4.6 5 8.2 5s6.7-2.1 8.2-5c-1.5-2.9-4.6-5-8.2-5zm0 8.3A3.3 3.3 0 1 1 10 5.7a3.3 3.3 0 0 1 0 6.6zm0-1.6a1.7 1.7 0 1 0 0-3.4 1.7 1.7 0 0 0 0 3.4z"/>
</svg>`;

/** Recognised well enough to be worth converting on paste, without asking the server first. */
const URL_PATTERN = /^https?:\/\/\S+$/i;

class InsertEyeEmbedCommand extends Command {
    refresh() {
        const model = this.editor.model;

        this.isEnabled = model.schema.findAllowedParent(
            model.document.selection.getFirstPosition(),
            'eyeEmbed',
        ) !== null;
    }

    execute(options) {
        const editor = this.editor;

        editor.model.change((writer) => {
            const embed = writer.createElement('eyeEmbed', {
                handle: options.handle,
                label: options.label || options.handle,
                options: options.options || '',
            });

            editor.model.insertObject(embed, null, null, { setSelection: 'after' });
        });
    }
}

class EyeEmbedEditing extends Plugin {
    static get requires() {
        return [Widget];
    }

    static get pluginName() {
        return 'EyeEmbedEditing';
    }

    init() {
        const editor = this.editor;

        editor.model.schema.register('eyeEmbed', {
            inheritAllFrom: '$blockObject',
            allowAttributes: ['handle', 'label', 'options'],
        });

        editor.conversion.for('upcast').elementToElement({
            view: {
                name: 'div',
                classes: 'eye-embed',
            },
            model: (viewElement, { writer }) => writer.createElement('eyeEmbed', {
                handle: viewElement.getAttribute('data-eye-handle') || '',
                label: viewElement.getAttribute('data-eye-label')
                    || viewElement.getAttribute('data-eye-handle')
                    || '',
                options: viewElement.getAttribute('data-eye-options') || '',
            }),
        });

        editor.conversion.for('dataDowncast').elementToElement({
            model: 'eyeEmbed',
            view: (modelElement, { writer }) => {
                const handle = modelElement.getAttribute('handle') || '';
                const label = modelElement.getAttribute('label') || '';
                const options = modelElement.getAttribute('options') || '';
                const attributes = { class: 'eye-embed', 'data-eye-handle': handle };

                if (label) attributes['data-eye-label'] = label;
                if (options) attributes['data-eye-options'] = options;

                // A raw element, so the reference tag is written as plain text rather than as
                // model content CKEditor would then try to own.
                return writer.createRawElement('div', attributes, (domElement) => {
                    domElement.textContent = options
                        ? `{eye:${handle}:render(${options})}`
                        : `{eye:${handle}:render}`;
                });
            },
        });

        editor.conversion.for('editingDowncast').elementToElement({
            model: 'eyeEmbed',
            view: (modelElement, { writer }) => {
                const handle = modelElement.getAttribute('handle') || '';
                const label = modelElement.getAttribute('label') || handle;
                const options = modelElement.getAttribute('options') || '';

                const container = writer.createContainerElement('div', { class: 'eye-embed' }, [
                    writer.createRawElement('div', { class: 'eye-embed-card' }, (domElement) => {
                        domElement.textContent = options
                            ? `${label} (${handle} · ${options})`
                            : `${label} (${handle})`;
                    }),
                ]);

                return toWidget(container, writer, { label: `Eye embed: ${label}` });
            },
        });

        editor.commands.add('insertEyeEmbed', new InsertEyeEmbedCommand(editor));
    }
}

/**
 * Paste a URL on its own, get an embed.
 *
 * Only when the pasted text is a bare URL and nothing else — pasting a paragraph that happens to
 * contain a link should stay a link, and pasting into the middle of a sentence should stay text.
 */
class EyeEmbedAutoPaste extends Plugin {
    static get pluginName() {
        return 'EyeEmbedAutoPaste';
    }

    init() {
        const editor = this.editor;

        editor.plugins.get('ClipboardPipeline').on('inputTransformation', (event, data) => {
            const text = (data.dataTransfer.getData('text/plain') || '').trim();

            if (!URL_PATTERN.test(text) || !editor.commands.get('insertEyeEmbed').isEnabled) return;

            // Only on an empty line: replacing a selection with an embed is not what anyone
            // meant by pasting over it.
            const selection = editor.model.document.selection;
            const block = selection.getFirstPosition().parent;

            if (!selection.isCollapsed || (block.childCount || 0) > 0) return;

            event.stop();

            Craft.sendActionRequest('POST', 'eye/embeds/quick-create', { data: { url: text } })
                .then((response) => {
                    editor.execute('insertEyeEmbed', {
                        handle: response.data.handle,
                        label: response.data.title,
                    });
                })
                .catch(() => {
                    // Fall back to what a paste normally does, so the URL is never simply lost.
                    editor.model.change((writer) => {
                        editor.model.insertContent(writer.createText(text));
                    });
                });
        }, { priority: 'high' });
    }
}

export class EyeEmbed extends Plugin {
    static get requires() {
        return [EyeEmbedEditing, EyeEmbedAutoPaste];
    }

    static get pluginName() {
        return 'EyeEmbed';
    }

    init() {
        const editor = this.editor;

        editor.ui.componentFactory.add('eyeEmbed', (locale) => {
            const button = new ButtonView(locale);
            const command = editor.commands.get('insertEyeEmbed');

            button.set({
                label: Craft.t('eye', 'Embed'),
                icon: ICON,
                tooltip: true,
            });

            button.bind('isEnabled').to(command, 'isEnabled');

            button.on('execute', () => {
                window.EyePicker.open((embed) => {
                    editor.execute('insertEyeEmbed', {
                        handle: embed.handle,
                        label: embed.title,
                    });
                    editor.editing.view.focus();
                });
            });

            return button;
        });
    }
}

export default EyeEmbed;
