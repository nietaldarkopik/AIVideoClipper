<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublishingProfileResource;
use App\Models\PublishingProfile;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublishingProfileController extends Controller
{
    public function index(Request $request)
    {
        $profiles = $request->user()->publishingProfiles()->with('socialAccounts')->get();

        return PublishingProfileResource::collection($profiles);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'social_account_ids' => ['sometimes', 'array'],
            'social_account_ids.*' => ['integer', 'exists:social_accounts,id'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['is_default'])) {
            $request->user()->publishingProfiles()->update(['is_default' => false]);
        }

        $profile = $request->user()->publishingProfiles()->create([
            'name' => $data['name'],
            'is_default' => $data['is_default'] ?? false,
        ]);

        if (! empty($data['social_account_ids'])) {
            $ownedIds = $request->user()->socialAccounts()->whereIn('id', $data['social_account_ids'])->pluck('id');
            $profile->socialAccounts()->sync($ownedIds);
        }

        return PublishingProfileResource::make($profile->load('socialAccounts'))->response()->setStatusCode(201);
    }

    public function update(Request $request, PublishingProfile $publishingProfile)
    {
        $this->authorizeProfile($request, $publishingProfile);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'social_account_ids' => ['sometimes', 'array'],
            'social_account_ids.*' => ['integer', 'exists:social_accounts,id'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['is_default'])) {
            $request->user()->publishingProfiles()->where('id', '!=', $publishingProfile->id)->update(['is_default' => false]);
        }

        $publishingProfile->update(collect($data)->except('social_account_ids')->all());

        if (array_key_exists('social_account_ids', $data)) {
            $ownedIds = $request->user()->socialAccounts()->whereIn('id', $data['social_account_ids'])->pluck('id');
            $publishingProfile->socialAccounts()->sync($ownedIds);
        }

        return PublishingProfileResource::make($publishingProfile->fresh('socialAccounts'));
    }

    public function destroy(Request $request, PublishingProfile $publishingProfile)
    {
        $this->authorizeProfile($request, $publishingProfile);
        $publishingProfile->delete();

        return response()->json(['message' => 'Publishing profile deleted.']);
    }

    private function authorizeProfile(Request $request, PublishingProfile $profile): void
    {
        if ($profile->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
