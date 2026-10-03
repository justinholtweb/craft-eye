<?php

namespace justinholtweb\eye\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\eye\elements\Embed;
use justinholtweb\eye\models\EmbedOptions;
use justinholtweb\eye\models\FramabilityResult;
use justinholtweb\eye\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * Eye from the command line.
 *
 * The health check is the one that earns its keep: framing headers change without warning, and a
 * site that has been quietly refusing to be embedded for three weeks is not something anyone
 * notices by looking at the control panel.
 */
class EmbedsController extends Controller
{
    public $defaultAction = 'list';

    /** Re-check every embed regardless of when it was last looked at. */
    public bool $force = false;

    /** For `check`: exit non-zero if any embed has a problem. For CI. */
    public bool $failOnProblem = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'check') {
            $options[] = 'force';
            $options[] = 'failOnProblem';
        }

        return $options;
    }

    /**
     * List every embed in the library.
     */
    public function actionList(): int
    {
        /** @var Embed[] $embeds */
        $embeds = Embed::find()->status(null)->orderBy(['eye_embeds.handle' => SORT_ASC])->all();

        if (!$embeds) {
            $this->stdout("No embeds yet.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-28s %-14s %-8s %-14s %s\n", 'HANDLE', 'PROVIDER', 'MODE', 'FRAMING', 'URL'), Console::BOLD);

        foreach ($embeds as $embed) {
            $framability = $embed->getFramability();

            $this->stdout(sprintf(
                "%-28s %-14s %-8s ",
                $this->truncate((string)$embed->handle, 28),
                $this->truncate($embed->provider, 14),
                $embed->mode,
            ));

            $this->stdout(sprintf('%-14s', $framability->status), match ($framability->status) {
                FramabilityResult::STATUS_ALLOWED => Console::FG_GREEN,
                FramabilityResult::STATUS_DENIED, FramabilityResult::STATUS_ERROR => Console::FG_RED,
                FramabilityResult::STATUS_RESTRICTED => Console::FG_YELLOW,
                default => Console::FG_GREY,
            });

            $this->stdout($this->truncate((string)$embed->url, 60) . ($embed->enabled ? '' : '  (disabled)') . "\n");
        }

        $this->stdout(sprintf("\n%d embeds.\n", count($embeds)));

        return ExitCode::OK;
    }

    /**
     * Ask every embed's URL whether it still lets this site frame it.
     *
     * Proxied and inlined embeds are skipped: they are never framed by the browser, so a framing
     * header says nothing about whether they work.
     */
    public function actionCheck(): int
    {
        $plugin = Plugin::getInstance();
        $maxAge = $this->force ? 0 : 86400;

        $counts = $plugin->embeds->checkAll($maxAge, function(Embed $embed, FramabilityResult $result) {
            $this->stdout(sprintf('%-30s ', $this->truncate((string)$embed->handle, 30)));
            $this->stdout($result->getStatusLabel(), match ($result->status) {
                FramabilityResult::STATUS_ALLOWED => Console::FG_GREEN,
                FramabilityResult::STATUS_DENIED, FramabilityResult::STATUS_ERROR => Console::FG_RED,
                FramabilityResult::STATUS_RESTRICTED => Console::FG_YELLOW,
                default => Console::FG_GREY,
            });
            $this->stdout($result->message !== '' ? "  $result->message\n" : "\n", Console::FG_GREY);
        });

        if (!$counts) {
            $this->stdout("Nothing to check.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout("\n");

        foreach ($counts as $status => $count) {
            $this->stdout("  $status: $count\n");
        }

        $problems = ($counts[FramabilityResult::STATUS_DENIED] ?? 0)
            + ($counts[FramabilityResult::STATUS_RESTRICTED] ?? 0)
            + ($counts[FramabilityResult::STATUS_ERROR] ?? 0);

        if ($problems > 0) {
            $this->stdout("\n$problems embed(s) will not appear for readers.\n", Console::FG_RED);

            if ($this->failOnProblem) {
                return ExitCode::SOFTWARE;
            }
        }

        return ExitCode::OK;
    }

    /**
     * Create an embed from a URL.
     *
     * @param string $url The URL to embed.
     */
    public function actionCreate(string $url): int
    {
        $plugin = Plugin::getInstance();
        $embed = $plugin->embeds->createFromUrl($url);

        if (!$plugin->embeds->saveEmbed($embed)) {
            $this->stderr("Could not save the embed:\n", Console::FG_RED);

            foreach ($embed->getErrorSummary(true) as $error) {
                $this->stderr("  $error\n", Console::FG_RED);
            }

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Created {$embed->handle} ({$embed->getProviderName()}).\n", Console::FG_GREEN);
        $this->stdout("Reference tag: {$embed->getEmbedCode()}\n");

        return ExitCode::OK;
    }

    /**
     * Show what Eye makes of a URL, without saving anything.
     *
     * @param string $url The URL to inspect.
     */
    public function actionInspect(string $url): int
    {
        $plugin = Plugin::getInstance();
        $match = $plugin->providers->match($url);

        if (!$match) {
            $this->stderr("That is not a URL Eye can embed.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Provider:  {$match->provider->name}\n");
        $this->stdout("Embed URL: {$match->embedUrl}\n");

        if ($match->posterUrl) {
            $this->stdout("Poster:    {$match->posterUrl}\n");
        }

        $defaults = $match->getOptionDefaults();
        $this->stdout('Defaults:  ' . json_encode($defaults, JSON_UNESCAPED_SLASHES) . "\n");

        try {
            $framability = $plugin->framability->check($match->embedUrl, false);
            $this->stdout("Framing:   {$framability->getStatusLabel()} — {$framability->message}\n");
        } catch (Throwable $e) {
            $this->stdout("Framing:   could not check ({$e->getMessage()})\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    /**
     * Forget every cached proxy page and framing verdict.
     */
    public function actionClearCaches(): int
    {
        $plugin = Plugin::getInstance();
        $cleared = 0;

        /** @var Embed[] $embeds */
        $embeds = Embed::find()->status(null)->all();

        foreach ($embeds as $embed) {
            $plugin->framability->forget((string)$embed->url);

            if (in_array($embed->mode, EmbedOptions::FETCHING_MODES, true)) {
                $plugin->proxy->forget((string)$embed->url, $embed->getOptions());
            }

            $cleared++;
        }

        $this->stdout("Cleared caches for $cleared embed(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function truncate(string $value, int $length): string
    {
        return strlen($value) > $length ? substr($value, 0, $length - 1) . '…' : $value;
    }
}
