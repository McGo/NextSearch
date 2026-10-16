<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Admin-Zugang aus der Umgebung
    |--------------------------------------------------------------------------
    |
    | Wird von `php artisan nextsearch:bootstrap` beim Start angelegt. Ein
    | bereits vorhandener Nutzer mit dieser E-Mail bleibt unangetastet.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Indexierung
    |--------------------------------------------------------------------------
    */

    'index' => [
        'default_interval_minutes' => (int) env('INDEX_DEFAULT_INTERVAL_MINUTES', 15),
        'max_file_size' => (int) env('INDEX_MAX_FILE_SIZE_MB', 100) * 1024 * 1024,

        // Everything else is skipped during the crawl and marked `skipped`.
        // Leaving it empty means the default below applies.
        'extensions' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INDEX_EXTENSIONS', '')),
        ))) ?: [
            'pdf', 'eml', 'msg', 'md', 'txt', 'rtf',
            'odt', 'ods', 'odp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'csv', 'html', 'htm', 'epub',
            'png', 'jpg', 'jpeg', 'tif', 'tiff',
        ],

        // Folders that are never entered. Complements the exclude patterns
        // maintained per folder in the UI.
        'ignored_directories' => ['.git', 'node_modules', '.trashbin', '.versions'],

        // Full text that goes into the index per document. Anything beyond is
        // truncated so single outliers don't blow up the index.
        'max_indexed_characters' => 1_000_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Text extraction (Apache Tika)
    |--------------------------------------------------------------------------
    |
    | OCR only kicks in for PDFs without a text layer and for image files. It
    | costs noticeable processing time, so it can be turned off.
    |
    */

    'tika' => [
        'url' => rtrim((string) env('TIKA_URL', 'http://tika:9998'), '/'),
        'timeout' => (int) env('TIKA_TIMEOUT', 300),
        'ocr' => [
            'enabled' => filter_var(env('TIKA_OCR_ENABLED', true), FILTER_VALIDATE_BOOL),
            'languages' => (string) env('TIKA_OCR_LANGUAGES', 'deu+eng'),
            // Fewer characters than this counts as "no usable text layer".
            'min_characters' => 64,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Preview images
    |--------------------------------------------------------------------------
    */

    'preview' => [
        'enabled' => filter_var(env('PREVIEW_ENABLED', true), FILTER_VALIDATE_BOOL),
        'width' => (int) env('PREVIEW_WIDTH', 600),
        'disk' => env('PREVIEW_DISK', 's3'),
        'url_ttl_minutes' => (int) env('PREVIEW_URL_TTL_MINUTES', 10),

        'office' => [
            'enabled' => filter_var(env('PREVIEW_OFFICE_ENABLED', true), FILTER_VALIDATE_BOOL),
            'url' => rtrim((string) env('GOTENBERG_URL', 'http://gotenberg:3000'), '/'),
            'timeout' => (int) env('GOTENBERG_TIMEOUT', 120),
        ],

        // Formats without a useful rendering get no file; the interface shows
        // a type tile there.
        'renderable' => [
            'pdf' => 'pdf',
            'png' => 'image', 'jpg' => 'image', 'jpeg' => 'image',
            'gif' => 'image', 'webp' => 'image', 'tif' => 'image', 'tiff' => 'image',
            'doc' => 'office', 'docx' => 'office', 'odt' => 'office', 'rtf' => 'office',
            'xls' => 'office', 'xlsx' => 'office', 'ods' => 'office',
            'ppt' => 'office', 'pptx' => 'office', 'odp' => 'office',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tags
    |--------------------------------------------------------------------------
    |
    | The "Neu" tag is created by `php artisan nextsearch:bootstrap` and hangs on
    | every document indexed for the first time. The value below is only the
    | starting point — an administrator adjusts the duration in the interface,
    | and a change there is not overwritten on the next start.
    |
    */

    'tags' => [
        'new_duration_minutes' => (int) env('TAGS_NEW_DURATION_MINUTES', 7 * 24 * 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Öffentliche API
    |--------------------------------------------------------------------------
    |
    | Requests per minute per API key on /api/v1. Generous enough for a script
    | that tags a few thousand documents, tight enough to notice a runaway loop.
    | Documented in docs/api.md.
    |
    */

    'api' => [
        'rate_limit' => (int) env('API_RATE_LIMIT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Einladungen
    |--------------------------------------------------------------------------
    |
    | How long an invitation link stays valid, in hours. NextSearch sends no
    | mail: the link goes back to whoever created the account, in the interface
    | or through the admin API, and they pass it on. Documented in docs/api.md.
    |
    */

    'invitations' => [
        'expire_hours' => (int) env('INVITATION_EXPIRE_HOURS', 168),
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP-Server
    |--------------------------------------------------------------------------
    |
    | The Model Context Protocol endpoint under /api/mcp, so an assistant such
    | as Claude can search and tag. Authentication is either a personal API key
    | or, for clients that fetch their own token in a browser, the OAuth server
    | below. Documented in docs/mcp.md.
    |
    */

    'mcp' => [
        'enabled' => filter_var(env('MCP_ENABLED', true), FILTER_VALIDATE_BOOL),

        // What the client sees in `initialize`. The name ends up in the
        // connector list of the client, so it carries the installation's name.
        'server_name' => env('MCP_SERVER_NAME', 'NextSearch'),

        // Reported in `initialize`. The release tag is baked into the image by
        // CI; a local checkout says "dev".
        'server_version' => env('APP_VERSION', 'dev'),

        // Protocol revisions this server speaks, newest first. A client asking
        // for something else is answered with the first of these.
        'protocol_versions' => ['2025-06-18', '2025-03-26', '2024-11-05'],

        // Requests per minute per token. A tool call is one request.
        'rate_limit' => (int) env('MCP_RATE_LIMIT', 240),

        // Hits a single search tool call returns at most. Above that a model
        // stops reading and only burns context.
        'max_results' => (int) env('MCP_MAX_RESULTS', 25),

        'oauth' => [
            'enabled' => filter_var(env('MCP_OAUTH_ENABLED', true), FILTER_VALIDATE_BOOL),

            // RFC 7591. Switching this off means clients have to be entered by
            // hand — nothing Claude Desktop can do, so it stays on by default.
            'dynamic_registration' => filter_var(env('MCP_OAUTH_DYNAMIC_REGISTRATION', true), FILTER_VALIDATE_BOOL),

            'access_token_ttl_minutes' => (int) env('MCP_ACCESS_TOKEN_TTL_MINUTES', 60),
            'refresh_token_ttl_days' => (int) env('MCP_REFRESH_TOKEN_TTL_DAYS', 30),

            // Long enough for a slow browser redirect, short enough that a code
            // left in a log is worthless.
            'authorization_code_ttl_seconds' => 300,

            // New client registrations per hour per address.
            'registration_rate_limit' => (int) env('MCP_OAUTH_REGISTRATION_RATE_LIMIT', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Suchindex
    |--------------------------------------------------------------------------
    */

    'search' => [
        'index' => env('MEILISEARCH_INDEX', 'documents'),
        'per_page' => 20,
        'max_per_page' => 100,
        'facets' => ['instance_name', 'folder_label', 'extension', 'year', 'size_bucket', 'ocr_used'],
    ],

];
