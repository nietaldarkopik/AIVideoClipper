<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SocialAccountResource;
use App\Models\SocialAccount;
use App\Services\Social\SocialProviderManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            'account_name' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
        ]);

        $provider = $this->providers->resolve($data['platform']);
        $account = $provider->connect($request->user(), $data);

        return SocialAccountResource::make($account)->response()->setStatusCode(201);
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
