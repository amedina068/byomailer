<?php

use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\UserDeactivationController;
use App\Http\Controllers\AttributeDefaultController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\AutomationProjectController;
use App\Http\Controllers\AutomationStatusController;
use App\Http\Controllers\BlockController;
use App\Http\Controllers\BlockListController;
use App\Http\Controllers\ConfirmOptInController;
use App\Http\Controllers\ConfirmResubscribeController;
use App\Http\Controllers\ConfirmUnsubscribeController;
use App\Http\Controllers\ContactAttributeController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContactExportController;
use App\Http\Controllers\ContactImportController;
use App\Http\Controllers\ContactImportStatusController;
use App\Http\Controllers\ContactListController;
use App\Http\Controllers\ContactMailController;
use App\Http\Controllers\ContactSequenceEnrollmentController;
use App\Http\Controllers\ContactTagController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentationApiController;
use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\DocumentationGuideController;
use App\Http\Controllers\DocumentationMarkdownController;
use App\Http\Controllers\DocumentationOpenApiController;
use App\Http\Controllers\DocumentationPostmanController;
use App\Http\Controllers\DuplicateEmailTemplateController;
use App\Http\Controllers\EditorImageController;
use App\Http\Controllers\EmailLogController;
use App\Http\Controllers\EmailTemplateArchiveController;
use App\Http\Controllers\EmailTemplateController;
use App\Http\Controllers\EmailTemplateListController;
use App\Http\Controllers\GalleryImageController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\MailListController;
use App\Http\Controllers\MailListImportController;
use App\Http\Controllers\NewsletterAbWinnerController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\NewsletterMailExportController;
use App\Http\Controllers\NewsletterProjectController;
use App\Http\Controllers\NewsletterTestMailController;
use App\Http\Controllers\NewsletterUnscheduleController;
use App\Http\Controllers\NewsletterVariablesController;
use App\Http\Controllers\Onboarding\AwsOnboardingController;
use App\Http\Controllers\OneClickUnsubscribeController;
use App\Http\Controllers\OptInController;
use App\Http\Controllers\PreferenceCenterController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReactivateContactController;
use App\Http\Controllers\ReputationController;
use App\Http\Controllers\ResubscribeController;
use App\Http\Controllers\SegmentArchiveController;
use App\Http\Controllers\SegmentController;
use App\Http\Controllers\SequenceContactController;
use App\Http\Controllers\SequenceController;
use App\Http\Controllers\SequenceProjectController;
use App\Http\Controllers\SequenceStatusController;
use App\Http\Controllers\SequenceStepContactController;
use App\Http\Controllers\SequenceStepContactMailExportController;
use App\Http\Controllers\SequenceStepController;
use App\Http\Controllers\SequenceStepFiltersController;
use App\Http\Controllers\SequenceStepStatusController;
use App\Http\Controllers\SequenceStepTestMailController;
use App\Http\Controllers\SesWebhookController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TlsAskController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\VisualAutomationContactController;
use App\Http\Controllers\VisualAutomationController;
use App\Http\Controllers\VisualAutomationNodeContactController;
use App\Http\Controllers\VisualAutomationProjectController;
use App\Http\Controllers\VisualAutomationStatusController;
use App\Http\Middleware\EnforceAccessLevel;
use App\Http\Middleware\EnforceReadAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsOwner;
use App\Http\Middleware\SetTenantFromContactMailLink;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

// The first-run page. It creates the owner account and closes when a user exists.
Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::get('setup', [SetupController::class, 'show'])->name('setup.show');
    Route::post('setup', [SetupController::class, 'store'])->name('setup.store');
});

Route::get('tls/ask', TlsAskController::class)
    ->middleware('throttle:tls-ask')
    ->name('tls.ask');

Route::get('documentation', DocumentationController::class)->name('documentation');
Route::prefix('documentation/api')->name('documentation.api.')->group(function () {
    Route::get('/', [DocumentationApiController::class, 'index'])->name('index');
    Route::get('contacts', [DocumentationApiController::class, 'contacts'])->name('contacts');
    Route::get('tags', [DocumentationApiController::class, 'tags'])->name('tags');
    Route::get('lists', [DocumentationApiController::class, 'lists'])->name('lists');
    Route::get('topics', [DocumentationApiController::class, 'topics'])->name('topics');
    Route::get('blocks', [DocumentationApiController::class, 'blocks'])->name('blocks');
    Route::get('newsletters', [DocumentationApiController::class, 'newsletters'])->name('newsletters');
    Route::get('sequences', [DocumentationApiController::class, 'sequences'])->name('sequences');
    Route::get('link-clicks', [DocumentationApiController::class, 'linkClicks'])->name('link-clicks');
    Route::get('projects', [DocumentationApiController::class, 'projects'])->name('projects');
    Route::get('filters', [DocumentationApiController::class, 'filters'])->name('filters');
    Route::get('email-identities', [DocumentationApiController::class, 'emailIdentities'])->name('email-identities');
    Route::get('email-templates', [DocumentationApiController::class, 'emailTemplates'])->name('email-templates');
    Route::get('email-logs', [DocumentationApiController::class, 'emailLogs'])->name('email-logs');
    Route::get('webhooks', [DocumentationApiController::class, 'webhooks'])->name('webhooks');
});
Route::get('documentation/postman', DocumentationPostmanController::class)->name('documentation.postman');
Route::get('documentation/openapi', DocumentationOpenApiController::class)->name('documentation.openapi');
Route::get('/byomailer-docs.md', DocumentationMarkdownController::class)->name('documentation.markdown');

Route::prefix('documentation/guides')->name('documentation.guides.')->group(function () {
    Route::get('getting-started', [DocumentationGuideController::class, 'gettingStarted'])->name('getting-started');
    Route::get('create-aws-account', [DocumentationGuideController::class, 'createAwsAccount'])->name('create-aws-account');
    Route::get('create-iam-user', [DocumentationGuideController::class, 'createIamUser'])->name('create-iam-user');
    Route::get('create-access-keys', [DocumentationGuideController::class, 'createAccessKeys'])->name('create-access-keys');
    Route::get('create-policies', [DocumentationGuideController::class, 'createPolicies'])->name('create-policies');
    Route::get('attach-policies', [DocumentationGuideController::class, 'attachPolicies'])->name('attach-policies');
    Route::get('request-production-access', [DocumentationGuideController::class, 'requestProductionAccess'])->name('request-production-access');
    Route::get('custom-tracking-domain', [DocumentationGuideController::class, 'customTrackingDomain'])->name('custom-tracking-domain');
    Route::get('email-templates', [DocumentationGuideController::class, 'emailTemplates'])->name('email-templates');
    Route::get('mcp-server', [DocumentationGuideController::class, 'mcpServer'])->name('mcp-server');
});
// `Route::permanentRedirect()` also registers QUERY, which Wayfinder has no type for.
Route::get('documentation/release-notes', fn () => redirect()->away('https://byomailer.com/docs/getting-started/release-notes', 301))
    ->name('documentation.release-notes');

Route::get('dashboard', DashboardController::class)
    ->middleware(['auth', EnsureUserIsActive::class, 'verified'])
    ->name('dashboard');

Route::get('reputation', ReputationController::class)
    ->middleware(['auth', EnsureUserIsActive::class, 'verified'])
    ->name('reputation.show');

Route::get('invites/{user:uuid}', [InviteController::class, 'show'])->name('invites.show');
Route::post('invites/{user:uuid}', [InviteController::class, 'store'])->name('invites.store');

// Contact-facing links have no authenticated user. The mail in the link gives the tenant.
Route::middleware(SetTenantFromContactMailLink::class)->group(function (): void {
    Route::get('unsubscribe/{contactMail}', UnsubscribeController::class)
        ->name('contact-mails.unsubscribe');

    Route::post('unsubscribe/{contactMail}', ConfirmUnsubscribeController::class)
        ->name('contact-mails.confirm-unsubscribe');

    // RFC 8058. Mailbox providers POST here from the List-Unsubscribe header with
    // no CSRF token, so this path is exempt in bootstrap/app.php. Legacy clients
    // that GET the header URL land on the normal confirmation page instead.
    Route::post('one-click-unsubscribe/{contactMail}', OneClickUnsubscribeController::class)
        ->name('contact-mails.one-click-unsubscribe');

    // Older clients GET the List-Unsubscribe URL instead of posting to it. Serve
    // them the same confirmation page, which changes nothing on its own.
    // `Route::redirect` cannot be used here: it registers for ANY method and would
    // overwrite the POST route above.
    Route::get('one-click-unsubscribe/{contactMail}', UnsubscribeController::class);

    // Same split as unsubscribe above, for the same reason: a GET carries no CSRF
    // protection, so it must not put an opted-out contact back on the list.
    Route::get('resubscribe/{contactMail}', ResubscribeController::class)
        ->name('contact-mails.resubscribe.show');

    Route::post('resubscribe/{contactMail}', ConfirmResubscribeController::class)
        ->name('contact-mails.resubscribe');

    // Same split as unsubscribe above. Mail scanners open each GET link, so the
    // GET only shows the page and the contact confirms the opt-in with a POST.
    Route::get('opt-in/{contactMail}', OptInController::class)
        ->name('contact-mails.opt-in.show');

    Route::post('opt-in/{contactMail}', ConfirmOptInController::class)
        ->name('contact-mails.opt-in');

    Route::get('preferences/{contactMail}', [PreferenceCenterController::class, 'show'])
        ->name('preferences.show')
        ->middleware('signed');

    Route::patch('preferences/{contactMail}', [PreferenceCenterController::class, 'update'])
        ->name('preferences.update');
    Route::delete('preferences/{contactMail}', [PreferenceCenterController::class, 'destroy'])
        ->name('preferences.destroy');
    Route::post('preferences/{contactMail}/resubscribe', [PreferenceCenterController::class, 'resubscribe'])
        ->name('preferences.resubscribe');
});

Route::post('webhooks/ses', SesWebhookController::class)->name('webhooks.ses');

Route::middleware(['auth', EnsureUserIsActive::class, 'verified', EnforceAccessLevel::class])->group(function () {
    Route::post('editor/images', [EditorImageController::class, 'store'])->name('editor.images.store');

    Route::get('gallery/images', [GalleryImageController::class, 'index'])->name('gallery.images.index');
    Route::post('gallery/images', [GalleryImageController::class, 'store'])->name('gallery.images.store');

    Route::get('lists', [MailListController::class, 'index'])->name('lists.index');
    Route::get('lists/create', [MailListController::class, 'create'])->name('lists.create');
    Route::post('lists', [MailListController::class, 'store'])->name('lists.store');
    Route::get('lists/{list}', [MailListController::class, 'show'])->name('lists.show');
    Route::get('lists/{list}/edit', [MailListController::class, 'edit'])->name('lists.edit');
    Route::put('lists/{list}', [MailListController::class, 'update'])->name('lists.update');
    Route::post('lists/import', MailListImportController::class)->name('lists.import');

    Route::get('segments', [SegmentController::class, 'index'])->name('segments.index');
    Route::get('segments/create', [SegmentController::class, 'create'])->name('segments.create');
    Route::post('segments', [SegmentController::class, 'store'])->name('segments.store');
    Route::get('segments/{segment}', [SegmentController::class, 'show'])->name('segments.show');
    Route::get('segments/{segment}/edit', [SegmentController::class, 'edit'])->name('segments.edit');
    Route::put('segments/{segment}', [SegmentController::class, 'update'])->name('segments.update');
    Route::patch('segments/{segment}/archive', SegmentArchiveController::class)->name('segments.archive');

    Route::post('lists/{list}/topics', [TopicController::class, 'store'])->name('lists.topics.store');
    Route::put('lists/{list}/topics/{topic}', [TopicController::class, 'update'])->name('lists.topics.update');
    Route::delete('lists/{list}/topics/{topic}', [TopicController::class, 'destroy'])->name('lists.topics.destroy');

    Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::get('contacts/create', [ContactController::class, 'create'])->name('contacts.create');
    Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::get('contacts/import', ContactImportController::class)->name('contacts.import');
    Route::get('contacts/export', ContactExportController::class)->name('contacts.export');
    Route::get('contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
    Route::patch('contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
    Route::post('contacts/{contact}/unsubscribe', [ContactController::class, 'unsubscribe'])->name('contacts.unsubscribe');
    Route::post('contacts/{contact}/subscribe', [ContactController::class, 'subscribe'])->name('contacts.subscribe');
    Route::post('contacts/{contact}/reactivate', ReactivateContactController::class)->name('contacts.reactivate');
    Route::post('contacts/{contact}/tags', [ContactTagController::class, 'sync'])->name('contacts.tags.sync');
    Route::post('contacts/{contact}/lists', [ContactListController::class, 'sync'])->name('contacts.lists.sync');
    Route::post('contacts/sequences', ContactSequenceEnrollmentController::class)->name('contacts.sequences.enroll');
    Route::post('contacts/{contact}/attributes', [ContactAttributeController::class, 'store'])->name('contacts.attributes.store');
    Route::patch('contacts/{contact}/attributes/{attributeName}', [ContactAttributeController::class, 'update'])->name('contacts.attributes.update');
    Route::delete('contacts/{contact}/attributes/{attributeName}', [ContactAttributeController::class, 'destroy'])->name('contacts.attributes.destroy');

    Route::get('attributes', [AttributeDefaultController::class, 'index'])->name('attributes.index');
    Route::patch('attributes/{attribute}', [AttributeDefaultController::class, 'update'])->name('attributes.update');

    Route::get('tags', [TagController::class, 'index'])->name('tags.index');
    Route::post('tags', [TagController::class, 'store'])->name('tags.store');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::get('email-templates/list', EmailTemplateListController::class)->name('email-templates.list');
    Route::get('email-templates', [EmailTemplateController::class, 'index'])->name('email-templates.index');
    Route::get('email-templates/create', [EmailTemplateController::class, 'create'])->name('email-templates.create');
    Route::post('email-templates', [EmailTemplateController::class, 'store'])->name('email-templates.store');
    Route::post('email-templates/from-starter/{starter}', [EmailTemplateController::class, 'startFromStarter'])->name('email-templates.from-starter');
    Route::post('email-templates/from-blank', [EmailTemplateController::class, 'startBlank'])->name('email-templates.from-blank');
    Route::get('email-templates/{emailTemplate}/edit', [EmailTemplateController::class, 'edit'])->name('email-templates.edit');
    Route::put('email-templates/{emailTemplate}', [EmailTemplateController::class, 'update'])->name('email-templates.update');
    Route::patch('email-templates/{emailTemplate}/default', [EmailTemplateController::class, 'setDefault'])->name('email-templates.set-default');
    Route::post('email-templates/{emailTemplate}/archive', [EmailTemplateArchiveController::class, 'store'])->name('email-templates.archive.store');
    Route::delete('email-templates/{emailTemplate}/archive', [EmailTemplateArchiveController::class, 'destroy'])->name('email-templates.archive.destroy');
    Route::post('email-templates/{emailTemplate}/duplicate', DuplicateEmailTemplateController::class)->name('email-templates.duplicate');

    Route::get('blocks/list', BlockListController::class)->name('blocks.list');
    Route::get('blocks', [BlockController::class, 'index'])->name('blocks.index');
    Route::get('blocks/create', [BlockController::class, 'create'])->name('blocks.create');
    Route::post('blocks', [BlockController::class, 'store'])->name('blocks.store');
    Route::get('blocks/{block}/edit', [BlockController::class, 'edit'])->name('blocks.edit');
    Route::put('blocks/{block}', [BlockController::class, 'update'])->name('blocks.update');
    Route::delete('blocks/{block}', [BlockController::class, 'destroy'])->name('blocks.destroy');

    Route::get('email-logs', [EmailLogController::class, 'index'])->name('email-logs.index');
    Route::get('email-logs/{emailLogId}', [EmailLogController::class, 'show'])->name('email-logs.show');

    Route::get('newsletters', [NewsletterController::class, 'index'])->name('newsletters.index');
    Route::get('newsletters/create', [NewsletterController::class, 'create'])->name('newsletters.create');
    Route::post('newsletters/contacts/preview', [NewsletterController::class, 'previewContacts'])
        ->withoutMiddleware(EnforceAccessLevel::class)
        ->middleware(EnforceReadAccess::class)
        ->name('newsletters.contacts.preview');
    Route::get('newsletters/{newsletter}', [NewsletterController::class, 'show'])->name('newsletters.show');
    Route::delete('newsletters/{newsletter}', [NewsletterController::class, 'destroy'])->name('newsletters.destroy');
    Route::get('newsletters/{newsletter}/export', NewsletterMailExportController::class)->name('newsletters.export');
    Route::post('newsletters/{newsletter}/projects', [NewsletterProjectController::class, 'sync'])->name('newsletters.projects.sync');
    Route::patch('newsletters/{newsletter}/title', [NewsletterController::class, 'updateTitle'])->name('newsletters.title.update');
    Route::get('newsletters/{newsletter}/content', [NewsletterController::class, 'showContent'])
        ->name('newsletters.content.show');
    Route::patch('newsletters/{newsletter}/content', [NewsletterController::class, 'updateContent'])
        ->name('newsletters.content.update');
    Route::get('newsletters/{newsletter}/variables', [NewsletterVariablesController::class, 'show'])
        ->name('newsletters.variables.show');
    Route::patch('newsletters/{newsletter}/variables', [NewsletterVariablesController::class, 'update'])
        ->name('newsletters.variables.update');
    Route::get('newsletters/{newsletter}/filters', [NewsletterController::class, 'showFilters'])
        ->name('newsletters.filters.show');
    Route::patch('newsletters/{newsletter}/filters', [NewsletterController::class, 'updateFilters'])
        ->name('newsletters.filters.update');
    Route::get('newsletters/{newsletter}/preview', [NewsletterController::class, 'showPreview'])
        ->name('newsletters.preview.show');
    Route::get('newsletters/{newsletter}/schedule', [NewsletterController::class, 'showSchedule'])
        ->name('newsletters.schedule.show');
    Route::post('newsletters/{newsletter}/schedule', [NewsletterController::class, 'schedule'])
        ->name('newsletters.schedule');
    Route::delete('newsletters/{newsletter}/schedule', NewsletterUnscheduleController::class)
        ->name('newsletters.unschedule');
    Route::post('newsletters/{newsletter}/test-mail', NewsletterTestMailController::class)
        ->middleware('throttle:newsletter-test-mail')
        ->name('newsletters.test-mail');
    Route::post('newsletters/{newsletter}/ab-winner', NewsletterAbWinnerController::class)
        ->name('newsletters.ab-winner');

    Route::get('automations', [AutomationController::class, 'index'])->name('automations.index');
    Route::get('automations/create', [AutomationController::class, 'create'])->name('automations.create');
    Route::post('automations', [AutomationController::class, 'store'])->name('automations.store');
    Route::get('automations/{automation}/edit', [AutomationController::class, 'edit'])->name('automations.edit');
    Route::put('automations/{automation}', [AutomationController::class, 'update'])->name('automations.update');
    Route::post('automations/{automation}/projects', [AutomationProjectController::class, 'sync'])->name('automations.projects.sync');
    Route::patch('automations/{automation}/status', AutomationStatusController::class)->name('automations.status.update');
    Route::delete('automations/{automation}', [AutomationController::class, 'destroy'])->name('automations.destroy');

    Route::get('visual-automations', [VisualAutomationController::class, 'index'])->name('visual-automations.index');
    Route::get('visual-automations/create', [VisualAutomationController::class, 'create'])->name('visual-automations.create');
    Route::post('visual-automations', [VisualAutomationController::class, 'store'])->name('visual-automations.store');
    Route::get('visual-automations/{visualAutomation}', [VisualAutomationController::class, 'show'])->name('visual-automations.show');
    Route::put('visual-automations/{visualAutomation}', [VisualAutomationController::class, 'update'])->name('visual-automations.update');
    Route::get('visual-automations/{visualAutomation}/contacts', [VisualAutomationContactController::class, 'index'])
        ->name('visual-automations.contacts.index');
    Route::get('visual-automations/{visualAutomation}/nodes/{nodeId}/contacts', [VisualAutomationNodeContactController::class, 'index'])
        ->name('visual-automations.nodes.contacts.index');
    Route::post('visual-automations/{visualAutomation}/projects', [VisualAutomationProjectController::class, 'sync'])->name('visual-automations.projects.sync');
    Route::patch('visual-automations/{visualAutomation}/status', VisualAutomationStatusController::class)->name('visual-automations.status.update');
    Route::delete('visual-automations/{visualAutomation}', [VisualAutomationController::class, 'destroy'])->name('visual-automations.destroy');

    Route::get('sequences', [SequenceController::class, 'index'])->name('sequences.index');
    Route::get('sequences/create', [SequenceController::class, 'create'])->name('sequences.create');
    Route::post('sequences', [SequenceController::class, 'store'])->name('sequences.store');
    Route::patch('sequences/{sequence}', [SequenceController::class, 'update'])->name('sequences.update');
    Route::get('sequences/{sequence}', [SequenceController::class, 'show'])->name('sequences.show');
    Route::post('sequences/{sequence}/projects', [SequenceProjectController::class, 'sync'])->name('sequences.projects.sync');
    Route::get('sequences/{sequence}/steps/{sequenceStep}', [SequenceStepController::class, 'show'])
        ->name('sequences.steps.show');
    Route::get('sequences/{sequence}/contacts', [SequenceContactController::class, 'index'])
        ->name('sequences.contacts.index');
    Route::get('sequences/{sequence}/steps/{sequenceStep}/contacts', [SequenceStepContactController::class, 'index'])
        ->name('sequences.steps.contacts.index');
    Route::get('sequences/{sequence}/steps/{sequenceStep}/contact-mails/export', SequenceStepContactMailExportController::class)
        ->name('sequences.steps.contact-mails.export');
    Route::patch('sequences/{sequence}/status', SequenceStatusController::class)->name('sequences.status.update');
    Route::patch('sequences/{sequence}/filters', [SequenceController::class, 'updateFilters'])->name('sequences.filters.update');
    Route::patch('sequences/{sequence}/variables', [SequenceController::class, 'updateVariables'])->name('sequences.variables.update');
    Route::post('sequences/{sequence}/steps', [SequenceStepController::class, 'store'])->name('sequences.steps.store');
    Route::patch('sequences/{sequence}/steps/{sequenceStep}', [SequenceStepController::class, 'update'])->name('sequences.steps.update');
    Route::delete('sequences/{sequence}/steps/{sequenceStep}', [SequenceStepController::class, 'destroy'])->name('sequences.steps.destroy');
    Route::patch('sequences/{sequence}/steps/{sequenceStep}/status', SequenceStepStatusController::class)
        ->name('sequences.steps.status.update');
    Route::patch('sequences/{sequence}/steps/{sequenceStep}/filters', SequenceStepFiltersController::class)
        ->name('sequences.steps.filters.update');
    Route::post('sequences/{sequence}/steps/{sequenceStep}/test-mail', SequenceStepTestMailController::class)
        ->middleware('throttle:newsletter-test-mail')
        ->name('sequences.steps.test-mail');

    Route::get('contact-mails/{contactMail}', [ContactMailController::class, 'show'])
        ->name('contact-mails.show');

    Route::get('contact-imports/{contactImport}/status', ContactImportStatusController::class)
        ->name('contact-imports.status');

    Route::prefix('onboarding')->name('onboarding.')->group(function () {
        Route::get('aws', [AwsOnboardingController::class, 'show'])->name('aws');
        Route::post('aws/credentials', [AwsOnboardingController::class, 'storeCredentials'])->name('aws.credentials');
    });
});

// The admin area of the instance. Everyone except the owner gets a 404.
Route::middleware([EnsureUserIsOwner::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', fn () => redirect('/admin/users'))->name('index');
    Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('users/{user}/deactivation', [UserDeactivationController::class, 'store'])->name('users.deactivation.store');
    Route::delete('users/{user}/deactivation', [UserDeactivationController::class, 'destroy'])->name('users.deactivation.destroy');
    Route::get('settings', [AdminSettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [AdminSettingsController::class, 'update'])->name('settings.update');
});

require __DIR__.'/settings.php';
