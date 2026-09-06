<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Strip the `proxy` query parameter before signature validation.
 *
 * m3u-tv's _applyProxyPlayback appends &proxy=true to AIOStreams live
 * stream URLs. The cache token already authenticates the request; the
 * proxy flag is irrelevant for this endpoint but invalidates the
 * ValidateSignature middleware if left in the query string.
 */
class StripProxyQueryParam
{
    public function handle(Request $request, Closure $next)
    {
        $request->query->remove('proxy');

        return $next($request);
    }
}
