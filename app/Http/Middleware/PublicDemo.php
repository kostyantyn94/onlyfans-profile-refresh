<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/** Read-only public demo for requests that came through a reverse proxy (config/demo.php). */
class PublicDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        $readOnly = config('demo.read_only_when_proxied') && $request->headers->has('X-Forwarded-For');

        View::share('readOnly', $readOnly);

        if ($readOnly) {
            if ($url = config('demo.public_url')) {
                URL::forceRootUrl($url);
                URL::forceScheme(parse_url($url, PHP_URL_SCHEME) ?: 'https');
                // The paginator builds its links from the request's own URL, which
                // behind the proxy is plain http: point it at the public URL too.
                Paginator::currentPathResolver(fn () => URL::to($request->path()));
            }

            if (! $request->isMethodSafe() || $request->is('horizon', 'horizon/*')) {
                abort(403, 'This public demo is read-only.');
            }
        }

        return $next($request);
    }
}
