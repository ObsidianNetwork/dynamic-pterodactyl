<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || $user->role === null) {
            return response()->json([
                'success' => false,
                'message' => 'Admin access required',
            ], 403);
        }
        return $next($request);
    }
}
