<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SocialAccountResource;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class SocialAccountController extends Controller
{
    public function __construct(private readonly SocialProviderManager $providers)
    {
    }

    public function platforms()
    {
        return response()->json([
            'platforms' => collect($this->providers->all())->map(fn ($p) => [
                'key' => $p->platform(),
                'label' => $p->label(),
            ])->values(),
        ]);
    }

    public function index(Request $request)
    {
        $accounts = $request->user()->socialAccounts()->orderBy('platform')->get();

        return SocialAccountResource::collection($accounts);
    }

    /**
     * Mock connect flow: no real OAuth redirect, the account is created immediately.
     * Swap for a real provider and this becomes a callback-handling endpoint instead.
     */
    public function connect(Request $request)
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in($this->providers->supportedPlatforms())],
            'account_name' => ['required_unless:platform,instagram', 'string', 'max:255'],
            'username' => ['required_if:platform,instagram', 'nullable', 'string', 'max:255'],
            'password' => ['required_if:platform,instagram', 'nullable', 'string'],
        ]);

        $provider = $this->providers->resolve($data['platform']);
        $account = $provider->connect($request->user(), $data);

        return SocialAccountResource::make($account)->response()->setStatusCode(201);
    }

    /**
     * Real OAuth flow, step 1: return the platform's consent screen URL for the
     * frontend to navigate the browser to. Separate from connect() (which still
     * handles the mock platforms' fake-form flow untouched).
     */
    public function authorize(Request $request, string $platform)
    {
        if (! in_array($platform, $this->providers->supportedPlatforms(), true)) {
            throw new NotFoundHttpException();
        }

        $provider = $this->providers->resolve($platform);

        return response()->json([
            'authorization_url' => $provider->getAuthorizationUrl(
                $request->user(),
                $this->redirectUriFor($platform),
                $this->encodeState($request->user())
            ),
        ]);
    }

    /**
     * Real OAuth flow, step 2: the platform redirects the bare browser here (no
     * bearer token attached), so `state` is what tells us which user this is for.
     * Always ends in a redirect back to the frontend, success or failure — this
     * is a top-level navigation, not an API call the SPA can read a JSON error from.
     */
    public function callback(Request $request, string $platform)
    {
        $frontendBase = rtrim(config('app.frontend_url'), '/') . '/social-accounts';

        if (! in_array($platform, $this->providers->supportedPlatforms(), true)) {
            return redirect($frontendBase . '?error=' . urlencode('Unknown platform.'));
        }

        if ($request->query('error')) {
            return redirect($frontendBase . '?error=' . urlencode((string) $request->query('error')));
        }

        $code = $request->query('code');
        $state = $request->query('state');

        if (! $code || ! $state) {
            return redirect($frontendBase . '?error=' . urlencode('Missing code or state from provider.'));
        }

        $user = $this->decodeState((string) $state);
        if (! $user) {
            return redirect($frontendBase . '?error=' . urlencode('Connection request expired or invalid. Please try again.'));
        }

        try {
            $this->providers->resolve($platform)->connect($user, [
                'code' => $code,
                'redirect_uri' => $this->redirectUriFor($platform),
            ]);
        } catch (Throwable $e) {
            return redirect($frontendBase . '?error=' . urlencode($e->getMessage()));
        }

        return redirect($frontendBase . '?connected=' . urlencode($platform));
    }

    private function redirectUriFor(string $platform): string
    {
        return match ($platform) {
            'youtube' => (string) config('services.google.redirect_uri'),
            'facebook' => (string) config('services.facebook.redirect_uri'),
            default => url("/api/social-accounts/{$platform}/callback"),
        };
    }

    private function encodeState(User $user): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'nonce' => Str::random(16),
            'issued_at' => now()->timestamp,
        ]));
    }

    private function decodeState(string $state): ?User
    {
        try {
            $data = json_decode(Crypt::decryptString($state), true);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data) || ! isset($data['user_id'], $data['issued_at'])) {
            return null;
        }

        // 10-minute window to complete the provider's consent screen.
        if (now()->timestamp - (int) $data['issued_at'] > 600) {
            return null;
        }

        return User::find($data['user_id']);
    }

    public function update(Request $request, SocialAccount $socialAccount)
    {
        $this->authorizeAccount($request, $socialAccount);

        $data = $request->validate([
            'auto_publish_enabled' => ['sometimes', 'boolean'],
        ]);

        $socialAccount->update($data);

        return SocialAccountResource::make($socialAccount);
    }

    public function refresh(Request $request, SocialAccount $socialAccount)
    {
        $this->authorizeAccount($request, $socialAccount);

        $provider = $this->providers->resolve($socialAccount->platform);
        $provider->refreshToken($socialAccount);

        return SocialAccountResource::make($socialAccount->fresh());
    }

    public function disconnect(Request $request, SocialAccount $socialAccount)
    {
        $this->authorizeAccount($request, $socialAccount);

        $provider = $this->providers->resolve($socialAccount->platform);
        $provider->disconnect($socialAccount);

        return SocialAccountResource::make($socialAccount->fresh());
    }

    private function authorizeAccount(Request $request, SocialAccount $account): void
    {
        if ($account->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
