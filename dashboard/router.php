<?php

use App\Services\Dashboard\Dashboard;
use App\Services\Dashboard\VoiceSession;
use Illuminate\Contracts\Console\Kernel;

$address = getenv('SIR_DASHBOARD_ADDRESS');
$key = getenv('SIR_DASHBOARD_KEY');
$project = getenv('SIR_DASHBOARD_PROJECT');

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

if (! $key || ! $project || ! $address || ($_SERVER['HTTP_HOST'] ?? '') !== $address
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);

    return;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$prefix = '/dashboard-'.hash_hmac('sha256', 'path', $key);
$session = hash_hmac('sha256', 'session', $key);
$csrf = hash_hmac('sha256', 'csrf', $key);
$cookie = 'sir_dashboard_'.substr(hash('sha256', $address), 0, 12);

if ($method === 'GET' && $path === '/' && is_string($_GET['key'] ?? null) && hash_equals($key, $_GET['key'])) {
    setcookie($cookie, $session, ['path' => $prefix.'/', 'httponly' => true, 'samesite' => 'Strict']);
    header('Location: '.$prefix.'/', true, 303);

    return;
}

if (! is_string($path) || ! str_starts_with($path, $prefix.'/')
    || ! is_string($_COOKIE[$cookie] ?? null) || ! hash_equals($session, $_COOKIE[$cookie])) {
    http_response_code(403);
    echo 'Open the dashboard URL printed by sir start.';

    return;
}

if ($method === 'POST' && (($_SERVER['HTTP_ORIGIN'] ?? '') !== 'http://'.$address
    || ! is_string($_POST['_csrf'] ?? null) || ! hash_equals($csrf, $_POST['_csrf']))) {
    http_response_code(403);

    return;
}

if (! in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);

    return;
}

if (! @chdir($project) || ($identity = @stat('.')) === false
    || getenv('SIR_DASHBOARD_PROJECT_ID') !== $identity['dev'].':'.$identity['ino']) {
    http_response_code(503);

    return;
}

$base = dirname(__DIR__);
require is_file($base.'/vendor/autoload.php') ? $base.'/vendor/autoload.php' : $base.'/../../autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$route = substr($path, strlen($prefix));
if ($method === 'GET' && $route === '/ask-voice.js' && app(VoiceSession::class)->available()) {
    header('Content-Type: application/javascript; charset=utf-8');
    readfile(__DIR__.'/ask-voice.js');

    return;
}

if ($method === 'POST' && $route === '/ask-voice') {
    header('Content-Type: application/sdp');
    echo app(VoiceSession::class)->answer($_POST['sdp'] ?? null);

    return;
}

header('Content-Type: text/html; charset=utf-8');
echo app(Dashboard::class)->render($method, $route, $_GET, $_POST, $csrf, $prefix);
