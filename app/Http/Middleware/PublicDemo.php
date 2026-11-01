<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
            }

            if (! $request->isMethodSafe() || $request->is('horizon', 'horizon/*')) {
                abort(403, 'This public demo is read-only.');
            }
        }

        return $next($request);
    }
}
