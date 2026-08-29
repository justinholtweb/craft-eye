<?php

namespace justinholtweb\eye\web\assets\runtime;

use craft\web\AssetBundle;
use justinholtweb\eye\Plugin;

/**
 * The front-end stylesheet and runtime.
 *
 * Registered by {@see \justinholtweb\eye\services\Renderer} the first time an embed renders, and
 * not at all on a page that has none. Either half can be turned off in the plugin settings for a
 * site that would rather ship its own.
 */
class RuntimeAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [];

    public function init(): void
    {
        $settings = Plugin::getInstance()->getSettings();

        $this->css = $settings->registerCss ? ['eye.css'] : [];

        // Deferred: nothing here needs to run before the page is parsed, and an embed that has
        // not been enhanced yet still shows its frame.
        $this->js = $settings->registerJs ? ['eye.js'] : [];
        $this->jsOptions = ['defer' => true];

        parent::init();
    }
}
