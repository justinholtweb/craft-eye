<?php

namespace justinholtweb\eye\web\assets\picker;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The embed picker, shared by every editor integration.
 *
 * One picker rather than one per editor: the choice an author is making — which embed — is the
 * same whatever is doing the asking, and a second implementation would drift from the first.
 */
class PickerAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = ['eye-picker.js'];

    public $css = ['eye-picker.css'];
}
