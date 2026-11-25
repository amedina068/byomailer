<?php

use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ContactSubscribeController;
use App\Http\Controllers\Api\EmailIdentityController;
use App\Http\Controllers\Api\EmailLogController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\MailListController;
use App\Http\Controllers\Api\NewsletterAbWinnerController;
use App\Http\Controllers\Api\NewsletterContactsPreviewController;
use App\Http\Controllers\Api\NewsletterController;
use App\Http\Controllers\Api\NewsletterLinkContactController;
use App\Http\Controllers\Api\NewsletterLinkController;
use App\Http\Controllers\Api\NewsletterTestMailController;
use App\Http\Controllers\Api\NewsletterVariablesController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\SequenceController;
use App\Http\Controllers\Api\SequenceStatsController;
use App\Http\Controllers\Api\SequenceStepController;
use App\Http\Controllers\Api\SequenceStepFiltersController;
use App\Http\Controllers\Api\SequenceStepLinkContactController;
use App\Http\Controllers\Api\SequenceStepLinkController;
use App\Http\Controllers\Api\SequenceStepStatsController;
use App\Http\Controllers\Api\SequenceVariablesController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TopicController;
use App\Http\Controllers\Api\WebhookEndpointController;
use App\Http\Controllers\Api\WebhookEndpointDeliveryController;
use App\Http\Controllers\Api\WebhookEndpointSecretController;
use App\Http\Controllers\Api\WebhookEndpointTestController;
use App\Http\Controllers\SequenceStepContactMailExportController;
use App\Http\Middleware\EnforceAccessLevel;
use App\Http\Middleware\EnforceReadAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::pattern('contactId', '[0-9]+');
Route::pattern('webhookEndpointId', '[0-9]+');
Route::pattern('listId', '[0-9]+');
Route::pattern('topicId', '[0-9]+');
Route::pattern('blockId', '[0-9]+');
Route::pattern('newsletterId', '[0-9]+');
Route::pattern('emailIdentityId', '[0-9]+');
Route::pattern('emailTemplateId', '[0-9]+');
Route::pattern('emailLogId', '[0-9]+');
Route::pattern('sequenceId', '[0-9]+');
Route::pattern('sequenceStepId', '[0-9]+');
Route::pattern('projectId', '[0-9]+');

Route::middleware(['auth:sanctum', EnforceAccessLevel::class, 'throttle:api'])
    ->prefix('v1')
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('sequences/{sequenceId}/steps/{sequenceStepId}/contact-mails/export', SequenceStepContactMailExportController::class)
            ->name('sequences.steps.contact-mails.export');

        Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
        Route::get('contacts/{contactId}', [ContactController::class, 'show'])->name('contacts.show');
        Route::put('contacts/{contactId}', [ContactController::class, 'update'])->name('contacts.update');
        Route::patch('contacts/{contactId}', [ContactController::class, 'update'])->name('contacts.patch');

        Route::get('tags', [TagController::class, 'index'])->name('tags.index');
        Route::post('tags', [TagController::class, 'store'])->name('tags.store');
        Route::patch('tags/{tag}', [TagController::class, 'update'])->name('tags.update');
        Route::delete('tags/{tag}', [TagController::class, 'destroy'])->name('tags.destroy');

        Route::get('lists', [MailListController::class, 'index'])->name('lists.index');
        Route::post('lists', [MailListController::class, 'store'])->name('lists.store');
        Route::patch('lists/{listId}', [MailListController::class, 'update'])->name('lists.update');
        Route::delete('lists/{listId}', [MailListController::class, 'destroy'])->name('lists.destroy');
        Route::post('lists/{listId}/contacts', [ContactController::class, 'store'])->name('lists.contacts.store');
        Route::post('contacts/{contactId}/unsubscribe', [ContactController::class, 'unsubscribe'])->name('contacts.unsubscribe');
        Route::post('contacts/{contactId}/subscribe', ContactSubscribeController::class)->name('contacts.subscribe');
        Route::delete('lists/{listId}/contacts/{contactId}', [ContactController::class, 'removeFromList'])->name('lists.contacts.destroy');

        Route::get('lists/{listId}/topics', [TopicController::class, 'index'])->name('lists.topics.index');
        Route::post('lists/{listId}/topics', [TopicController::class, 'store'])->name('lists.topics.store');
        Route::patch('lists/{listId}/topics/{topicId}', [TopicController::class, 'update'])->name('lists.topics.update');
        Route::delete('lists/{listId}/topics/{topicId}', [TopicController::class, 'destroy'])->name('lists.topics.destroy');

        Route::get('blocks', [BlockController::class, 'index'])->name('blocks.index');
        Route::get('blocks/{blockId}', [BlockController::class, 'show'])->name('blocks.show');
        Route::post('blocks', [BlockController::class, 'store'])->name('blocks.store');
        Route::patch('blocks/{blockId}', [BlockController::class, 'update'])->name('blocks.update');
        Route::delete('blocks/{blockId}', [BlockController::class, 'destroy'])->name('blocks.destroy');

        Route::get('email-identities', [EmailIdentityController::class, 'index'])->name('email-identities.index');
        Route::get('email-identities/{emailIdentityId}', [EmailIdentityController::class, 'show'])->name('email-identities.show');

        Route::get('email-templates', [EmailTemplateController::class, 'index'])->name('email-templates.index');
        Route::get('email-templates/default', [EmailTemplateController::class, 'default'])->name('email-templates.default');
        Route::get('email-templates/{emailTemplateId}', [EmailTemplateController::class, 'show'])->name('email-templates.show');

        Route::get('newsletters', [NewsletterController::class, 'index'])->name('newsletters.index');
        Route::post('newsletters/contacts/preview', NewsletterContactsPreviewController::class)
            ->withoutMiddleware(EnforceAccessLevel::class)
            ->middleware([EnforceReadAccess::class, 'throttle:newsletter-preview'])
            ->name('newsletters.contacts.preview');
        Route::get('newsletters/{newsletterId}', [NewsletterController::class, 'show'])->name('newsletters.show');
        Route::get('newsletters/{newsletterId}/links', NewsletterLinkController::class)->name('newsletters.links.index');
        Route::get('newsletters/{newsletterId}/links/contacts', NewsletterLinkContactController::class)->name('newsletters.links.contacts.index');
        Route::post('newsletters', [NewsletterController::class, 'store'])->name('newsletters.store');
        Route::patch('newsletters/{newsletterId}', [NewsletterController::class, 'update'])->name('newsletters.update');
        Route::delete('newsletters/{newsletterId}', [NewsletterController::class, 'destroy'])->name('newsletters.destroy');
        Route::post('newsletters/{newsletterId}/test-mail', NewsletterTestMailController::class)
            ->middleware('throttle:newsletter-test-mail')
            ->name('newsletters.test-mail');
        Route::post('newsletters/{newsletterId}/ab-winner', NewsletterAbWinnerController::class)->name('newsletters.ab-winner');
        Route::get('newsletters/{newsletterId}/variables', [NewsletterVariablesController::class, 'show'])->name('newsletters.variables.show');
        Route::patch('newsletters/{newsletterId}/variables', [NewsletterVariablesController::class, 'update'])->name('newsletters.variables.update');

        Route::get('email-logs', [EmailLogController::class, 'index'])->name('email-logs.index');
        Route::get('email-logs/{emailLogId}', [EmailLogController::class, 'show'])->name('email-logs.show');

        Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
        Route::get('projects/{projectId}', [ProjectController::class, 'show'])->name('projects.show');
        Route::patch('projects/{projectId}', [ProjectController::class, 'update'])->name('projects.update');
        Route::delete('projects/{projectId}', [ProjectController::class, 'destroy'])->name('projects.destroy');

        Route::get('sequences', [SequenceController::class, 'index'])->name('sequences.index');
        Route::get('sequences/{sequenceId}', [SequenceController::class, 'show'])->name('sequences.show');
        Route::get('sequences/{sequenceId}/stats', SequenceStatsController::class)->name('sequences.stats');
        Route::get('sequences/{sequenceId}/variables', [SequenceVariablesController::class, 'show'])->name('sequences.variables.show');
        Route::patch('sequences/{sequenceId}/variables', [SequenceVariablesController::class, 'update'])->name('sequences.variables.update');
        Route::get('sequences/{sequenceId}/steps/{sequenceStepId}', [SequenceStepController::class, 'show'])->name('sequences.steps.show');
        Route::get('sequences/{sequenceId}/steps/{sequenceStepId}/stats', SequenceStepStatsController::class)->name('sequences.steps.stats');
        Route::get('sequences/{sequenceId}/steps/{sequenceStepId}/links', SequenceStepLinkController::class)->name('sequences.steps.links.index');
        Route::get('sequences/{sequenceId}/steps/{sequenceStepId}/links/contacts', SequenceStepLinkContactController::class)->name('sequences.steps.links.contacts.index');
        Route::patch('sequences/{sequenceId}/steps/{sequenceStepId}/filters', SequenceStepFiltersController::class)->name('sequences.steps.filters.update');

        Route::get('webhook-endpoints', [WebhookEndpointController::class, 'index'])->name('webhook-endpoints.index');
        Route::post('webhook-endpoints', [WebhookEndpointController::class, 'store'])->name('webhook-endpoints.store');
        Route::get('webhook-endpoints/{webhookEndpointId}', [WebhookEndpointController::class, 'show'])->name('webhook-endpoints.show');
        Route::patch('webhook-endpoints/{webhookEndpointId}', [WebhookEndpointController::class, 'update'])->name('webhook-endpoints.update');
        Route::delete('webhook-endpoints/{webhookEndpointId}', [WebhookEndpointController::class, 'destroy'])->name('webhook-endpoints.destroy');
        Route::get('webhook-endpoints/{webhookEndpointId}/deliveries', WebhookEndpointDeliveryController::class)->name('webhook-endpoints.deliveries');
        Route::post('webhook-endpoints/{webhookEndpointId}/test', WebhookEndpointTestController::class)
            ->middleware('throttle:webhook-test-ping')->name('webhook-endpoints.test');
        Route::post('webhook-endpoints/{webhookEndpointId}/secret', WebhookEndpointSecretController::class)->name('webhook-endpoints.secret');
    });

// Laravel 13.40 added QUERY to `Route::any()`. Wayfinder has no type for
// QUERY, so the route lists the other methods.
Route::match(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], '{any}', function (Request $request, string $any) {
    $query = $request->getQueryString();

    return redirect('/api/v1/'.$any.($query ? '?'.$query : ''), 308);
})
    ->where('any', '^(?!v1(\/|$)).*')
    ->name('api.legacy-redirect');
