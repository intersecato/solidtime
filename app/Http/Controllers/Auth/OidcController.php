<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use App\Service\Auth\OidcService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class OidcController extends Controller
{
    public function redirect(Request $request, OidcService $oidc): RedirectResponse
    {
        if (! $oidc->isEnabled()) {
            abort(404);
        }

        try {
            return redirect()->away($oidc->authorizationRedirectUrl($request));
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('login')
                ->with('message', __('OIDC login failed. Please try again or contact your administrator.'));
        }
    }

    public function callback(Request $request, OidcService $oidc): RedirectResponse
    {
        if (! $oidc->isEnabled()) {
            abort(404);
        }

        try {
            $user = $oidc->authenticateCallback($request);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('login')
                ->with('message', __('OIDC login failed. Please try again or contact your administrator.'));
        }

        Auth::guard((string) config('fortify.guard', 'web'))->login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
