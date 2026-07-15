<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens the public intake endpoints (api/v1/public/*) to any origin.
 *
 * These are unauthenticated, throttled, write-only lead forms — the same trust
 * model as any public contact form — so a wildcard origin is appropriate and
 * lets the landing page be hosted (or tested) from anywhere.
 *
 * It is PREPENDED to the global stack so it runs outermost and therefore gets
 * the last word on the response: Laravel's own HandleCors would otherwise stamp
 * `Access-Control-Allow-Credentials: true`, which browsers reject when the
 * origin is `*`. The authenticated CRM routes keep the strict, credentialed
 * CORS policy from config/cors.php — this only touches api/v1/public/*.
 */
class PublicCors
{
    private const PATHS = ['api/v1/public/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is(...self::PATHS)) {
            return $next($request);
        }

        // Answer the preflight ourselves — deterministic, and never reaches routing.
        $response = $request->isMethod('OPTIONS') ? response('', 204) : $next($request);

        $response->headers->set('Access-Control-Allow-Origin', '*');
        // '*' and credentials are mutually exclusive; drop what HandleCors set.
        $response->headers->remove('Access-Control-Allow-Credentials');
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Accept, Content-Type, X-Requested-With');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
