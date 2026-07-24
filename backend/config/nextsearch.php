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
