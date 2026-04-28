<?php

declare(strict_types=1);

return [
    'gotenberg' => [
        'url' => env('GOTENBERG_URL'),
        'basic_auth_username' => env('GOTENBERG_BASIC_AUTH_USERNAME'),
        'basic_auth_password' => env('GOTENBERG_BASIC_AUTH_PASSWORD'),
    ],

    'oidc' => [
        'enabled' => env('OIDC_ENABLED', false),
        'issuer' => env('OIDC_ISSUER'),
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'redirect_uri' => env('OIDC_REDIRECT_URI'),
        'token_endpoint_auth_method' => env('OIDC_TOKEN_ENDPOINT_AUTH_METHOD'),
        'scopes' => array_values(array_filter(array_map(
            'trim',
            explode(' ', (string) env('OIDC_SCOPES', 'openid profile email'))
        ))),
        'button_label' => env('OIDC_BUTTON_LABEL', 'Continue with SSO'),
        'name_claim' => env('OIDC_NAME_CLAIM', 'name'),
        'auto_register' => env('OIDC_AUTO_REGISTER', true),
        'auto_link' => env('OIDC_AUTO_LINK', true),
        'require_verified_email' => env('OIDC_REQUIRE_VERIFIED_EMAIL', false),
        'http_timeout' => env('OIDC_HTTP_TIMEOUT', 10),
    ],
];
