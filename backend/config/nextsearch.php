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

        // Alles andere wird beim Durchlauf übersprungen und als `skipped`
        // vermerkt. Leer lassen heißt: die Vorgabe unten gilt.
        'extensions' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INDEX_EXTENSIONS', '')),
        ))) ?: [
            'pdf', 'eml', 'msg', 'md', 'txt', 'rtf',
            'odt', 'ods', 'odp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'csv', 'html', 'htm', 'epub',
            'png', 'jpg', 'jpeg', 'tif', 'tiff',
        ],

        // Ordner, die nie betreten werden. Ergänzt die Ausschlussmuster, die
        // pro Ordner in der UI gepflegt werden.
        'ignored_directories' => ['.git', 'node_modules', '.trashbin', '.versions'],

        // Volltext, der pro Dokument in den Index geht. Alles darüber wird
        // abgeschnitten, damit einzelne Ausreißer den Index nicht sprengen.
        'max_indexed_characters' => 1_000_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Textextraktion (Apache Tika)
    |--------------------------------------------------------------------------
    |
    | OCR greift nur bei PDFs ohne Textlayer und bei Bilddateien. Sie kostet
    | spürbar Rechenzeit, deshalb ist sie abschaltbar.
    |
    */

    'tika' => [
        'url' => rtrim((string) env('TIKA_URL', 'http://tika:9998'), '/'),
        'timeout' => (int) env('TIKA_TIMEOUT', 300),
        'ocr' => [
            'enabled' => filter_var(env('TIKA_OCR_ENABLED', true), FILTER_VALIDATE_BOOL),
            'languages' => (string) env('TIKA_OCR_LANGUAGES', 'deu+eng'),
            // Weniger Zeichen als das gilt als „kein brauchbarer Textlayer".
            'min_characters' => 64,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Vorschaubilder
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

        // Formate ohne sinnvolles Rendering bekommen keine Datei; die
        // Oberfläche zeigt dort eine Typ-Kachel.
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
