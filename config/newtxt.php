<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Integration Switch
    |--------------------------------------------------------------------------
    |
    | This flag lets operators disable the package without removing the
    | middleware, Blade directive, or scheduled commands from the application.
    |
    */
    'enabled' => env('NEWTXT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | NewTXT Site Identity
    |--------------------------------------------------------------------------
    |
    | Values in this section are created in the customer dashboard. The public
    | key may be rendered into the widget script tag. The API key is sent only
    | on authenticated server-side requests, and the private key is used only to
    | sign those requests.
    |
    */
    'site_id' => null,
    'public_key' => env('NEWTXT_PUBLIC_KEY', env('NEWTXT_WIDGET_KEY')),
    'api_key' => env('NEWTXT_API_KEY'),
    'private_key' => env('NEWTXT_PRIVATE_KEY'),
    'request_timeout' => env('NEWTXT_REQUEST_TIMEOUT', 20),
    'prewarm_render_timeout' => env('NEWTXT_PREWARM_RENDER_TIMEOUT', 180),

    /*
    |--------------------------------------------------------------------------
    | NewTXT API
    |--------------------------------------------------------------------------
    |
    | These URLs are owned by the package because every customer installation
    | talks to the same NewTXT API and CDN. Override them only in tests or
    | private deployments by publishing this config file.
    |
    */
    'widget_loader_url' => 'https://cdn.newtxt.io/widget/v1/loader.js',

    /*
    |--------------------------------------------------------------------------
    | Account Settings Cache
    |--------------------------------------------------------------------------
    |
    | Languages, URL mode, rendered-page cache policy, and related product
    | behavior are read from the customer's NewTXT account by site keys.
    |
    */
    'account_settings_cache_ttl' => 300,
    'account_settings_cache_prefix' => 'newtxt:account-settings',

    /*
    |--------------------------------------------------------------------------
    | Widget Runtime
    |--------------------------------------------------------------------------
    |
    | The package owns widget rendering for Laravel sites. Customers using this
    | package must not paste the standalone JavaScript snippet separately.
    |
    */
    'widget_container_id' => 'language-widget-slot',
    'navigation_mode' => 'redirect',
    'url_mode' => 'path',
    'request_language_attributes' => [
        'newtxt_language_code',
        'widget_language_prefix',
        'widget_language_subdomain',
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Rendered HTML Cache
    |--------------------------------------------------------------------------
    |
    | Store translated HTML in the configured Laravel cache store so repeat
    | visitors and crawlers can be served without a remote render request.
    |
    */
    'cache_store' => null,
    'cache_ttl' => 86400,
    'cache_prefix' => 'newtxt:rendered-pages',
    'refresh_rendered_pages_after_local_hit' => true,
    'rendered_page_refresh_ttl' => 300,
    'ignored_rendered_page_query_parameters' => [
        'utm',
        'utm_*',
        'gclid',
        'gbraid',
        'wbraid',
        'fbclid',
        'msclkid',
        'yclid',
        '_gl',
        '_ga',
        'ref',
        'ref_src',
    ],
    'rendered_page_query_parameters' => [],
    'reject_unsupported_rendered_page_queries' => true,
    'max_rendered_page_query_parameters' => 20,
    'max_rendered_page_query_length' => 1024,

    /*
    |--------------------------------------------------------------------------
    | Project-Local Translation Artifacts
    |--------------------------------------------------------------------------
    |
    | The package stores public-page artifacts under storage/app/newtxt by
    | default. These files support fast language switching, local debugging, and
    | deterministic invalidation without committing generated content.
    |
    */
    'storage_path' => storage_path('app/newtxt'),
    'store_hashed_translations' => true,
    'store_rendered_pages' => true,
    'store_rendered_html' => true,
    'store_source_page_hashes' => true,
    'store_source_html' => false,
    'sync_hashed_translations_on_render' => true,
    'sync_hashed_translations_after_response' => true,
    'sync_hashed_translations_on_prewarm' => true,
    'page_hash_version' => 'newtxt-laravel-v4-runtime-rendering',
    'require_translated_render_marker' => true,

    /*
    |--------------------------------------------------------------------------
    | Translated Page Sitemap
    |--------------------------------------------------------------------------
    |
    | Ready translated page snapshots are published from a dedicated sitemap
    | on the customer site. The generated XML is stored below storage_path and
    | refreshed after successful renders and on public sitemap requests.
    |
    */
    'sitemap_enabled' => true,
    'sitemap_path' => '/translate-sitemap.xml',
    'sitemap_site_url' => null,
    'sitemap_include_query_strings' => false,
    'sitemap_preserve_existing' => true,
    'sitemap_max_urls' => 50000,
    'sitemap_max_bytes' => 50000000,
    'sitemap_http_cache_ttl' => 300,
    'sitemap_middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | SEO Metadata Injection
    |--------------------------------------------------------------------------
    |
    | Rendered translated HTML returned by NewTXT is already SEO-aware. The
    | Laravel package still performs a local pass so canonical, Open Graph, and
    | robots tags remain present when cached or stored inside the project.
    |
    */
    'inject_seo_metadata' => true,
    'seo_robots' => 'index,follow',
    'incomplete_seo_robots' => 'noindex,follow',

    /*
    |--------------------------------------------------------------------------
    | Signed Service Callback
    |--------------------------------------------------------------------------
    |
    | The callback route is an optional server-to-server control channel used by
    | NewTXT to ask the Laravel app to clear local cache, prewarm rendered
    | pages, or sync local translation artifacts. Keep it disabled unless a
    | strong shared secret is configured in the deployment secret manager.
    |
    */
    'callback_enabled' => env('NEWTXT_CALLBACK_ENABLED', false),
    'callback_path' => env('NEWTXT_CALLBACK_PATH', '/newtxt/callback'),
    'callback_secret' => env('NEWTXT_CALLBACK_SECRET', env('NEWTXT_PRIVATE_KEY')),
    'callback_signature_header' => 'X-NewTXT-Signature',
    'callback_timestamp_header' => 'X-NewTXT-Timestamp',
    'callback_tolerance_seconds' => 300,
    'callback_replay_protection' => true,
    'callback_replay_cache_prefix' => 'newtxt:callback-replay',
    'callback_cache_store' => null,
    'callback_allowed_actions' => ['health.check', 'cache.clear', 'page.prewarm', 'translations.sync'],
    'callback_middleware' => ['throttle:30,1'],

    /*
    |--------------------------------------------------------------------------
    | Public Route Safety
    |--------------------------------------------------------------------------
    |
    | Middleware should run only on public HTML pages. These defaults exclude
    | private or non-page surfaces even when the middleware is registered widely.
    |
    */
    'excluded_paths' => [
        'admin*',
        'api*',
        'auth*',
        'login',
        'logout',
        'register',
        'account*',
        'dashboard*',
        'checkout*',
        'billing*',
        'webhooks*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Target Languages
    |--------------------------------------------------------------------------
    |
    | These values are local fallbacks only. Normal installations read source
    | and target languages from the customer's NewTXT account settings.
    |
    */
    'source_language' => 'en',
    'target_languages' => [],
];
