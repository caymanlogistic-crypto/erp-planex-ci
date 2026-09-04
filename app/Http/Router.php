<?php

namespace App\Http;

use App\Security\AccessRequestContext;
use App\Security\AccessRuntime;
use App\Security\EffectiveAccess;
use App\Security\EffectiveAccessResolver;
use App\Security\PageRegistry;

class Router
{
    private array $routes = [];
    private PageRegistry $pageRegistry;
    private ?EffectiveAccess $effectiveAccess = null;

    public function __construct(?PageRegistry $pageRegistry = null)
    {
        $this->pageRegistry = $pageRegistry ?? new PageRegistry();
    }

    /** @param string|list<string>|null $pageKeys */
    public function get(string $path, callable $handler, string|array|null $pageKeys = null): self
    {
        return $this->addRoute('GET', $path, $handler, $pageKeys);
    }

    /** @param string|list<string>|null $pageKeys */
    public function post(string $path, callable $handler, string|array|null $pageKeys = null): self
    {
        return $this->addRoute('POST', $path, $handler, $pageKeys);
    }

    public function enableCompanyAccess(EffectiveAccessResolver $resolver, AccessRequestContext $request): self
    {
        // Resolution occurs once for this request and the provider itself must
        // read current central state. The result is never persisted in session.
        $this->effectiveAccess = $resolver->resolve($request);
        AccessRuntime::setCurrent($this->effectiveAccess);
        return $this;
    }

    public function pageRegistry(): PageRegistry
    {
        return $this->pageRegistry;
    }

    public function dispatch(string $method, string $uri): mixed
    {
        $uri = parse_url($uri, PHP_URL_PATH);
        $uri = $uri !== null ? $uri : '/';

        $basePath = trim(getenv('APP_BASE_PATH') ?: '');
        if ($basePath !== '') {
            $basePath = '/' . trim($basePath, '/');
            if (path_starts_with_base_path($uri, $basePath)) {
                $uri = substr($uri, strlen($basePath));
            }
        }

        $uri = rtrim($uri, '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== strtoupper($method)) {
                continue;
            }

            $params = $this->matchPath($route['path'], $uri);

            if ($params !== false) {
                if ($this->mustAuthorize($uri) && !$this->routeIsAuthorized($route)) {
                    return $this->denyAccess($uri);
                }
                return ($route['handler'])(...$params);
            }
        }

        http_response_code(404);
        header('Content-Type: text/plain');
        echo '404 Not Found';

        return null;
    }

    public function resolve(string $method, string $uri): mixed
    {
        return $this->dispatch($method, $uri);
    }

    private function matchPath(string $routePath, string $uri): array|false
    {
        if ($routePath === '/' && $uri === '/') {
            return [];
        }

        $routeSegments = $routePath === '/' ? [] : explode('/', trim($routePath, '/'));
        $uriSegments   = $uri === '/'       ? [] : explode('/', trim($uri, '/'));

        if (count($routeSegments) !== count($uriSegments)) {
            return false;
        }

        $params = [];

        foreach ($routeSegments as $i => $segment) {
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                $params[] = $uriSegments[$i];
            } elseif ($segment !== $uriSegments[$i]) {
                return false;
            }
        }

        return $params;
    }

    /** @param string|list<string>|null $pageKeys */
    private function addRoute(string $method, string $path, callable $handler, string|array|null $pageKeys): self
    {
        $normalizedPageKeys = null;
        if ($pageKeys !== null) {
            $normalizedPageKeys = is_array($pageKeys) ? array_values($pageKeys) : [$pageKeys];
            $this->pageRegistry->registerEndpoint($method, $path, $normalizedPageKeys);
        }

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'page_keys' => $normalizedPageKeys,
        ];

        return $this;
    }

    private function mustAuthorize(string $uri): bool
    {
        return $this->effectiveAccess !== null
            && ($uri === '/company' || str_starts_with($uri, '/company/'));
    }

    /** @param array{method:string,path:string,handler:callable,page_keys:?array} $route */
    private function routeIsAuthorized(array $route): bool
    {
        $pageKeys = $this->pageRegistry->endpointPageKeys($route['method'], $route['path']);
        if (!is_array($pageKeys) || $pageKeys === []) {
            return false;
        }

        foreach ($pageKeys as $pageKey) {
            if (is_string($pageKey) && $this->effectiveAccess?->canAccessPage($pageKey)) {
                return true;
            }
        }

        return false;
    }

    private function denyAccess(string $uri): null
    {
        http_response_code(403);
        if ($this->expectsJson($uri)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'access_denied'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo '403 Forbidden';
        }

        return null;
    }

    private function expectsJson(string $uri): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        return str_contains($accept, 'application/json')
            || $requestedWith === 'xmlhttprequest'
            || str_ends_with($uri, '/json')
            || str_ends_with($uri, '/data');
    }
}
