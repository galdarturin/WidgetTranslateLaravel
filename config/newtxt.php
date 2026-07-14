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
    | The site ID is used for authenticated server API calls. The widget key is
    | public and is used only when the package renders the shared widget runtime.
    |
    */
    'site_id' => env('NEWTXT_SITE_ID'),
    'widget_key' => env('NEWTXT_WIDGET_KEY', env('NEWTXT_SITE_KEY')),
    'source_language' => env('NEWTXT_SOURCE_LANGUAGE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Server API Credentials
    |--------------------------------------------------------------------------
    |
    | The API token must stay server-side. Never publish it into frontend
    | bundles, Blade templates, logs, or cached HTML responses.
    |
    */
    'api_token' => env('NEWTXT_API_TOKEN'),
    'api_base_url' => rtrim(env('NEWTXT_API_BASE_URL', 'https://api.newtxt.io/api/v1'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Widget Runtime
    |--------------------------------------------------------------------------
    |
    | The package owns widget rendering for Laravel sites. Customers using this
    | package must not paste the standalone JavaScript snippet separately.
    |
    */
    'widget_loader_url' => env('NEWTXT_WIDGET_LOADER_URL', 'https://cdn.newtxt.io/widget/v1/loader.js'),
    'widget_container_id' => env('NEWTXT_WIDGET_CONTAINER_ID', 'language-widget-slot'),
    'navigation_mode' => env('NEWTXT_NAVIGATION_MODE', 'redirect'),
    'url_mode' => env('NEWTXT_URL_MODE', 'path'),

    /*
    |--------------------------------------------------------------------------
    | Local Rendered HTML Cache
    |--------------------------------------------------------------------------
    |
    | Store translated HTML in the configured Laravel cache store so repeat
    | visitors and crawlers can be served without a remote render request.
    |
    */
    'cache_store' => env('NEWTXT_CACHE_STORE'),
    'cache_ttl' => (int) env('NEWTXT_CACHE_TTL', 86400),
    'cache_prefix' => env('NEWTXT_CACHE_PREFIX', 'newtxt:rendered-pages'),

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
    'storage_path' => env('NEWTXT_STORAGE_PATH', storage_path('app/newtxt')),
    'store_hashed_translations' => env('NEWTXT_STORE_HASHED_TRANSLATIONS', true),
    'store_rendered_pages' => env('NEWTXT_STORE_RENDERED_PAGES', true),
    'store_rendered_html' => env('NEWTXT_STORE_RENDERED_HTML', true),
    'store_source_page_hashes' => env('NEWTXT_STORE_SOURCE_PAGE_HASHES', true),
    'store_source_html' => env('NEWTXT_STORE_SOURCE_HTML', false),
    'sync_hashed_translations_on_prewarm' => env('NEWTXT_SYNC_HASHED_TRANSLATIONS_ON_PREWARM', true),
    'page_hash_version' => env('NEWTXT_PAGE_HASH_VERSION', 'newtxt-laravel-v1'),

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
    'inject_seo_metadata' => env('NEWTXT_INJECT_SEO_METADATA', true),
    'seo_robots' => env('NEWTXT_SEO_ROBOTS', 'index,follow'),

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
    'callback_secret' => env('NEWTXT_CALLBACK_SECRET'),
    'callback_signature_header' => env('NEWTXT_CALLBACK_SIGNATURE_HEADER', 'X-NewTXT-Signature'),
    'callback_timestamp_header' => env('NEWTXT_CALLBACK_TIMESTAMP_HEADER', 'X-NewTXT-Timestamp'),
    'callback_tolerance_seconds' => (int) env('NEWTXT_CALLBACK_TOLERANCE_SECONDS', 300),
    'callback_allowed_actions' => array_filter(explode(',', env('NEWTXT_CALLBACK_ACTIONS', 'health.check,cache.clear,page.prewarm,translations.sync'))),
    'callback_middleware' => array_filter(explode(',', env('NEWTXT_CALLBACK_MIDDLEWARE', ''))),

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
    | The middleware uses the first path segment as a language prefix only when
    | it is explicitly listed here. Keep this list aligned with NewTXT site
    | languages.
    |
    */
    'target_languages' => array_filter(explode(',', env('NEWTXT_TARGET_LANGUAGES', ''))),
];
