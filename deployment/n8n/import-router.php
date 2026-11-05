<?php
// The Docker-facing listener serves only authenticated ingestion and health.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path !== '/up' && !str_starts_with((string) $path, '/api/collection/') && !str_starts_with((string)$path,'/api/sales/') && $path !== '/api/leads/import') {
    http_response_code(404); exit;
}
require dirname(__DIR__, 2).'/public/index.php';
