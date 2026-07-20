<?php

namespace Newtxt\Laravel\Http\Controllers;

use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Newtxt\Laravel\NewtxtManager;
use Throwable;

class NewtxtSitemapController extends Controller
{
    public function __construct(
        private readonly NewtxtManager $newtxt,
    ) {
    }

    /**
     * Serve the locally generated sitemap for ready translated pages.
     */
    public function __invoke(Request $request): Response
    {
        if (!(bool) config('newtxt.sitemap_enabled', true)) {
            abort(404);
        }

        try {
            $sitemap = $this->newtxt->refreshSitemap();
        } catch (Throwable) {
            return response('', 503, [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Retry-After' => '300',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $response = response($sitemap['xml'], 200, [
            'Cache-Control' => 'public, max-age=' . max(0, (int) config('newtxt.sitemap_http_cache_ttl', 300)),
            'Content-Type' => 'application/xml; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setEtag($sitemap['etag']);
        $response->setLastModified((new DateTimeImmutable())->setTimestamp($sitemap['lastModified']));
        $response->isNotModified($request);

        return $response;
    }
}
