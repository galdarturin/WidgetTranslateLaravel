# NewTXT Translate Laravel Integration

The Laravel package is the server-side NewTXT integration path. A Laravel site installs this package instead of the standalone JavaScript snippet.

## Responsibilities

- Treat the Laravel application as an unprepared host that should not build a
  separate translation subsystem.
- Render the language switcher from Laravel with `@newtxtWidget()`.
- Call the NewTXT API from the server with dashboard-issued API, public, and private keys.
- Read languages, URL mode, rendering mode, and rendered-page cache policy from the NewTXT account.
- Serve translated public HTML through `newtxt.render` middleware.
- Store API-rendered translated HTML in the configured Laravel cache store.
- Store API-provided source-text hash translations under the project storage directory.
- Store rendered page hashes and optional HTML snapshots for deterministic local invalidation.
- Inject canonical, hreflang, robots, Open Graph, Twitter, description, and table-of-contents metadata into public source and rendered translated HTML.
- Offer optional Artisan tools for local cache hydration and translation artifact sync.
- Read public sitemap URLs only when they already exist and are useful for local prewarm.
- Build translated sitemap entries from application-provided source sitemap entries without requiring a custom sitemap implementation.
- Include local rendered page snapshots in translated sitemap output when available.
- Keep crawling, discovery tools, AI translation, and translation orchestration inside the NewTXT service.
- Keep private routes, account pages, checkout pages, APIs, and webhooks out of translation middleware.

## Integration Model

NewTXT treats the Laravel application as an unprepared host. The host should not
need to build language routing, translation queues, crawler jobs, SEO translation
pipelines, or editor tooling only to become translatable.

This package is the Laravel edge for NewTXT:

- It renders the widget, signs server-side requests, protects private routes,
  serves translated public HTML, and keeps local cache/artifact adapters small.
- It sends heavy work to the NewTXT API, including rendered page generation,
  page translation lookup, account settings, and cache orchestration.
- It uses local source paths, public HTML responses, existing sitemap entries,
  and optional signed callbacks only as integration inputs for NewTXT-owned
  tools.

If a customer site has no sitemap, no translation tables, and no custom
multilingual code, that is a valid installation target. Do not add those systems
to the customer app just for this package.

## Install

```bash
composer require newtxt/newtxt-translate
php artisan vendor:publish --tag=newtxt-config
```

## Composer Distribution

`composer require newtxt/newtxt-translate` works for customer projects only after the package is available through Composer package discovery.

The Composer package name is `newtxt/newtxt-translate`. Packagist package names require the `vendor/project` format, so `newtxt-translate` is used as the project segment under the `newtxt` vendor.

Public release options:

- Publish the package source repository as `https://github.com/galdarturin/WidgetTranslateLaravel`.
- Submit the repository to Packagist under the `newtxt/newtxt-translate` package name.
- Tag stable releases in the source repository, for example `v1.0.0`, so Composer can resolve installable versions.
- Make sure the Packagist account can publish packages under the protected `newtxt` vendor name.

Do not add a manual `version` field to `composer.json` for normal VCS releases. Composer reads installable versions from Git branches and tags.

Changes pushed to `main` update the `dev-main` package reference after Packagist refreshes its metadata, but customer applications only receive those changes after running `composer update newtxt/newtxt-translate` and deploying the updated lock file. Stable customer installs require a semantic version tag such as `v1.0.1`, `v1.1.0`, or `v2.0.0`.

Private or early-access release options:

- Use Private Packagist or another authenticated Composer repository.
- Configure the customer Laravel app with a VCS repository before running `composer require`:

```bash
composer config repositories.newtxt vcs https://github.com/galdarturin/WidgetTranslateLaravel.git
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

If the host application rewrites localized routes before route middleware runs,
the middleware can read the language from configured request attributes while
using the current request path as the source path. The default allow-list is:

```php
'request_language_attributes' => [
    'newtxt_language_code',
    'widget_language_prefix',
    'widget_language_subdomain',
],
```

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

Prewarm is optional local cache hydration. It is not a crawler and it is not a
translation engine. Use it when the application already has known public paths,
an existing public sitemap, or a deploy workflow that benefits from warmed local
HTML cache entries.

When `sync_hashed_translations_on_prewarm` is enabled in the published package config, prewarm also syncs translated nodes into the local hashed translation store. If no `--language` option is passed, the command uses target languages from the NewTXT account.

Rendered HTML is stored in the Laravel cache store and as a project-local HTML snapshot. If the Laravel cache entry is missing later, the middleware rehydrates it from `storage/app/newtxt` before making a remote render request.

Sitemap prewarm accepts only public `http` and `https` URLs and blocks localhost, private IP, and reserved IP targets. The package does not crawl websites or generate translations by itself; it uses sitemap URLs only to collect source paths and then calls the NewTXT API for rendered pages and translated nodes. If the host does not already expose a sitemap, use request-time rendering, NewTXT dashboard discovery, or signed service callbacks instead of building a custom sitemap solely for this integration.

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

Manual writes are intended for controlled migrations, tests, or support tooling.
Normal customer installations should populate this store from NewTXT API
responses through `newtxt:prewarm`, `newtxt:translations-sync`, middleware
rendering, or signed service callbacks.

## Rendered Page Snapshots

Translated renders are hashed after the local SEO pass. The package writes metadata and optional HTML snapshots under:

```text
storage/app/newtxt/pages/{siteId}/{languageCode}/{pageHash}.json
storage/app/newtxt/pages/{siteId}/{languageCode}/{pageHash}.html
storage/app/newtxt/pages/{siteId}/{languageCode}/indexes/{lookupHash}.json
```

`pageHash` includes the package hash version, site ID, language, URL mode, source path, and normalized HTML hash. `lookupHash` includes the site ID, language, URL mode, source path, query string, and page hash version so translated requests can be served from local storage immediately after prewarm. Bump `page_hash_version` in the published package config when the application needs to invalidate old page artifacts after a rendering policy change.

For source pages, the middleware can record the Laravel-rendered source HTML response hash when `store_source_page_hashes` is enabled in the published package config. This snapshot is captured before the local SEO pass so generated head tags do not become translation source content. Full source HTML is stored only when `store_source_html` is enabled.

The default `page_hash_version` is `newtxt-laravel-v2` because the local SEO pass now writes a complete canonical and hreflang set plus page metadata. Applications with a published config should bump this value when deploying the updated package if old local snapshots must be invalidated immediately.

Applications provide their source sitemap entries and let the package build the translated sitemap output:

```php
$entries = Newtxt::sitemapEntries($sourceEntries, 'https://example.com', ['urlMode' => 'path']);
```

The package reads target languages from NewTXT account settings or local fallback config, builds translated locations from the provided site URL, and includes locally stored rendered page snapshots. Query-string snapshots are excluded by default.

## SEO Metadata

When local SEO metadata injection is enabled in the package config or account settings, public source HTML and rendered translated HTML receive a local SEO pass. Translated HTML is processed before it is cached or written to storage. The pass preserves native page metadata and adds only missing tags:

- `<link rel="canonical">`
- `<link rel="alternate" hreflang="...">` for the source page, every configured target language, and `x-default`
- `<title>` from supplied page title metadata or page headings when missing
- `<meta name="description">`
- `<meta property="og:url">`
- `<meta name="twitter:url">`
- `<meta name="robots">`
- Open Graph and Twitter title/description values
- `<meta name="newtxt:table-of-contents">` from supplied table-of-contents metadata or page headings

For translated pages, the canonical URL comes from the NewTXT render response when available and falls back to the configured URL mode. For source pages, the canonical URL comes from `app.url` plus the source path. Only absolute `http` and `https` URLs are accepted for SEO URL tags.

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
$sitemap = Newtxt::sitemapEntries($sourceEntries, 'https://example.com', ['urlMode' => 'path']);
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
