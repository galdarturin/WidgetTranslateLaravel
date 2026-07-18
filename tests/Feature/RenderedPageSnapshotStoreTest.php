<?php

namespace Newtxt\Laravel\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Newtxt\Laravel\Storage\RenderedPageSnapshotStore;
use Newtxt\Laravel\Tests\TestCase;

class RenderedPageSnapshotStoreTest extends TestCase
{
    public function test_forget_removes_unreferenced_snapshot_artifacts(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-store-forget-' . bin2hex(random_bytes(6));
        config()->set('newtxt.storage_path', $storagePath);

        try {
            $store = app(RenderedPageSnapshotStore::class);
            $store->put([
                'siteId' => 'site-one',
                'languageCode' => 'fr',
                'path' => '/about',
                'query' => '',
                'urlMode' => 'path',
                'pageHash' => 'page-hash-one',
                'pageHashVersion' => 'newtxt-laravel-v3',
                'html' => '<html><head><title>Bonjour</title></head><body><main>Bonjour page</main></body></html>',
            ], true);

            $directory = $storagePath . '/pages/site-one/fr';
            $this->assertFileExists($directory . '/page-hash-one.json');
            $this->assertFileExists($directory . '/page-hash-one.html');

            $store->forget('site-one', 'fr', 'path', '/about', '', 'newtxt-laravel-v3');

            $this->assertFileDoesNotExist($directory . '/page-hash-one.json');
            $this->assertFileDoesNotExist($directory . '/page-hash-one.html');
        } finally {
            (new Filesystem())->deleteDirectory($storagePath);
        }
    }
}
