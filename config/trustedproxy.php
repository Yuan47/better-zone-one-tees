<?php

// Read through Laravel's configuration repository so this also works with config:cache.
return [
    'proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
];
