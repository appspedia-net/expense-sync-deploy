<?php
/**
 * Expense Manager v3 sync API — front controller.
 *
 * All requests are rewritten here by public/.htaccess. This file only wires
 * middleware (CORS, app key, IP rate limit) and the route table; the work
 * happens in app/Controllers.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Auth;
use App\Router;
use App\Services\RateLimit;

// --- CORS / preflight ------------------------------------------------------
cors_headers();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Resolve the request path (works with or without the rewrite) ----------
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($scriptDir !== '' && strpos($path, $scriptDir) === 0) {
    $path = substr($path, strlen($scriptDir)) ?: '/';
}
if (strpos($path, '/index.php') === 0) {
    $path = substr($path, strlen('/index.php')) ?: '/';
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- Global guards ---------------------------------------------------------
$isHealth = ($path === '/v1/health' || $path === '/' || $path === '' || ($path === '/v1/admin/migrate' && $method === 'GET'));
if (!$isHealth) {
    Auth::requireAppKey();
    RateLimit::guard('ip:' . client_ip(), (int) config('rate_limits.per_ip_per_minute', 240), 60);
}

// --- Routes ----------------------------------------------------------------
$r = new Router();

$r->get('/', 'HealthController@health');
$r->get('/v1/health', 'HealthController@health');

// auth
$r->post('/v1/auth/apple', 'AuthController@apple');
$r->post('/v1/auth/google', 'AuthController@google');
$r->post('/v1/auth/otp/request', 'AuthController@otpRequest');
$r->post('/v1/auth/otp/verify', 'AuthController@otpVerify');

// me
$r->get('/v1/me', 'MeController@show');
$r->patch('/v1/me', 'MeController@update');
$r->delete('/v1/me', 'MeController@destroy');
$r->post('/v1/me/device', 'MeController@device');

// spaces
$r->post('/v1/spaces', 'SpacesController@create');
$r->get('/v1/spaces', 'SpacesController@index');
$r->get('/v1/spaces/{id}', 'SpacesController@show');
$r->patch('/v1/spaces/{id}', 'SpacesController@update');
$r->delete('/v1/spaces/{id}', 'SpacesController@destroy');
$r->post('/v1/spaces/{id}/leave', 'SpacesController@leave');
$r->post('/v1/spaces/{id}/archive', 'SpacesController@archive');

// members
$r->get('/v1/spaces/{id}/members', 'MembersController@index');
$r->patch('/v1/spaces/{id}/members/{uid}', 'MembersController@update');
$r->delete('/v1/spaces/{id}/members/{uid}', 'MembersController@remove');

// invites
$r->post('/v1/spaces/{id}/invites', 'InvitesController@create');
$r->get('/v1/spaces/{id}/invites', 'InvitesController@index');
$r->delete('/v1/spaces/{id}/invites/{code}', 'InvitesController@revoke');
$r->get('/v1/invites/{code}', 'InvitesController@preview');
$r->post('/v1/invites/{code}/accept', 'InvitesController@accept');

// sync + views
$r->get('/v1/spaces/{id}/changes', 'SyncController@pull');
$r->post('/v1/spaces/{id}/changes', 'SyncController@push');
$r->get('/v1/spaces/{id}/balances', 'SpaceDataController@balances');
$r->get('/v1/spaces/{id}/activity', 'SpaceDataController@activity');

// misc
$r->get('/v1/rates', 'RatesController@index');
$r->post('/v1/ai/receipt', 'AiController@receipt');
$r->post('/v1/ai/ask', 'AiController@ask');
$r->get('/v1/admin/stats', 'AdminController@stats');
$r->post('/v1/admin/migrate', 'AdminController@migrate');
$r->get('/v1/admin/migrate', 'AdminController@migrate');

$r->dispatch($method, $path);
