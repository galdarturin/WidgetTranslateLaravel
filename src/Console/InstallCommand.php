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
        $this->line('   NEWTXT_PUBLIC_KEY=replace-with-dashboard-public-key');
        $this->line('   NEWTXT_API_KEY=replace-with-dashboard-api-key');
        $this->line('   NEWTXT_PRIVATE_KEY=replace-with-dashboard-private-key');
        $this->line('   NEWTXT_CALLBACK_ENABLED=false');
        $this->line('');
        $this->line('   Languages, URL mode, rendering mode, and cache policy are read from the NewTXT dashboard.');
        $this->line('');
        $this->line('3. Attach middleware only to public HTML routes:');
        $this->line("   Route::middleware(['web', 'newtxt.render'])->group(function () { /* public routes */ });");
        $this->line('');
        $this->line('4. Render the switcher from the layout:');
        $this->line('   @newtxtWidget()');
        $this->line('');
        $this->line('5. Optional local cache tools. NewTXT still owns crawling and translation work:');
        $this->line('   php artisan newtxt:prewarm --language=fr --path=/');
        $this->line('   php artisan newtxt:translations-sync --language=fr --path=/');
        $this->line('');
        $this->line('6. Register the signed service callback URL in NewTXT:');
        $this->line('   https://your-domain.example/newtxt/callback');

        return self::SUCCESS;
    }
}
