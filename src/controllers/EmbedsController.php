<?php

namespace justinholtweb\eye\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\eye\elements\Embed;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\Plugin;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The control panel side of the embed library.
 */
class EmbedsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('eye/embeds/_index', [
            'title' => Craft::t('eye', 'Embeds'),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionEdit(?int $embedId = null, ?Embed $embed = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($embed === null) {
            if ($embedId !== null) {
                $embed = $plugin->embeds->getEmbedById($embedId);

                if (!$embed) {
                    throw new NotFoundHttpException(Craft::t('eye', 'Embed not found.'));
                }
            } else {
                $embed = new Embed();
                $embed->setOptions($plugin->getSettings()->getDefaultEmbedOptions());

                // "New from URL": the index's paste field posts here, so the form opens already
                // filled in with whatever the provider registry made of it.
                $url = Craft::$app->getRequest()->getQueryParam('url');

                if ($url) {
                    $embed = $plugin->embeds->createFromUrl((string)$url);
                }
            }
        }

        $settings = $plugin->getSettings();

        return $this->renderTemplate('eye/embeds/_edit', [
            'embed' => $embed,
            'options' => $embed->getOptions(),
            'isNew' => !$embed->id,
            'title' => $embed->id ? $embed->getUiLabel() : Craft::t('eye', 'New embed'),
            'providers' => $plugin->providers->getAll(),
            'match' => $embed->url ? $embed->getProviderMatch() : null,
            'settings' => $settings,
            'proxyUsable' => $settings->getProxyIsUsable(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
            'sandboxTokens' => EmbedOptions::SANDBOX_TOKENS,
            'allowFeatures' => EmbedOptions::ALLOW_FEATURES,
            'childScriptUrl' => UrlHelper::siteUrl('eye/child.js'),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $embedId = $request->getBodyParam('embedId');

        if ($embedId) {
            $embed = $plugin->embeds->getEmbedById((int)$embedId);

            if (!$embed) {
                throw new NotFoundHttpException(Craft::t('eye', 'Embed not found.'));
            }
        } else {
            $embed = new Embed();
        }

        $embed->title = $request->getBodyParam('title', $embed->title);
        $embed->handle = $request->getBodyParam('handle', $embed->handle) ?: null;
        $embed->url = $request->getBodyParam('url', $embed->url);
        $embed->enabled = (bool)$request->getBodyParam('enabled', $embed->enabled);

        $posted = $request->getBodyParam('options', []);
        $embed->setOptions($embed->getOptions()->merge(is_array($posted) ? $this->normalizePostedOptions($posted) : []));

        if (!$plugin->embeds->saveEmbed($embed)) {
            return $this->asModelFailure($embed, Craft::t('eye', 'Couldn’t save embed.'), 'embed');
        }

        // A saved embed is worth one probe: this is the moment the author is looking at the
        // screen, and the moment a "this site refuses to be framed" answer is most useful.
        if ($plugin->getSettings()->checkFramability && !$embed->getOptions()->getIsFetched()) {
            try {
                $plugin->embeds->checkFramability($embed, true);
            } catch (Throwable $e) {
                Craft::warning('Framability check failed after save: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            }
        }

        return $this->asModelSuccess($embed, Craft::t('eye', 'Embed saved.'), 'embed', [
            'id' => $embed->id,
            'handle' => $embed->handle,
            'embedCode' => $embed->getEmbedCode(),
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DELETE);

        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('embedId');
        $embed = Plugin::getInstance()->embeds->getEmbedById($id);

        if (!$embed) {
            throw new NotFoundHttpException(Craft::t('eye', 'Embed not found.'));
        }

        if (!Plugin::getInstance()->embeds->deleteEmbed($embed)) {
            return $this->asFailure(Craft::t('eye', 'Couldn’t delete embed.'));
        }

        return $this->asSuccess(Craft::t('eye', 'Embed deleted.'), redirect: UrlHelper::cpUrl('eye/embeds'));
    }

    /**
     * Ask a URL whether it will let this site frame it.
     *
     * Never cached — this action only ever runs because a human pressed a button that says
     * "check again".
     */
    public function actionCheck(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $url = (string)$request->getRequiredBodyParam('url');
        $embedId = $request->getBodyParam('embedId');
        $plugin = Plugin::getInstance();

        try {
            if ($embedId && ($embed = $plugin->embeds->getEmbedById((int)$embedId))) {
                $result = $plugin->embeds->checkFramability($embed, false);
            } else {
                $plugin->framability->forget($url);
                $result = $plugin->framability->check($url, false);
            }
        } catch (Throwable $e) {
            return $this->asJson([
                'status' => 'error',
                'label' => Craft::t('eye', 'Unreachable'),
                'color' => 'red',
                'message' => $e->getMessage(),
                'suggestion' => '',
            ]);
        }

        return $this->asJson([
            'status' => $result->status,
            'label' => $result->getStatusLabel(),
            'color' => $result->getStatusColor(),
            'message' => $result->message,
            'header' => $result->header,
            'suggestion' => $result->getSuggestion(),
        ]);
    }

    /**
     * What Eye makes of a URL — the endpoint behind "paste a URL and watch the form fill in",
     * in the edit screen and in both editor plugins.
     */
    public function actionResolve(): Response
    {
        $this->requireAcceptsJson();

        $url = trim((string)Craft::$app->getRequest()->getRequiredBodyParam('url'));
        $match = Plugin::getInstance()->providers->match($url);

        if (!$match) {
            return $this->asJson(['recognised' => false]);
        }

        return $this->asJson([
            'recognised' => !$match->provider->getIsGeneric(),
            'provider' => $match->provider->handle,
            'providerName' => $match->provider->name,
            'embedUrl' => $match->embedUrl,
            'posterUrl' => $match->posterUrl,
            'privacy' => $match->provider->hasPrivacyVariant,
            'defaults' => $match->getOptionDefaults(),
        ]);
    }

    /**
     * Turn a pasted URL into a library embed, and hand back the reference tag for it.
     *
     * This is what makes "paste a URL into rich text" work without inventing a second rendering
     * path: rich text renders reference tags, reference tags need an element, so a pasted URL
     * becomes one. The side effect is the point — every third-party frame on the site ends up in
     * one index, where it can be audited, switched off, or moved behind a consent card.
     *
     * Existing embeds with the same URL are reused, so pasting the same video into ten entries
     * creates one library entry, not ten.
     */
    public function actionQuickCreate(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = Craft::$app->getRequest();
        $url = trim((string)$request->getRequiredBodyParam('url'));
        $plugin = Plugin::getInstance();

        if (!preg_match('~^https?://~i', $url)) {
            return $this->asFailure(Craft::t('eye', 'That is not a URL Eye can embed.'));
        }

        /** @var Embed|null $existing */
        $existing = Embed::find()->url($url)->status(null)->one();

        if ($existing) {
            return $this->asJson([
                'success' => true,
                'reused' => true,
                'id' => $existing->id,
                'handle' => $existing->handle,
                'title' => $existing->getUiLabel(),
                'embedCode' => $existing->getEmbedCode(),
                'cpEditUrl' => $existing->getCpEditUrl(),
            ]);
        }

        $overrides = $request->getBodyParam('options', []);
        $embed = $plugin->embeds->createFromUrl($url, is_array($overrides) ? $overrides : []);

        if (!$plugin->embeds->saveEmbed($embed)) {
            return $this->asModelFailure($embed, Craft::t('eye', 'Couldn’t create embed.'), 'embed');
        }

        return $this->asJson([
            'success' => true,
            'reused' => false,
            'id' => $embed->id,
            'handle' => $embed->handle,
            'title' => $embed->getUiLabel(),
            'embedCode' => $embed->getEmbedCode(),
            'cpEditUrl' => $embed->getCpEditUrl(),
        ]);
    }

    /** The editor picker's list. */
    public function actionList(): Response
    {
        $this->requireAcceptsJson();

        $search = trim((string)Craft::$app->getRequest()->getParam('search', ''));
        $query = Embed::find()->status(null)->orderBy(['elements_sites.title' => SORT_ASC])->limit(100);

        if ($search !== '') {
            $query->search($search);
        }

        $embeds = [];

        /** @var Embed $embed */
        foreach ($query->all() as $embed) {
            $embeds[] = [
                'id' => $embed->id,
                'handle' => $embed->handle,
                'title' => $embed->getUiLabel(),
                'url' => $embed->url,
                'provider' => $embed->getProviderName(),
                'mode' => $embed->mode,
                'enabled' => $embed->enabled,
                'embedCode' => $embed->getEmbedCode(),
            ];
        }

        return $this->asJson(['embeds' => $embeds]);
    }

    /** The live preview on the edit screen: the real renderer, in a real site-mode template. */
    public function actionPreview(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $url = trim((string)$request->getBodyParam('url', ''));

        if ($url === '') {
            return $this->asJson(['html' => '']);
        }

        $posted = $request->getBodyParam('options', []);
        $options = $plugin->getSettings()->getDefaultEmbedOptions()
            ->merge(is_array($posted) ? $this->normalizePostedOptions($posted) : []);

        try {
            $html = (string)$plugin->renderer->renderUrl($url, $options, ['id' => 'eye-preview']);
        } catch (Throwable $e) {
            return $this->asJson(['html' => '', 'error' => $e->getMessage()]);
        }

        return $this->asJson(['html' => $html]);
    }

    /**
     * A posted form speaks in strings and missing checkboxes; {@see EmbedOptions} speaks in
     * types. This is the one place that translates.
     *
     * @param array<string, mixed> $posted
     * @return array<string, mixed>
     */
    private function normalizePostedOptions(array $posted): array
    {
        // An unchecked checkbox posts nothing, so every boolean has to be stated explicitly or
        // turning one off would read as "no opinion" and leave it on.
        foreach (['showLoader', 'showFallbackLink', 'rememberConsent', 'allowFullscreen', 'scrollToTop'] as $key) {
            $posted[$key] = !empty($posted[$key]);
        }

        // Craft's checkbox groups post an empty string alongside the real values.
        foreach (['sandbox', 'allow'] as $key) {
            if (isset($posted[$key]) && is_array($posted[$key])) {
                $posted[$key] = array_values(array_filter($posted[$key], fn($v) => $v !== '' && $v !== null));
            }
        }

        // A blank sandbox field means "no sandbox attribute", which is not the same as an empty
        // sandbox — an empty one is the most restrictive setting there is.
        if (($posted['sandboxEnabled'] ?? '0') === '0') {
            $posted['sandbox'] = null;
        }

        unset($posted['sandboxEnabled']);

        if (($posted['stripScripts'] ?? '') === '') {
            $posted['stripScripts'] = null;
        }

        return $posted;
    }
}
