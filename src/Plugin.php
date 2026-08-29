<?php

namespace justinholtweb\eye;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\eye\elements\Embed;
use justinholtweb\eye\fields\EmbedField;
use justinholtweb\eye\fields\EmbedsField;
use justinholtweb\eye\models\Settings;
use justinholtweb\eye\services\Embeds;
use justinholtweb\eye\services\Fetcher;
use justinholtweb\eye\services\Framability;
use justinholtweb\eye\services\Providers;
use justinholtweb\eye\services\Proxy;
use justinholtweb\eye\services\ProxyRoutes;
use justinholtweb\eye\services\Renderer;
use justinholtweb\eye\twig\Extension;
use justinholtweb\eye\twig\EyeVariable;
use yii\base\Event;

/**
 * Eye — iframes that behave.
 *
 * Four things go wrong with an iframe in a CMS, and this plugin exists for all four: it is the
 * wrong size, it loads before anyone asked, it hands the reader to a third party without
 * telling them, and sometimes the other site refuses to be framed at all.
 *
 * @property-read Embeds $embeds
 * @property-read Providers $providers
 * @property-read Renderer $renderer
 * @property-read Fetcher $fetcher
 * @property-read Framability $framability
 * @property-read Proxy $proxy
 * @property-read ProxyRoutes $proxyRoutes
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_VIEW = 'eye:viewEmbeds';
    public const PERMISSION_MANAGE = 'eye:manageEmbeds';
    public const PERMISSION_DELETE = 'eye:deleteEmbeds';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'eye';

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'embeds' => Embeds::class,
                'providers' => Providers::class,
                'renderer' => Renderer::class,
                'fetcher' => Fetcher::class,
                'framability' => Framability::class,
                'proxy' => Proxy::class,
                'proxyRoutes' => ProxyRoutes::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerElementTypes();
        $this->registerFieldTypes();
        $this->registerRoutes();
        $this->registerPermissions();
        $this->registerTwig();
        $this->registerRichTextIntegrations();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('eye', 'Eye');

        $item['subnav'] = [
            'embeds' => [
                'label' => Craft::t('eye', 'Embeds'),
                'url' => 'eye/embeds',
            ],
        ];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('eye', 'Settings'),
                'url' => 'settings/plugins/eye',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('eye/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
            'purifierConfigs' => $this->purifierConfigOptions(),
            'childScriptUrl' => \craft\helpers\UrlHelper::siteUrl('eye/child.js'),
        ]);
    }

    /** @return array<string, string> */
    public function purifierConfigOptions(): array
    {
        $options = ['' => Craft::t('eye', 'Default')];
        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'htmlpurifier';

        if (is_dir($path)) {
            foreach (\craft\helpers\FileHelper::findFiles($path, ['only' => ['*.json'], 'recursive' => false]) as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $options[$name] = $name;
            }
        }

        return $options;
    }

    private function registerElementTypes(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Embed::class;
        });
    }

    private function registerFieldTypes(): void
    {
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = EmbedField::class;
            $event->types[] = EmbedsField::class;
        });
    }

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'eye' => 'eye/embeds/index',
                'eye/embeds' => 'eye/embeds/index',
                'eye/embeds/new' => 'eye/embeds/edit',
                'eye/embeds/<embedId:\d+>' => 'eye/embeds/edit',
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                // The uid form is the safe one: the URL to fetch is read from the database, not
                // from the request. The signed form carries a payload nobody but this site could
                // have produced. There is no form that takes a URL.
                'eye/proxy/<uid:[\w\-]+>' => 'eye/proxy/render',
                'eye/proxy' => 'eye/proxy/render',

                // A stable URL for the child script, because it is meant to be pasted into a
                // `<script src>` on somebody else's site, and a published `/cpresources/…` path
                // moves on every deploy.
                'eye/child.js' => 'eye/proxy/child',
            ];
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('eye', 'Eye'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('eye', 'View embeds'),
                        'nested' => [
                            self::PERMISSION_MANAGE => [
                                'label' => Craft::t('eye', 'Create and edit embeds'),
                            ],
                            self::PERMISSION_DELETE => [
                                'label' => Craft::t('eye', 'Delete embeds'),
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('eye', EyeVariable::class);
        });

        Craft::$app->getView()->registerTwigExtension(new Extension());
    }

    /**
     * Editing affordances only.
     *
     * Rendering inside rich text is done by Craft's own reference-tag parsing — see
     * {@see Embed::getRender()} — so these integrations exist purely to help an author write the
     * tag without typing it, and their absence costs nothing but convenience.
     */
    private function registerRichTextIntegrations(): void
    {
        // Purification happens on save, which can be a console request (a resave, a migration, an
        // Element API write), so this one is not CP-only.
        (new richtext\PurifierSupport())->register();

        if ($this->getSettings()->autoembed) {
            (new richtext\Autoembed())->register();
        }

        if (!Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        (new richtext\CkeditorIntegration())->register();
        (new richtext\RedactorIntegration())->register();
    }
}
