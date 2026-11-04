<?php

function create_pwa_manifest()
{
    // During first-run setup BASE_URL exists, while regular login uses the
    // persisted setting. Do not depend on the installer-only constant.
    $base_url = get_config('base_url') ?: (defined('BASE_URL') ? BASE_URL : '/');
    $base_url = rtrim($base_url, '/') . '/';

    $manifest = [];
    $manifest['name'] = "UnrealIRCd Admin WebPanel";
    $manifest['short_name'] = "WebPanel";
    $manifest['start_url'] = $base_url;
    $manifest['display'] = 'standalone';
    $manifest['background_color'] = '#fff';
    $manifest['theme_color'] = '#4a86ee';
    $manifest['icons'] = [
        (object)[
            'src' => $base_url.'img/favicon.ico',
            'sizes' => '64x64',
            'type' => 'image/x-icon'
        ],
        [
            'src' => $base_url.'img/unreal.jpg',
            'sizes' => '128x128',
            'type' => 'image/jpeg'
        ]
    ];

    $path = UPATH . '/manifest.json';
    if (is_file($path))
        return true;

    // A locked-down deployment creates this file during installation. Do not
    // try to loosen permissions or fail login if application code is read-only.
    if (!is_writable(UPATH))
        return false;

    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents($path, $json, LOCK_EX) !== false;
}
