<?php

namespace Newtxt\Laravel\Console;

use Illuminate\Console\Command;
use Newtxt\Laravel\NewtxtManager;
use Throwable;

class ClearCacheCommand extends Command
{
    protected $signature = 'newtxt:cache-clear {--language= : Target language code} {--path= : Source page path}';

    protected $description = 'Clear one local translated HTML cache entry.';

    public function handle(NewtxtManager $newtxt): int
    {
        $language = (string) $this->option('language');
        $path = (string) $this->option('path');

        if ($language === '' || $path === '') {
            $this->error('Use --language and --path so only one translated HTML cache entry is cleared.');
            return self::FAILURE;
        }

        try {
            $newtxt->clearRenderedPageCache($language, $path);
        } catch (Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }

        $this->info("Cleared NewTXT local cache for {$language} {$path}.");

        return self::SUCCESS;
    }
}
