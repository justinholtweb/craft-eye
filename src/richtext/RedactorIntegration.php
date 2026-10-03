<?php

namespace justinholtweb\eye\richtext;

use Craft;
use craft\redactor\events\ModifyRedactorConfigEvent;
use craft\redactor\events\RegisterPluginPathsEvent;
use craft\redactor\Field as RedactorField;
use justinholtweb\eye\web\assets\picker\PickerAsset;
use yii\base\Event;

/**
 * Teaches Redactor how to insert an Eye embed.
 *
 * Two hooks: one to tell Redactor where the plugin lives, one to switch it on for every field
 * whose config does not already mention it. Sites that curate their Redactor config by hand keep
 * control — an explicit `plugins` list that omits `eye` is left alone.
 */
class RedactorIntegration
{
    public function register(): void
    {
        if (!class_exists(RedactorField::class)) {
            return;
        }

        Event::on(RedactorField::class, RedactorField::EVENT_REGISTER_PLUGIN_PATHS, function(RegisterPluginPathsEvent $event) {
            $event->paths[] = Craft::getAlias('@justinholtweb/eye/web/assets/redactor/dist');
        });

        Event::on(RedactorField::class, RedactorField::EVENT_DEFINE_REDACTOR_CONFIG, function(ModifyRedactorConfigEvent $event) {
            $config = $event->config;

            // A config that names its own plugins has been curated by hand, and adding to it
            // would be overruling that. Whoever wrote it adds `eye` to the list themselves.
            if (!isset($config['plugins'])) {
                $config['plugins'] = ['eye'];
                $event->config = $config;
            }

            // The picker is a CP asset, not a Redactor one, so it has to be registered here
            // rather than left to the plugin's own JS file.
            Craft::$app->getView()->registerAssetBundle(PickerAsset::class);
        });
    }
}
