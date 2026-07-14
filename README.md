# NewTXT Translate Laravel Integration

The Laravel package is the server-side NewTXT integration path. A Laravel site installs this package instead of the standalone JavaScript snippet.

## Responsibilities

- Render the language switcher from Laravel with `@newtxtWidget()`.
- Call the NewTXT API from the server with dashboard-issued API, public, and private keys.
- Read languages, URL mode, rendering mode, and rendered-page cache policy from the NewTXT account.
- Serve translated public HTML through `newtxt.render` middleware.
- Store translated HTML in the configured Laravel cache store.
- Store source-text hash translations under the project storage directory.
- Store rendered page hashes and optional HTML snapshots for deterministic invalidation.
- Inject canonical, robots, Open Graph, and Twitter URL metadata into rendered translated HTML.
- Prewarm translated pages with Artisan commands.
- Read public sitemap URLs only to resolve paths for local prewarm.
- Expose local rendered page snapshot metadata for application-owned sitemap generation.
- Keep crawling, AI translation, and translation orchestration inside the NewTXT service.
- Keep private routes, account pages, checkout pages, APIs, and webhooks out of translation middleware.

## Install

```bash
composer require newtxt/newtxt-translate
php artisan vendor:publish --tag=newtxt-config
```

## Composer Distribution

`composer require newtxt/newtxt-translate` works for customer projects only after the package is available through Composer package discovery.

The Composer package name is `newtxt/newtxt-translate`. Packagist package names require the `vendor/project` format, so `newtxt-translate` is used as the project segment under the `newtxt` vendor.

Public release options:

- Publish the package source repository as `https://github.com/newtxt/newtxt-translate-lib`.
- Submit the repository to Packagist under the `newtxt/newtxt-translate` package name.
- Tag stable releases in the source repository, for example `v1.0.0`, so Composer can resolve installable versions.
- Make sure the Packagist account can publish packages under the protected `newtxt` vendor name.

Do not add a manual `version` field to `composer.json` for normal VCS releases. Composer reads installable versions from Git branches and tags.

Changes pushed to `main` update the `dev-main` package reference after Packagist refreshes its metadata, but customer applications only receive those changes after running `composer update newtxt/newtxt-translate` and deploying the updated lock file. Stable customer installs require a semantic version tag such as `v1.0.1`, `v1.1.0`, or `v2.0.0`.

Private or early-access release options:

- Use Private Packagist or another authenticated Composer repository.
- Configure the customer Laravel app with a VCS repository before running `composer require`:

```bash
composer config repositories.newtxt vcs https://github.com/newtxt/newtxt-translate-lib.git
composer require newtxt/newtxt-translate
```

Packagist API tokens are deployment credentials. Do not commit them, add them to `composer.json`, place them in this package, or expose them to customer applications. Use them only in Packagist/GitHub webhook configuration or trusted CI secrets when package updates need to be triggered automatically.

## Environment

The API and CDN URLs are owned by the package because all customer installs use the same NewTXT infrastructure. Customers only need the keys generated in the NewTXT dashboard:

```dotenv
NEWTXT_ENABLED=true
NEWTXT_PUBLIC_KEY=replace-with-dashboard-public-key
NEWTXT_API_KEY=replace-with-dashboard-api-key
NEWTXT_PRIVATE_KEY=replace-with-dashboard-private-key
NEWTXT_CALLBACK_ENABLED=false
```

`NEWTXT_PUBLIC_KEY` is safe to render into the widget script tag. `NEWTXT_API_KEY`, `NEWTXT_PRIVATE_KEY`, and `NEWTXT_CALLBACK_SECRET` are server-only. The API key is sent only on signed server-side integration requests. The private key signs those requests and is never sent as a header value. Do not render server-only keys into Blade, JavaScript, logs, or cached HTML.

Source language, target languages, URL mode, widget rendering mode, SEO mode, and translated-page cache policy are read from the customer's NewTXT account. Do not duplicate those values in application `.env` files.

## Route Middleware

Attach the middleware only to public cacheable HTML routes:

```php
Route::middleware(['web', 'newtxt.render'])->group(function () {
    Route::view('/about', 'pages.about');
    Route::view('/pricing', 'pages.pricing');
});
```

Do not attach it to admin, auth, account, checkout, billing, API, or webhook routes.

## Widget Rendering

Render the switcher from the Laravel layout:

```blade
@newtxtWidget()
```

Do not paste the standalone JavaScript snippet when this package is installed. The package renders the shared widget runtime under Laravel control.

## Prewarm

```bash
php artisan newtxt:prewarm --language=fr --path=/
php artisan newtxt:prewarm --language=fr --sitemap=https://example.com/sitemap.xml
```

When `sync_hashed_translations_on_prewarm` is enabled in the published package config, prewarm also syncs translated nodes into the local hashed translation store. If no `--language` option is passed, the command uses target languages from the NewTXT account.

Rendered HTML is stored in the Laravel cache store and as a project-local HTML snapshot. If the Laravel cache entry is missing later, the middleware rehydrates it from `storage/app/newtxt` before making a remote render request.

Sitemap prewarm accepts only public `http` and `https` URLs and blocks localhost, private IP, and reserved IP targets. The package does not crawl websites or generate translations by itself; it uses sitemap URLs only to collect source paths and then calls the NewTXT API for rendered pages and translated nodes.

## Hashed Translation Store

```bash
php artisan newtxt:translations-sync --language=fr --path=/about
```

The package stores reusable translated fragments by source-text hash:

```text
storage/app/newtxt/translations/{siteId}/{languageCode}/{sourceHash}.json
```

Each entry includes `sourceHash`, `translationHash`, `sourceText`, `translatedText`, node metadata, and timestamps. Application code can read these entries through the facade:

```php
use Newtxt\Laravel\Facades\Newtxt;

$entry = Newtxt::hashedTranslation('fr', 'Source text');
Newtxt::putHashedTranslation('fr', 'Source text', 'Translated text', ['source' => 'manual']);
```

## Rendered Page Snapshots

Translated renders are hashed after the local SEO pass. The package writes metadata and optional HTML snapshots under:

```text
storage/app/newtxt/pages/{siteId}/{languageCode}/{pageHash}.json
storage/app/newtxt/pages/{siteId}/{languageCode}/{pageHash}.html
storage/app/newtxt/pages/{siteId}/{languageCode}/indexes/{lookupHash}.json
```

`pageHash` includes the package hash version, site ID, language, URL mode, source path, and normalized HTML hash. `lookupHash` includes the site ID, language, URL mode, source path, query string, and page hash version so translated requests can be served from local storage immediately after prewarm. Bump `page_hash_version` in the published package config when the application needs to invalidate old page artifacts after a rendering policy change.

For source pages, the middleware can record the final Laravel HTML response hash when `store_source_page_hashes` is enabled in the published package config. Full source HTML is stored only when `store_source_html` is enabled.

Applications that own their sitemap can include locally stored translated snapshots through:

```php
$entries = Newtxt::renderedPageSitemapEntries('https://example.com');
```

The package builds sitemap locations from the provided site URL, stored source path, language code, and URL mode. Query-string snapshots are excluded by default.

## SEO Metadata

When local SEO metadata injection is enabled in the package config or account settings, rendered translated HTML receives a local SEO pass before it is cached or written to storage. The pass upserts:

- `<link rel="canonical">`
- `<meta property="og:url">`
- `<meta name="twitter:url">`
- `<meta name="robots">`
- optional description, Open Graph, and Twitter title/description values supplied through `renderPage(..., ['seo' => [...]] )`

Only absolute `http` and `https` URLs are accepted for SEO URL tags.

## Signed Service Callback

The package can expose an optional signed callback endpoint so NewTXT can ask the Laravel app to perform local operations after content or translation changes:

```text
POST /newtxt/callback
X-NewTXT-Timestamp: 1780000000
X-NewTXT-Signature: sha256=<hmac>
```

The HMAC is `hash_hmac('sha256', $timestamp . '.' . $rawBody, $callbackSecret)`. Requests outside `NEWTXT_CALLBACK_TOLERANCE_SECONDS` are rejected.

Supported actions are controlled by `callback_allowed_actions` in the published package config:

```json
{
  "requestId": "optional-idempotency-or-trace-id",
  "action": "page.prewarm",
  "data": {
    "languageCode": "fr",
    "path": "/about",
    "options": {
      "forceRefreshCache": true
    }
  }
}
```

Built-in actions:

- `health.check` returns package health metadata.
- `cache.clear` clears one local rendered-page cache entry.
- `page.prewarm` renders and stores one translated page without returning full HTML. When a matching local snapshot already exists, it can rehydrate the Laravel cache from local storage instead of calling the remote render API.
- `translations.sync` writes page node translations into the local hashed translation store.

Leave `NEWTXT_CALLBACK_ENABLED=false` unless a strong callback secret is configured and the callback URL has been registered in NewTXT.

## Cache Clearing

```bash
php artisan newtxt:cache-clear --language=fr --path=/about
```

The package clears one local rendered HTML cache entry at a time. It also removes the matching local snapshot lookup index so stale translated HTML is not served after an explicit clear. It does not flush the whole Laravel cache store.

## Developer API

```php
use Newtxt\Laravel\Facades\Newtxt;

$rendered = Newtxt::rememberRenderedPage('fr', '/about');
$snippet = Newtxt::widgetSnippet();
$stored = Newtxt::syncHashedTranslations('fr', '/about');
$sitemapEntries = Newtxt::renderedPageSitemapEntries('https://example.com');
```

Use direct API calls for controlled application workflows. Keep route middleware scoped to public pages for normal request handling.

## Development

```bash
composer install
composer run lint
composer run test
composer validate --strict
```

The package is intended for public Composer distribution. `composer.lock`, `vendor/`, and PHPUnit cache files are ignored so release tags stay source-only.

## Release Checklist

1. Confirm `composer validate --strict`, `composer run lint`, and `composer run test` pass.
2. Push the source repository to the public package host.
3. Submit the repository to Packagist as `newtxt/newtxt-translate`.
4. Create a stable semantic version tag, for example `v1.0.0`.
5. Verify a clean Laravel app can run `composer require newtxt/newtxt-translate`.

## License

The package is open-sourced under the MIT license.
