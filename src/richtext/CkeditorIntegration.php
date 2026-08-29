<?php

namespace justinholtweb\eye\richtext;

use Craft;
use craft\ckeditor\helpers\CkeditorConfig;
use craft\ckeditor\Plugin as CkeditorPlugin;
use justinholtweb\eye\web\assets\ckeditor\EyeCkeditorAsset;

/**
 * Teaches CKEditor how to insert an Eye embed.
 *
 * Editing only. Rendering is Craft's reference-tag parsing, which knows nothing about CKEditor —
 * so this integration can be absent, broken or disabled and existing embeds keep working.
 */
class CkeditorIntegration
{
    public function register(): void
    {
        // `CkeditorConfig` arrived with the ESM/import-map rewrite of the CKEditor plugin. On the
        // older DLL-based versions the package format differs enough that half-registering would
        // break the editor rather than merely lack a button — so on those, do nothing.
        if (!class_exists(CkeditorPlugin::class) || !class_exists(CkeditorConfig::class)) {
            return;
        }

        CkeditorPlugin::registerCkeditorPackage(EyeCkeditorAsset::class, 'index.js');

        CkeditorConfig::registerPackage(EyeCkeditorAsset::NAMESPACE, [
            'plugins' => [EyeCkeditorAsset::PLUGIN_NAME],
            'toolbarItems' => [EyeCkeditorAsset::TOOLBAR_ITEM],
        ]);

        // …and add the import-map entry ourselves.
        //
        // The CKEditor plugin adds one for every registered package, but it does so in its own
        // `init()` — and plugins initialise in handle order, so `ckeditor` has already been and
        // gone by the time `eye` registers anything. Without this the editor loads a script that
        // imports a bare specifier nothing has mapped, and the *whole field* fails to boot, not
        // just our button.
        $view = Craft::$app->getView();
        $assetManager = $view->getAssetManager();
        $bundle = $assetManager->getBundle(EyeCkeditorAsset::class);

        if ($bundle instanceof EyeCkeditorAsset) {
            // With a timestamp, deliberately. A published directory's hash comes from its path
            // and its *directory* mtime, and editing a file inside it does not change that — so
            // without `?v=`, the module URL stays identical while its contents change, and every
            // browser that has already imported it keeps running the old copy.
            $view->registerJsImport(EyeCkeditorAsset::NAMESPACE, $assetManager->getAssetUrl($bundle, 'index.js', true));
        }
    }
}
