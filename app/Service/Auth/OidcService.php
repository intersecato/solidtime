<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Enums\Weekday;
use App\Models\User;
use App\Service\UserService;
use Facile\JoseVerifier\JWK\JwksProviderBuilder;
use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\ClientInterface;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Issuer\Metadata\Provider\MetadataProviderBuilder;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Facile\OpenIDClient\Service\Builder\UserInfoServiceBuilder;
use Facile\OpenIDClient\Service\AuthorizationService;
use Facile\OpenIDClient\Service\UserInfoService;
use Facile\OpenIDClient\Session\AuthSession;
use Facile\OpenIDClient\Session\AuthSessionInterface;
use Facile\OpenIDClient\Token\TokenSetInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Client\ClientInterface as PsrHttpClient;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use RuntimeException;

class OidcService
{
    public const STATE_SESSION_KEY = 'oidc.state';

    public const NONCE_SESSION_KEY = 'oidc.nonce';

    public const CODE_VERIFIER_SESSION_KEY = 'oidc.code_verifier';

    public const AUTH_SESSION_KEY = 'oidc.auth_session';

    public function __construct(private readonly UserService $userService) {}

    public function isEnabled(): bool
    {
        return (bool) config('services.oidc.enabled')
            && filled(config('services.oidc.issuer'))
            && filled(config('services.oidc.client_id'));
    }

    public function authorizationRedirectUrl(Request $request): string
    {
        $this->ensureEnabled();

        $state = Str::random(40);
        $nonce = Str::random(40);
        $codeVerifier = Str::random(96);
        $authSession = new AuthSession;
        $authSession->setState($state);
        $authSession->setNonce($nonce);
        $authSession->setCodeVerifier($codeVerifier);

        $request->session()->put(self::STATE_SESSION_KEY, $state);
        $request->session()->put(self::NONCE_SESSION_KEY, $nonce);
        $request->session()->put(self::CODE_VERIFIER_SESSION_KEY, $codeVerifier);
        $request->session()->put(self::AUTH_SESSION_KEY, $authSession->jsonSerialize());

        return $this->authorizationService()->getAuthorizationUri($this->client(), [
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $this->base64UrlEncode(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
        ]);
    }

    public function authenticateCallback(Request $request): User
    {
        $this->ensureEnabled();

        $error = $request->query('error');
        if (is_string($error)) {
            throw new RuntimeException('OIDC provider returned an error: '.$error);
        }

        $state = $request->query('state');
        $expectedState = $request->session()->pull(self::STATE_SESSION_KEY);
        if (! is_string($state) || ! is_string($expectedState) || ! hash_equals($expectedState, $state)) {
            throw new RuntimeException('Invalid OIDC state.');
        }

        $code = $request->query('code');
        if (! is_string($code) || $code === '') {
            throw new RuntimeException('Missing OIDC authorization code.');
        }

        $request->session()->forget([self::NONCE_SESSION_KEY, self::CODE_VERIFIER_SESSION_KEY]);
        $authSession = $this->pullAuthSession($request);
        $client = $this->client();
        $tokenSet = $this->authorizationService()->callback(
            $client,
            $request->query(),
            $this->redirectUri(),
            $authSession
        );
        $claims = $this->claims($client, $tokenSet);

        return $this->resolveUser($claims);
    }

    private function ensureEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('OIDC is not enabled.');
        }
    }

    private function client(): ClientInterface
    {
        $httpClient = $this->httpClient();
        $httpFactory = $this->httpFactory();
        $issuer = (new IssuerBuilder)
            ->setMetadataProviderBuilder(
                (new MetadataProviderBuilder)
                    ->setHttpClient($httpClient)
                    ->setRequestFactory($httpFactory)
                    ->setUriFactory($httpFactory)
            )
            ->setJwksProviderBuilder(
                new JwksProviderBuilder
            )
            ->build(rtrim((string) config('services.oidc.issuer'), '/').'/.well-known/openid-configuration');
        $clientSecret = config('services.oidc.client_secret');
        $authMethod = config('services.oidc.token_endpoint_auth_method')
            ?: (filled($clientSecret) ? 'client_secret_basic' : 'none');
        $clientMetadata = [
            'client_id' => (string) config('services.oidc.client_id'),
            'redirect_uris' => [$this->redirectUri()],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $authMethod,
        ];
        if (filled($clientSecret)) {
            $clientMetadata['client_secret'] = (string) $clientSecret;
        }

        return (new ClientBuilder)
            ->setHttpClient($httpClient)
            ->setIssuer($issuer)
            ->setClientMetadata(ClientMetadata::fromArray($clientMetadata))
            ->build();
    }

    private function authorizationService(): AuthorizationService
    {
        $httpFactory = $this->httpFactory();

        return (new AuthorizationServiceBuilder)
            ->setHttpClient($this->httpClient())
            ->setRequestFactory($httpFactory)
            ->build();
    }

    private function userInfoService(): UserInfoService
    {
        return (new UserInfoServiceBuilder)
            ->setHttpClient($this->httpClient())
            ->setRequestFactory($this->httpFactory())
            ->build();
    }

    private function httpClient(): PsrHttpClient
    {
        return new GuzzleClient([
            'timeout' => (float) config('services.oidc.http_timeout'),
            'http_errors' => false,
        ]);
    }

    private function httpFactory(): RequestFactoryInterface&UriFactoryInterface
    {
        return new HttpFactory;
    }

    /**
     * @return array<string, mixed>
     */
    private function claims(ClientInterface $client, TokenSetInterface $tokenSet): array
    {
        $claims = $tokenSet->claims();

        if (
            $tokenSet->getAccessToken() !== null
            && $client->getIssuer()->getMetadata()->getUserinfoEndpoint() !== null
        ) {
            $userInfo = $this->userInfoService()->getUserInfo($client, $tokenSet);
            if (is_array($userInfo)) {
                if (
                    isset($claims['sub'], $userInfo['sub'])
                    && is_string($claims['sub'])
                    && is_string($userInfo['sub'])
                    && ! hash_equals($claims['sub'], $userInfo['sub'])
                ) {
                    throw new RuntimeException('OIDC ID token and userinfo subjects do not match.');
                }

                $claims = array_merge($claims, $userInfo);
            }
        }

        if (! isset($claims['sub']) || ! is_string($claims['sub']) || $claims['sub'] === '') {
            throw new RuntimeException('OIDC user claims are missing sub.');
        }

        if (! isset($claims['email']) || ! is_string($claims['email']) || $claims['email'] === '') {
            throw new RuntimeException('OIDC user claims are missing email.');
        }

        return $claims;
    }

    private function pullAuthSession(Request $request): AuthSessionInterface
    {
        $authSession = $request->session()->pull(self::AUTH_SESSION_KEY);
        if (! is_array($authSession)) {
            throw new RuntimeException('Missing OIDC auth session.');
        }

        return AuthSession::fromArray($authSession);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function resolveUser(array $claims): User
    {
        $subject = (string) $claims['sub'];
        $email = Str::lower(trim((string) $claims['email']));
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ((bool) config('services.oidc.require_verified_email') && ! $emailVerified) {
            throw new RuntimeException('OIDC email is not verified.');
        }

        /** @var User|null $user */
        $user = User::query()
            ->active()
            ->where('oidc_sub', $subject)
            ->first();

        if ($user !== null) {
            return $this->syncProfilePhotoIfNeeded(
                $this->markEmailVerifiedIfNeeded($user, $emailVerified),
                $claims
            );
        }

        /** @var User|null $user */
        $user = User::query()
            ->active()
            ->where('email', $email)
            ->first();

        if ($user !== null) {
            if (! (bool) config('services.oidc.auto_link')) {
                throw new RuntimeException('OIDC auto-linking is disabled.');
            }

            if ($user->oidc_sub !== null && $user->oidc_sub !== $subject) {
                throw new RuntimeException('User is already linked to a different OIDC subject.');
            }

            $user->forceFill(['oidc_sub' => $subject])->save();

            return $this->syncProfilePhotoIfNeeded(
                $this->markEmailVerifiedIfNeeded($user, $emailVerified),
                $claims
            );
        }

        if (! (bool) config('services.oidc.auto_register')) {
            throw new RuntimeException('OIDC auto-registration is disabled.');
        }

        $nameClaim = (string) config('services.oidc.name_claim');
        $name = trim((string) ($claims[$nameClaim] ?? $claims['name'] ?? $claims['preferred_username'] ?? $email));
        if ($name === '') {
            $name = $email;
        }

        $user = $this->userService->createUser(
            $name,
            $email,
            Str::random(64),
            config('app.timezone', 'UTC'),
            Weekday::Monday,
            null,
            null,
            null,
            null,
            null,
            null,
            $emailVerified
        );
        $user->forceFill(['oidc_sub' => $subject])->save();

        return $this->syncProfilePhotoIfNeeded($user, $claims);
    }

    private function markEmailVerifiedIfNeeded(User $user, bool $emailVerified): User
    {
        if ($emailVerified && $user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function syncProfilePhotoIfNeeded(User $user, array $claims): User
    {
        if (! (bool) config('services.oidc.sync_profile_photo')) {
            return $user;
        }

        $pictureClaim = (string) config('services.oidc.picture_claim', 'picture');
        $pictureUrl = $claims[$pictureClaim] ?? $claims['picture'] ?? null;
        if (! is_string($pictureUrl) || $pictureUrl === '') {
            return $user;
        }

        $scheme = parse_url($pictureUrl, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return $user;
        }

        try {
            $photo = $this->downloadProfilePhoto($pictureUrl);
            if ($photo === null) {
                return $user;
            }

            [$contents, $extension] = $photo;
            $diskName = (string) config('jetstream.profile_photo_disk', 'public');
            $disk = Storage::disk($diskName);
            $previousPath = $user->profile_photo_path;
            $path = 'profile-photos/'.Str::random(40).'.'.$extension;

            $disk->put($path, $contents);
            $user->forceFill(['profile_photo_path' => $path])->save();

            if ($previousPath !== null && $previousPath !== $path) {
                $disk->delete($previousPath);
            }
        } catch (\Throwable $exception) {
            Log::warning('Failed to sync OIDC profile photo.', [
                'user_id' => $user->getKey(),
                'picture_url' => $pictureUrl,
                'exception' => $exception,
            ]);
        }

        return $user->refresh();
    }

    /**
     * @return array{string, string}|null
     */
    private function downloadProfilePhoto(string $pictureUrl): ?array
    {
        $response = (new GuzzleClient([
            'timeout' => (float) config('services.oidc.http_timeout'),
            'http_errors' => false,
            'allow_redirects' => true,
        ]))->get($pictureUrl);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return null;
        }

        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0] ?? ''));
        $extension = match ($contentType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
        if ($extension === null) {
            return null;
        }

        $contents = (string) $response->getBody();
        $maxBytes = (int) config('services.oidc.profile_photo_max_bytes', 1024 * 1024);
        if ($contents === '' || strlen($contents) > $maxBytes) {
            return null;
        }

        return [$contents, $extension];
    }

    private function redirectUri(): string
    {
        if (filled(config('services.oidc.redirect_uri'))) {
            return (string) config('services.oidc.redirect_uri');
        }

        return route('oidc.callback');
    }

    /**
     * @return list<string>
     */
    private function scopes(): array
    {
        $scopes = config('services.oidc.scopes');
        if (! is_array($scopes) || $scopes === []) {
            return ['openid', 'profile', 'email'];
        }

        return array_values(array_filter(array_map('strval', $scopes)));
    }
}
