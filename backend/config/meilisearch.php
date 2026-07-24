<?php

return [
    'host' => rtrim((string) env('MEILISEARCH_HOST', 'http://meilisearch:7700'), '/'),
    'key' => env('MEILISEARCH_KEY'),
];
