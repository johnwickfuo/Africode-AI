<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional single-password gate for the whole app on a public VPS
 * (spec section 8). Enabled by setting APP_ACCESS_PASSWORD in .env;
 * uses HTTP Basic auth (any username, that password) so browsers and
 * phones remember it. Disabled when the env var is empty.
 */
class RequireAccessPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = config('africode.access_password');

        if (blank($password) || hash_equals((string) $password, (string) $request->getPassword())) {
            return $next($request);
        }

        return response('Restricted.', 401, [
            'WWW-Authenticate' => 'Basic realm="Africode Football AI", charset="UTF-8"',
        ]);
    }
}
