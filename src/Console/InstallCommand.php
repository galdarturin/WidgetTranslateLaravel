<?php

namespace Newtxt\Laravel\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'newtxt:install';

    protected $description = 'Show the required Laravel integration setup steps.';

    /**
     * Print setup guidance without writing secrets or modifying environment files.
     */
    public function handle(): int
    {
        $this->info('NewTXT Translate Laravel integration');
        $this->line('');
        $this->line('1. Publish package configuration:');
        $this->line('   php artisan vendor:publish --tag=newtxt-config');
        $this->line('');
        $this->line('2. Configure server-side environment values:');
        $this->line('   NEWTXT_ENABLED=true');
        $this->line('   NEWTXT_SITE_ID=00000000-0000-0000-0000-000000000000');
        $this->line('   NEWTXT_WIDGET_KEY=widget-key');
        $this->line('   NEWTXT_API_TOKEN=replace-with-server-api-token');
        $this->line('   NEWTXT_API_BASE_URL=https://api.newtxt.io/api/v1');
        $this->line('   NEWTXT_TARGET_LANGUAGES=fr,de,es');
        $this->line('   NEWTXT_STORE_HASHED_TRANSLATIONS=true');
        $this->line('   NEWTXT_STORE_RENDERED_PAGES=true');
        $this->line('   NEWTXT_INJECT_SEO_METADATA=true');
        $this->line('   NEWTXT_PAGE_HASH_VERSION=newtxt-laravel-v1');
        $this->line('   NEWTXT_CALLBACK_ENABLED=false');
        $this->line('   NEWTXT_CALLBACK_PATH=/newtxt/callback');
        $this->line('   NEWTXT_CALLBACK_SECRET=replace-with-random-callback-secret');
        $this->line('');
        $this->line('3. Attach middleware only to public HTML routes:');
        $this->line("   Route::middleware(['web', 'newtxt.render'])->group(function () { /* public routes */ });");
        $this->line('');
        $this->line('4. Render the switcher from the layout:');
        $this->line('   @newtxtWidget()');
        $this->line('');
        $this->line('5. Prewarm rendered pages and sync local translation artifacts:');
        $this->line('   php artisan newtxt:prewarm --language=fr --path=/');
        $this->line('   php artisan newtxt:translations-sync --language=fr --path=/');
        $this->line('');
        $this->line('6. Register the signed service callback URL in NewTXT:');
        $this->line('   https://your-domain.example/newtxt/callback');

        return self::SUCCESS;
    }
}
