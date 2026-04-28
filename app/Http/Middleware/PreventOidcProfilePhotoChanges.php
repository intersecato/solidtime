<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventOidcProfilePhotoChanges
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (
            $user?->oidc_sub !== null
            && $request->is('user/profile-photo')
            && $request->isMethod('delete')
        ) {
            abort(403, __('Profile photos are managed by your SSO provider.'));
        }

        return $next($request);
    }
}
