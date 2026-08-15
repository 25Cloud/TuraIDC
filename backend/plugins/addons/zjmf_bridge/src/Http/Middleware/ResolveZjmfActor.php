<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveZjmfActor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->has('zjmf_actor_type')) {
            $request->attributes->set('zjmf_actor_type', $request->attributes->has('zjmf_app_id') ? 'system' : 'guest');
        }

        return $next($request);
    }
}
