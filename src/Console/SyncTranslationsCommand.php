<?php

namespace Newtxt\Laravel\Console;

use Illuminate\Console\Command;
use Newtxt\Laravel\NewtxtManager;
use Throwable;

class SyncTranslationsCommand extends Command
{
    protected $signature = 'newtxt:translations-sync
        {--language=* : Target language code. Defaults to configured target languages.}
        {--path=* : Source page path to sync.}';

    protected $description = 'Sync NewTXT page translations into local hash-addressed project artifacts.';

    /**
     * Store reusable translated fragments under the configured artifact path.
     */
    public function handle(NewtxtManager $newtxt): int
    {
        $languages = $this->resolveLanguages();
        $paths = $this->resolvePaths();

        if (!$languages) {
            $this->error('No languages were provided. Use --language or configure NEWTXT_TARGET_LANGUAGES.');
            return self::FAILURE;
        }

        if (!$paths) {
            $this->error('No paths were provided. Use --path to select public source pages.');
            return self::FAILURE;
        }

        $stored = 0;
        foreach ($languages as $language) {
            foreach ($paths as $path) {
                try {
                    $count = $newtxt->syncHashedTranslations($language, $path);
                    $stored += $count;
                    $this->line("Synced {$count} hashed translations for {$language} {$path}");
                } catch (Throwable $error) {
                    $this->warn("Failed {$language} {$path}: {$error->getMessage()}");
                }
            }
        }

        $this->info("Translation sync completed. Stored entries: {$stored}.");

        return self::SUCCESS;
    }

    /**
     * Resolve language options into normalized language codes.
     */
    private function resolveLanguages(): array
    {
        $languages = $this->option('language') ?: config('newtxt.target_languages', []);

        return collect($languages)
            ->map(fn ($language) => strtolower(trim((string) $language)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Normalize selected source paths.
     */
    private function resolvePaths(): array
    {
        return collect($this->option('path'))
            ->map(fn ($path) => '/' . ltrim(trim((string) $path), '/'))
            ->map(fn ($path) => $path === '//' ? '/' : $path)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
