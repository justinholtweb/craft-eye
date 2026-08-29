<?php

namespace justinholtweb\eye\web\assets\ckeditor;

use craft\ckeditor\web\assets\BaseCkeditorPackageAsset;
use justinholtweb\eye\web\assets\picker\PickerAsset;

/**
 * The CKEditor package.
 *
 * Registered with `craft\ckeditor\Plugin::registerCkeditorPackage()`, which puts `index.js` in
 * the editor's import map under this namespace and emits
 * `import { EyeEmbed } from '@justinholtweb/ckeditor5-eye'` into the field's own script. The
 * plugin is only pulled in for editors whose toolbar includes the button, so an editor that does
 * not offer embeds pays nothing for it.
 */
class EyeCkeditorAsset extends BaseCkeditorPackageAsset
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        PickerAsset::class,
    ];

    public const NAMESPACE = '@justinholtweb/ckeditor5-eye';

    public const PLUGIN_NAME = 'EyeEmbed';

    public const TOOLBAR_ITEM = 'eyeEmbed';

    public string $namespace = self::NAMESPACE;

    /**
     * Left empty on purpose.
     *
     * The base class would register the plugin and its toolbar item from here — but that happens
     * on `EVENT_AFTER_REGISTER_ASSET_BUNDLE`, which on the field settings screen fires *after*
     * the list of available toolbar items has been computed, so the button never appears for
     * anyone to drag in. {@see \justinholtweb\eye\richtext\CkeditorIntegration} registers both
     * directly at plugin-init time instead, which is early enough for every screen.
     */
    public array $pluginNames = [];

    public array $toolbarItems = [];
}
