<?php

namespace justinholtweb\eye\services;

use Craft;
use craft\base\Component;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\models\ProviderMatch;
use justinholtweb\eye\Plugin;
use Throwable;

/**
 * Which consent manager, if any, unlocks click-to-load embeds.
 *
 * Eye decides nothing about consent itself. A site that already runs a consent manager has asked
 * the reader once; asking again on every video is the thing people hate about embeds. So the
 * front-end runtime listens to the site's manager, loads a card's frame when the embed's category
 * is granted, and puts the card back when it is withdrawn.
 *
 * Toss is the family's consent manager and is read through its published contract
 * (`docs/consent-api.md` in craft-toss): when it is installed with its consent kit on, Eye
 * defers to it. Without Toss, the runtime listens for Cookiebot, CookieYes, Klaro or Google
 * Consent Mode, and `window.Eye.setConsent()` covers anything else.
 *
 * Everything here is the same for every visitor — which manager is in charge is a fact about the
 * site, not the reader — so it is safe to render into a statically cached page. The reader's
 * answer is only ever read in the browser.
 */
class Consent extends Component
{
    /** Toss when it is in charge, otherwise whichever manager the page turns out to have. */
    public const MANAGER_AUTO = 'auto';

    public const MANAGER_TOSS = 'toss';
    public const MANAGER_COOKIEBOT = 'cookiebot';
    public const MANAGER_COOKIEYES = 'cookieyes';
    public const MANAGER_KLARO = 'klaro';
    public const MANAGER_CONSENT_MODE = 'consentmode';

    /** No manager: every card waits for its own click, or for `Eye.setConsent()`. */
    public const MANAGER_NONE = 'none';

    public const MANAGERS = [
        self::MANAGER_AUTO,
        self::MANAGER_TOSS,
        self::MANAGER_COOKIEBOT,
        self::MANAGER_COOKIEYES,
        self::MANAGER_KLARO,
        self::MANAGER_CONSENT_MODE,
        self::MANAGER_NONE,
    ];

    /** The category an embed waits for when neither it nor its provider names one. */
    public const DEFAULT_CATEGORY = 'marketing';

    private ?bool $tossActive = null;

    /**
     * Whether Toss is installed, enabled and its consent kit is on — the contract's test for
     * "is Toss the consent manager".
     *
     * Toss's classes are never named here: on a site without Toss they do not exist, and a
     * reference to one would fatal. The plugin is reached through Craft's plugin registry and
     * its `consent` component through the service locator, and anything unexpected reads as
     * "not active", which keeps Eye's own behaviour.
     */
    public function tossIsActive(): bool
    {
        if ($this->tossActive !== null) {
            return $this->tossActive;
        }

        try {
            $plugins = Craft::$app->getPlugins();
            $toss = $plugins->isPluginEnabled('toss') ? $plugins->getPlugin('toss') : null;
            $consent = $toss !== null && $toss->has('consent') ? $toss->get('consent') : null;

            return $this->tossActive = is_object($consent)
                && method_exists($consent, 'isActive')
                && $consent->isActive() === true;
        } catch (Throwable $e) {
            Craft::warning('Eye could not ask Toss whether its consent kit is on: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return $this->tossActive = false;
        }
    }

    /**
     * The manager the runtime should listen to, with `auto` resolved as far as the server can
     * resolve it: to `toss` when Toss is in charge. Otherwise `auto` stays `auto`, and the
     * runtime works out which manager the page actually has.
     */
    public function manager(): string
    {
        $setting = Plugin::getInstance()->getSettings()->consentManager;

        if (!in_array($setting, self::MANAGERS, true)) {
            $setting = self::MANAGER_AUTO;
        }

        if ($setting === self::MANAGER_AUTO && $this->tossIsActive()) {
            return self::MANAGER_TOSS;
        }

        return $setting;
    }

    /** The category that unlocks an embed: its own, else its provider's, else `marketing`. */
    public function categoryFor(EmbedOptions $options, ?ProviderMatch $match): string
    {
        foreach ([$options->consentCategory, $match?->provider->consentCategory] as $category) {
            if (is_string($category) && in_array($category, EmbedOptions::CONSENT_CATEGORIES, true)) {
                return $category;
            }
        }

        return self::DEFAULT_CATEGORY;
    }

    /** @return array<string, string> For a settings `<select>`. */
    public function managerOptions(): array
    {
        return [
            self::MANAGER_AUTO => Craft::t('eye', 'Automatic — Toss if it is on, otherwise whichever the page has'),
            self::MANAGER_TOSS => 'Toss',
            self::MANAGER_COOKIEBOT => 'Cookiebot',
            self::MANAGER_COOKIEYES => 'CookieYes',
            self::MANAGER_KLARO => 'Klaro',
            self::MANAGER_CONSENT_MODE => Craft::t('eye', 'Google Consent Mode'),
            self::MANAGER_NONE => Craft::t('eye', 'None — every embed waits for its own click'),
        ];
    }

    /** @return array<string, string> For an embed's category `<select>`. */
    public function categoryOptions(): array
    {
        return [
            'marketing' => Craft::t('eye', 'Marketing'),
            'analytics' => Craft::t('eye', 'Analytics (statistics)'),
            'preferences' => Craft::t('eye', 'Preferences (functional)'),
            'necessary' => Craft::t('eye', 'Necessary — loads as soon as a consent manager is present'),
        ];
    }
}
