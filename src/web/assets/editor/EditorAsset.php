<?php

namespace justinholtweb\eye\web\assets\editor;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use justinholtweb\eye\web\assets\runtime\RuntimeAsset;

/**
 * The control panel side: the embed edit screen and the Embed field.
 *
 * Depends on the front-end runtime so that the preview on the edit screen behaves exactly like
 * the front end — a preview that quietly skipped click-to-load or auto height would be a preview
 * of something else.
 */
class EditorAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
        RuntimeAsset::class,
    ];

    public $js = ['eye-editor.js'];

    public $css = ['eye-editor.css'];
}
