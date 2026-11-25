<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserStorageSettingRequest;
use App\Services\StorageConnectionTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StorageSettingController extends Controller
{
    public function index(Request $request): Response
    {
        $setting = $request->user()->storageSetting()->first();

        return Inertia::render('settings/Storage', [
            'setting' => $setting ? [
                'id' => $setting->id,
                'provider' => $setting->provider,
                'bucket' => $setting->bucket,
                'region' => $setting->region,
                'endpoint' => $setting->endpoint,
                'public_base_url' => $setting->public_base_url,
                'path_prefix' => $setting->path_prefix,
                'is_verified' => $setting->is_verified,
                'updated_at' => $setting->updated_at?->toISOString(),
            ] : null,
        ]);
    }

    public function store(StoreUserStorageSettingRequest $request, StorageConnectionTester $tester): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $pathPrefix = isset($data['path_prefix']) && $data['path_prefix'] !== ''
            ? $data['path_prefix']
            : 'byomailer/'.$user->id;

        $setting = $user->storageSetting()->firstOrNew();
        $setting->fill([
            'user_id' => $user->id,
            'provider' => $data['provider'],
            'bucket' => $data['bucket'],
            'region' => $data['region'],
            'endpoint' => $data['endpoint'] ?? null,
            'access_key_id' => $data['access_key_id'],
            'secret_access_key' => $data['secret_access_key'],
            'public_base_url' => $data['public_base_url'],
            'path_prefix' => $pathPrefix,
            'is_verified' => false,
        ]);
        $setting->save();

        $result = $tester->test($setting);

        if (! $result['success']) {
            return back()->withErrors(['connection' => $result['error']])->withInput();
        }

        $setting->update(['is_verified' => true]);

        return back()->with('success', 'Storage settings saved and verified successfully.');
    }

    public function testConnection(Request $request, StorageConnectionTester $tester): JsonResponse
    {
        $setting = $request->user()->storageSetting()->first();

        if (! $setting) {
            return response()->json(['success' => false, 'error' => 'No storage settings configured.'], 404);
        }

        $result = $tester->test($setting);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->storageSetting()->delete();

        return back()->with('success', 'Storage settings removed.');
    }
}
