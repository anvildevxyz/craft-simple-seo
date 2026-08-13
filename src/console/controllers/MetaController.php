<?php

namespace anvildev\simpleseo\console\controllers;

use anvildev\simpleseo\helpers\Lookup;
use anvildev\simpleseo\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Meta inspection.
 *
 * `show` answers "why is this page's title/description/canonical that?"
 * without a browser, a template edit, or a guess about which fallback won.
 * It prints the same resolved model the front end and GraphQL both render
 * from, so what it reports is what ships.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class MetaController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string|null Site handle to resolve for. Defaults to the entry's own site.
     */
    public ?string $site = null;

    /**
     * @var bool Print the rendered tags instead of the resolved values.
     */
    public bool $tags = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['site', 'tags']);
    }

    /**
     * Prints the fully resolved meta for one entry, every fallback applied.
     */
    public function actionShow(int $id): int
    {
        $entry = Lookup::entry($id, $this->site);
        if (is_string($entry)) {
            $this->stderr($entry . "\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $meta = Plugin::getInstance()->getMeta();

        $this->stdout("\n" . $entry->title . ' ', Console::BOLD);
        $this->stdout('(' . $entry->getSite()->name . ")\n\n", Console::FG_GREY);

        if ($this->tags) {
            $this->stdout((string)$meta->renderTags($entry) . "\n\n");

            return ExitCode::OK;
        }

        $resolved = $meta->resolve($entry);
        foreach ($resolved->toArray() as $key => $value) {
            $this->stdout('  ' . str_pad($key, 18));
            $this->stdout($value === null ? '—' : $value, $value === null ? Console::FG_GREY : Console::FG_YELLOW);
            if (isset($resolved->sources[$key])) {
                $this->stdout('  ← ' . $resolved->sources[$key], Console::FG_GREY);
            }
            $this->stdout("\n");
        }

        $this->stdout("\n");

        return ExitCode::OK;
    }
}
