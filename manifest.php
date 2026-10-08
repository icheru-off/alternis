<?php
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/manifest+json; charset=utf-8');
$base = base_url() . '/';
echo json_encode([
    'name' => APP_NAME . ' — Suivi d\'alternance',
    'short_name' => APP_NAME,
    'description' => APP_TAGLINE,
    'start_url' => $base . 'index.php',
    'scope' => $base,
    'display' => 'standalone',
    'background_color' => '#0e1020',
    'theme_color' => '#7c5cfc',
    'orientation' => 'portrait-primary',
    'icons' => [
        ['src' => $base . 'assets/img/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => $base . 'assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
