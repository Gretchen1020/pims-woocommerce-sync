<?php
declare(strict_types=1);

namespace Pims\Http;

final class Router
{
    /** @var array<int, array{method:string, regex:string, handler:callable, auth:bool}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, bool $auth = true): void
    {
        // '/api/products/{id}' -> '#^/api/products/(?P<id>[^/]+)$#'
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => '#^' . $regex . '$#',
            'handler' => $handler,
            'auth'    => $auth,
        ];
    }

    /**
     * @return array{handler:callable, params:array<string,string>, auth:bool}
     * @throws HttpException 404 when no path matches, 405 when the path matches another method
     */
    public function match(Request $request): array
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['handler' => $route['handler'], 'params' => $params, 'auth' => $route['auth']];
        }
        if ($pathMatched) {
            throw new HttpException(405, 'method_not_allowed', 'Method not allowed.');
        }
        throw new HttpException(404, 'not_found', 'Not found.');
    }
}
