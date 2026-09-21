<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\Providers\TikTokProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'name' => $request->string('name'),
            'email' => $request->string('email'),
            'password' => Hash::make($request->string('password')),
            'role' => User::ROLE_USER,
        ]);

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        /** @var User $user */
        $user = Auth::user();
        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'user' => UserResource::make($user),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return UserResource::make($request->user());
    }

    /**
     * Step 1 of "Login/Sign up with TikTok": return TikTok's consent screen
     * URL. Unlike SocialAccountController::authorize() (linking TikTok to an
     * already-logged-in user), this is hit by a guest, so the state payload
     * carries no user_id — only a purpose marker + nonce, checked in
     * tiktokCallback() below.
     */
    public function tiktokRedirect(TikTokProvider $tiktok)
    {
        $state = Crypt::encryptString(json_encode([
            'purpose' => 'login',
            'nonce' => Str::random(16),
            'issued_at' => now()->timestamp,
        ]));

        return response()->json([
            'authorization_url' => $tiktok->getAuthorizationUrl(null, $this->tiktokLoginRedirectUri(), $state),
        ]);
    }

    /**
     * Step 2: TikTok redirects the bare browser here (no bearer token). Finds
     * the app user by matching TikTok's open_id against an existing
     * SocialAccount, or creates a brand-new account on first login — either
     * way the resulting SocialAccount is left connected, so the same TikTok
     * account is immediately ready to publish to, no separate "Connect"
     * step needed. Ends in a redirect back to the frontend login page (a
     * top-level navigation, not an API call the SPA can read JSON from), with
     * a one-time Sanctum token in the query string for the SPA to pick up.
     */
    public function tiktokCallback(Request $request, TikTokProvider $tiktok)
    {
        $frontendBase = rtrim(config('app.frontend_url'), '/').'/login';

        if ($request->query('error')) {
            return redirect($frontendBase.'?error='.urlencode((string) $request->query('error')));
        }

        $code = $request->query('code');
        $state = $request->query('state');
        if (! $code || ! $state) {
            return redirect($frontendBase.'?error='.urlencode('Missing code or state from TikTok.'));
        }

        try {
            $payload = json_decode(Crypt::decryptString((string) $state), true);
        } catch (Throwable) {
            return redirect($frontendBase.'?error='.urlencode('Login request expired or invalid. Please try again.'));
        }

        // 10-minute window to complete TikTok's consent screen, same as
        // SocialAccountController::decodeState().
        if (
            ! is_array($payload)
            || ($payload['purpose'] ?? null) !== 'login'
            || now()->timestamp - (int) ($payload['issued_at'] ?? 0) > 600
        ) {
            return redirect($frontendBase.'?error='.urlencode('Login request expired or invalid. Please try again.'));
        }

        try {
            $exchanged = $tiktok->exchangeAuthorizationCode([
                'code' => $code,
                'redirect_uri' => $this->tiktokLoginRedirectUri(),
            ]);
        } catch (Throwable $e) {
            return redirect($frontendBase.'?error='.urlencode($e->getMessage()));
        }

        $existingAccount = SocialAccount::where('platform', 'tiktok')
            ->where('external_account_id', $exchanged['open_id'])
            ->first();

        if ($existingAccount) {
            $user = $existingAccount->user;
        } else {
            $profile = $exchanged['profile'];
            $user = User::create([
                'name' => $profile['display_name'] ?? 'TikTok user',
                // TikTok's Login Kit doesn't grant an email scope for unaudited
                // apps, so there's no real email to store — synthesize a unique
                // placeholder tied to the TikTok open_id instead of asking for
                // one up front. The random password below means email/password
                // login stays unusable for this account until the user sets one.
                'email' => 'tiktok_'.$exchanged['open_id'].'@users.existcode.local',
                'password' => Hash::make(Str::random(40)),
                'role' => User::ROLE_USER,
            ]);
        }

        $tiktok->storeAccount($user, $exchanged);

        $token = $user->createToken('web')->plainTextToken;

        return redirect($frontendBase.'?tiktok_token='.urlencode($token));
    }

    private function tiktokLoginRedirectUri(): string
    {
        return (string) (config('services.tiktok.login_redirect_uri') ?: url('/api/auth/tiktok/callback'));
    }
}
