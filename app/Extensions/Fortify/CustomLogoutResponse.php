<?php

declare(strict_types=1);

namespace App\Extensions\Fortify;

use App\Service\Auth\OidcService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;

class CustomLogoutResponse implements LogoutResponseContract
{
    public function __construct(private readonly OidcService $oidcService) {}

    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return response()->json('', 204);
        }

        $oidcLogoutUrl = $this->oidcService->endSessionUrl();

        if ($oidcLogoutUrl !== null) {
            return Inertia::location($oidcLogoutUrl);
        }

        return Inertia::location('/');
    }
}
