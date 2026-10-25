<?php
/**
 * Minimal router with `{param}` path placeholders.
 *
 *   $r = new Router();
 *   $r->get('/v1/spaces/{id}', 'SpacesController@show');
 *   $r->dispatch($_SERVER['REQUEST_METHOD'], $path);
 *
 * Handlers are "Class@method" strings (class in App\Controllers) or any callable.
 * The handler receives one argument: an associative array of path params.
 */

declare(strict_types=1);

namespace App;

class Router
{
    /** @var array<int, array{method:string, regex:string, names:string[], handler:mixed, pattern:string}> */
    private array $routes = [];

    public function get(string $pattern, $handler): self    { return $this->add('GET', $pattern, $handler); }
    public function post(string $pattern, $handler): self   { return $this->add('POST', $pattern, $handler); }
    public function patch(string $pattern, $handler): self  { return $this->add('PATCH', $pattern, $handler); }
    public function put(string $pattern, $handler): self    { return $this->add('PUT', $pattern, $handler); }
    public function delete(string $pattern, $handler): self { return $this->add('DELETE', $pattern, $handler); }

    public function add(string $method, string $pattern, $handler): self
    {
        $names = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function (array $m) use (&$names): string {
            $names[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '/?$#',
            'names' => $names,
            'handler' => $handler,
        ];
        return $this;
    }

    /**
     * Find and run the matching route. Emits 404 / 405 JSON errors itself.
     */
    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        $path = '/' . trim($path, '/');
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = [];
            foreach ($route['names'] as $i => $name) {
                $params[$name] = rawurldecode($m[$i + 1]);
            }
            $this->invoke($route['handler'], $params);
            return;
        }

        if ($allowed) {
            header('Allow: ' . implode(', ', array_unique($allowed)));
            fail('method_not_allowed', 'Method not allowed', 405);
        }
        fail('not_found', 'No route for ' . $method . ' ' . $path, 404);
    }

    /** @param mixed $handler */
    private function invoke($handler, array $params): void
    {
        if (is_string($handler) && strpos($handler, '@') !== false) {
            [$class, $method] = explode('@', $handler, 2);
            $fqcn = strpos($class, '\\') === false ? 'App\\Controllers\\' . $class : $class;
            if (!class_exists($fqcn) || !method_exists($fqcn, $method)) {
                throw new \RuntimeException("Handler {$handler} not found");
            }
            $instance = new $fqcn();
            $instance->$method($params);
            return;
        }
        if (is_callable($handler)) {
            $handler($params);
            return;
        }
        throw new \RuntimeException('Invalid route handler');
    }
}
