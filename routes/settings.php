<?php

use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\AwsIdentityDetailsController;
use App\Http\Controllers\Settings\AwsIdentityListController;
use App\Http\Controllers\Settings\CustomLinkDomainCheckController;
use App\Http\Controllers\Settings\CustomLinkDomainController;
use App\Http\Controllers\Settings\CustomTrackingDomainCheckController;
use App\Http\Controllers\Settings\CustomTrackingDomainController;
use App\Http\Controllers\Settings\EmailCredentialController;
use App\Http\Controllers\Settings\EmailIdentityController;
use App\Http\Controllers\Settings\EmailIdentityRefreshController;
use App\Http\Controllers\Settings\EmailIdentityReplyToController;
use App\Http\Controllers\Settings\EmailIdentityTrackingController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\StorageSettingController;
use App\Http\Controllers\Settings\TeamController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Http\Controllers\Settings\UserArchiveController;
use App\Http\Controllers\Settings\WebhookController;
use App\Http\Controllers\Settings\WebhookSecretController;
use App\Http\Controllers\Settings\WebhookStatusController;
use App\Http\Controllers\Settings\WebhookTestController;
use App\Http\Middleware\EnforceAccessLevel;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', EnsureUserIsActive::class])->group(function () {
    Route::get('settings', fn () => redirect('/settings/profile'));

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', EnsureUserIsActive::class, 'verified', EnforceAccessLevel::class])->group(function () {
    // A Viewer can still delete the own account, change the own password and
    // delete the own API tokens.
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])
        ->withoutMiddleware(EnforceAccessLevel::class)
        ->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->withoutMiddleware(EnforceAccessLevel::class)
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');

    Route::get('settings/identities', [EmailIdentityController::class, 'index'])
        ->name('identities.index');
    Route::get('settings/identities/create', [EmailIdentityController::class, 'create'])
        ->name('identities.create');
    Route::get('settings/identities/aws-list', AwsIdentityListController::class)
        ->name('identities.aws-list');
    Route::get('settings/identities/aws-details', AwsIdentityDetailsController::class)
        ->name('identities.aws-details');
    Route::post('settings/identities', [EmailIdentityController::class, 'store'])
        ->name('identities.store');
    Route::get('settings/identities/{emailIdentity}', [EmailIdentityController::class, 'show'])
        ->name('identities.show');
    Route::delete('settings/identities/{emailIdentity}', [EmailIdentityController::class, 'destroy'])
        ->name('identities.destroy');
    Route::patch('settings/identities/{emailIdentity}/refresh', EmailIdentityRefreshController::class)
        ->name('identities.refresh');
    Route::put('settings/identities/{emailIdentity}/tracking', EmailIdentityTrackingController::class)
        ->name('identities.update-tracking');
    Route::put('settings/identities/{emailIdentity}/reply-to', EmailIdentityReplyToController::class)
        ->name('identities.update-reply-to');
    Route::put('settings/identities/{emailIdentity}/custom-tracking-domain', [CustomTrackingDomainController::class, 'update'])
        ->name('identities.update-custom-tracking-domain');
    Route::post('settings/identities/{emailIdentity}/custom-tracking-domain/check', CustomTrackingDomainCheckController::class)
        ->name('identities.check-custom-tracking-domain');
    Route::put('settings/identities/{emailIdentity}/custom-link-domain', [CustomLinkDomainController::class, 'update'])
        ->name('identities.update-custom-link-domain');
    Route::post('settings/identities/{emailIdentity}/custom-link-domain/check', CustomLinkDomainCheckController::class)
        ->name('identities.check-custom-link-domain');

    Route::get('settings/email-credentials', [EmailCredentialController::class, 'index'])
        ->name('email-credentials.index');
    Route::post('settings/email-credentials', [EmailCredentialController::class, 'store'])
        ->name('email-credentials.store');
    Route::post('settings/email-credentials/refresh-quota', [EmailCredentialController::class, 'refreshQuota'])
        ->name('email-credentials.refresh-quota');
    Route::post('settings/email-credentials/recheck-permissions', [EmailCredentialController::class, 'recheckPermissions'])
        ->name('email-credentials.recheck-permissions');

    Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])
        ->name('api-tokens.index');
    Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])
        ->name('api-tokens.store');
    Route::delete('settings/api-tokens/{tokenId}', [ApiTokenController::class, 'destroy'])
        ->withoutMiddleware(EnforceAccessLevel::class)
        ->name('api-tokens.destroy');

    Route::get('settings/storage', [StorageSettingController::class, 'index'])
        ->name('storage-setting.index');
    Route::post('settings/storage', [StorageSettingController::class, 'store'])
        ->name('storage-setting.store');
    Route::post('settings/storage/test', [StorageSettingController::class, 'testConnection'])
        ->name('storage-setting.test');
    Route::delete('settings/storage', [StorageSettingController::class, 'destroy'])
        ->name('storage-setting.destroy');

    Route::get('settings/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
    Route::post('settings/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::get('settings/webhooks/{webhookEndpointId}', [WebhookController::class, 'show'])
        ->whereNumber('webhookEndpointId')->name('webhooks.show');
    Route::delete('settings/webhooks/{webhookEndpointId}', [WebhookController::class, 'destroy'])
        ->whereNumber('webhookEndpointId')->name('webhooks.destroy');
    Route::patch('settings/webhooks/{webhookEndpointId}/status', WebhookStatusController::class)
        ->whereNumber('webhookEndpointId')->name('webhooks.status');
    Route::post('settings/webhooks/{webhookEndpointId}/secret', WebhookSecretController::class)
        ->whereNumber('webhookEndpointId')->name('webhooks.secret');
    Route::post('settings/webhooks/{webhookEndpointId}/test', WebhookTestController::class)
        ->whereNumber('webhookEndpointId')
        ->middleware('throttle:webhook-test-ping')
        ->name('webhooks.test');

    Route::get('settings/team', [TeamController::class, 'index'])->name('team.index');
    Route::post('settings/team', [TeamController::class, 'store'])->name('team.store');
    Route::patch('settings/team/{teamMember}', [TeamController::class, 'update'])->name('team.update');
    Route::post('settings/team/{teamMember}/archive', [UserArchiveController::class, 'store'])->name('team.archive');
    Route::delete('settings/team/{teamMember}/archive', [UserArchiveController::class, 'destroy'])->name('team.unarchive');
});
