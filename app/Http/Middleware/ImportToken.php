<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ImportToken
{
    public function handle(Request $request, Closure $next)
    {
        $configured = (string) config('leadgen.import_token');
        abort_unless(strlen($configured) >= 32 && hash_equals($configured, (string) $request->bearerToken()), 401, 'Invalid import credentials.');
        return $next($request);
    }
}
