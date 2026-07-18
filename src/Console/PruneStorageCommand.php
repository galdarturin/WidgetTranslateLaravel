<?php

namespace Newtxt\Laravel\Console;

use Illuminate\Console\Command;
use Newtxt\Laravel\Storage\HashedTranslationStore;
use Newtxt\Laravel\Storage\RenderedPageSnapshotStore;

class PruneStorageCommand extends Command
{
    protected $signature = 'newtxt:storage-prune
        {--older-than=30 : Minimum artifact age in days.}
        {--site= : Limit pruning to one site ID.}
        {--language=* : Limit pruning to one or more language codes.}
        {--delete : Delete stale artifacts. Without this flag the command only reports.}';

    protected $description = 'Report or delete stale NewTXT local storage artifacts.';

    public function handle(RenderedPageSnapshotStore $snapshots, HashedTranslationStore $translations): int
    {
        $olderThanDays = max(1, (int) $this->option('older-than'));
        $olderThanSeconds = $olderThanDays * 86400;
        $delete = (bool) $this->option('delete');
        $siteId = trim((string) $this->option('site')) ?: null;
        $languages = $this->option('language') ?: null;

        $pageSummary = $snapshots->pruneStaleArtifacts($olderThanSeconds, $delete, $siteId, $languages);
        $translationSummary = $translations->pruneStaleArtifacts($olderThanSeconds, $delete, $siteId, $languages);

        $mode = $delete ? 'Deleted' : 'Dry run';
        $this->line("{$mode}: rendered page artifacts stale={$pageSummary['stale']} deleted={$pageSummary['deleted']} bytes={$pageSummary['bytes']} scanned={$pageSummary['scanned']}");
        $this->line("{$mode}: hashed translation artifacts stale={$translationSummary['stale']} deleted={$translationSummary['deleted']} bytes={$translationSummary['bytes']} scanned={$translationSummary['scanned']}");

        if (!$delete) {
            $this->info('Run again with --delete to remove the reported stale artifacts.');
        }

        return self::SUCCESS;
    }
}
