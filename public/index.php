<?php
declare(strict_types=1);

// PHP built-in server: let it serve real static files itself.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
    if (is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/src/bootstrap.php';

use Pims\Auth;
use Pims\Controllers\AttributeController;
use Pims\Controllers\AuthController;
use Pims\Controllers\CategoryController;
use Pims\Controllers\ProductController;
use Pims\Controllers\SyncController;
use Pims\Http\HttpException;
use Pims\Http\Request;
use Pims\Http\Response;
use Pims\Http\Router;

set_exception_handler(function (Throwable $e): void {
    if ($e instanceof HttpException) {
        Response::error($e->status, $e->errorCode, $e->getMessage(), $e->fields);
    }
    error_log((string) $e);
    Response::error(500, 'server_error', 'Something went wrong.');
});

$request = Request::fromGlobals();

// Serve the SPA shell for non-API routes
if (strpos($request->path, '/api/') !== 0) {
    $html = __DIR__ . '/app.html';
    if (is_file($html)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($html);
        exit;
    }
    throw new HttpException(404, 'not_found', 'Not found.');
}

Auth::start();

$router = new Router();

// Auth
$router->add('GET',  '/api/auth/me',     [AuthController::class, 'me'],     false);
$router->add('POST', '/api/auth/login',  [AuthController::class, 'login'],  false);
$router->add('POST', '/api/auth/logout', [AuthController::class, 'logout']);

// Categories
$router->add('GET',    '/api/categories',                    [CategoryController::class, 'index']);
$router->add('GET',    '/api/categories/{id}',               [CategoryController::class, 'show']);
$router->add('POST',   '/api/categories',                    [CategoryController::class, 'create']);
$router->add('PUT',    '/api/categories/{id}',               [CategoryController::class, 'update']);
$router->add('DELETE', '/api/categories/{id}',               [CategoryController::class, 'delete']);
$router->add('PUT',    '/api/categories/{id}/attributes',    [CategoryController::class, 'setAttributes']);

// Attributes
$router->add('GET',    '/api/attributes',                           [AttributeController::class, 'index']);
$router->add('GET',    '/api/attributes/{id}',                      [AttributeController::class, 'show']);
$router->add('POST',   '/api/attributes',                           [AttributeController::class, 'create']);
$router->add('PUT',    '/api/attributes/{id}',                      [AttributeController::class, 'update']);
$router->add('DELETE', '/api/attributes/{id}',                      [AttributeController::class, 'delete']);
$router->add('POST',   '/api/attributes/{id}/options',              [AttributeController::class, 'addOption']);
$router->add('PUT',    '/api/attributes/{id}/options/{optionId}',   [AttributeController::class, 'updateOption']);
$router->add('DELETE', '/api/attributes/{id}/options/{optionId}',   [AttributeController::class, 'deleteOption']);

// Products
$router->add('GET',    '/api/products',              [ProductController::class, 'index']);
$router->add('GET',    '/api/products/{id}',         [ProductController::class, 'show']);
$router->add('POST',   '/api/products',              [ProductController::class, 'create']);
$router->add('PUT',    '/api/products/{id}',         [ProductController::class, 'update']);
$router->add('DELETE', '/api/products/{id}',         [ProductController::class, 'delete']);
$router->add('POST',   '/api/products/{id}/restore', [ProductController::class, 'restore']);

// Sync
$router->add('POST', '/api/products/{id}/sync',          [SyncController::class, 'sync']);
$router->add('GET',  '/api/products/{id}/sync-status',   [SyncController::class, 'status']);
$router->add('POST', '/api/products/{id}/sync/resolve',  [SyncController::class, 'resolve']);
$router->add('POST', '/api/products/{id}/refresh-stock', [SyncController::class, 'refreshStock']);
$router->add('POST', '/api/sync/import',                 [SyncController::class, 'import']);
$router->add('GET',  '/api/sync/logs',                   [SyncController::class, 'logs']);

$route = $router->match($request);

Auth::verifyCsrf($request);
if ($route['auth'] && !Auth::check()) {
    throw new HttpException(401, 'unauthenticated', 'Please log in.');
}

($route['handler'])($request, $route['params']);
