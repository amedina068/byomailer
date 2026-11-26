<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddContactToListTool;
use App\Mcp\Tools\AddTagsToContactTool;
use App\Mcp\Tools\AutomationDetailsTool;
use App\Mcp\Tools\BulkUpdateContactsTool;
use App\Mcp\Tools\CalculateNewsletterContactsTool;
use App\Mcp\Tools\CheckEmailIdentityLinkDomainTool;
use App\Mcp\Tools\ContactMailDetailsTool;
use App\Mcp\Tools\CreateAutomationTool;
use App\Mcp\Tools\CreateContactTool;
use App\Mcp\Tools\CreateEmailIdentityTool;
use App\Mcp\Tools\CreateEmailTemplateTool;
use App\Mcp\Tools\CreateListTool;
use App\Mcp\Tools\CreateNewsletterTool;
use App\Mcp\Tools\CreateProjectTool;
use App\Mcp\Tools\CreateSegmentTool;
use App\Mcp\Tools\CreateSequenceStepTool;
use App\Mcp\Tools\CreateSequenceTool;
use App\Mcp\Tools\CreateTagTool;
use App\Mcp\Tools\CreateTopicTool;
use App\Mcp\Tools\DeleteProjectTool;
use App\Mcp\Tools\DeleteTagTool;
use App\Mcp\Tools\DeleteTopicTool;
use App\Mcp\Tools\EmailIdentityDetailsTool;
use App\Mcp\Tools\EmailTemplateContentTool;
use App\Mcp\Tools\ExportSequenceStepContactMailsTool;
use App\Mcp\Tools\FindContactTool;
use App\Mcp\Tools\ListAutomationsTool;
use App\Mcp\Tools\ListContactAttributesTool;
use App\Mcp\Tools\ListContactMailsTool;
use App\Mcp\Tools\ListContactsTool;
use App\Mcp\Tools\ListEmailIdentitiesTool;
use App\Mcp\Tools\ListEmailTemplatesTool;
use App\Mcp\Tools\ListListsTool;
use App\Mcp\Tools\ListNewslettersTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListSegmentsTool;
use App\Mcp\Tools\ListSequenceStepContactMailsTool;
use App\Mcp\Tools\ListSequencesTool;
use App\Mcp\Tools\ListTagsTool;
use App\Mcp\Tools\NewsletterContentTool;
use App\Mcp\Tools\NewsletterStatsTool;
use App\Mcp\Tools\RefreshEmailIdentityStatusTool;
use App\Mcp\Tools\RemoveContactFromListTool;
use App\Mcp\Tools\RenameTagTool;
use App\Mcp\Tools\ScheduleNewsletterTool;
use App\Mcp\Tools\SendNewsletterTestMailTool;
use App\Mcp\Tools\SendSequenceStepTestMailTool;
use App\Mcp\Tools\SequenceContentTool;
use App\Mcp\Tools\SequenceStatsTool;
use App\Mcp\Tools\SequenceStepContentTool;
use App\Mcp\Tools\SequenceStepStatsTool;
use App\Mcp\Tools\SetAutomationStatusTool;
use App\Mcp\Tools\SetSegmentArchivedTool;
use App\Mcp\Tools\SetSequenceStatusTool;
use App\Mcp\Tools\SetSequenceStepStatusTool;
use App\Mcp\Tools\UpdateAccountProfileTool;
use App\Mcp\Tools\UpdateAutomationTool;
use App\Mcp\Tools\UpdateContactAttributeDefaultTool;
use App\Mcp\Tools\UpdateEmailIdentityLinkDomainTool;
use App\Mcp\Tools\UpdateEmailIdentityReplyToTool;
use App\Mcp\Tools\UpdateEmailIdentityTrackingTool;
use App\Mcp\Tools\UpdateEmailTemplateTool;
use App\Mcp\Tools\UpdateListTool;
use App\Mcp\Tools\UpdateNewsletterTool;
use App\Mcp\Tools\UpdateProjectTool;
use App\Mcp\Tools\UpdateSegmentTool;
use App\Mcp\Tools\UpdateSequenceStepTool;
use App\Mcp\Tools\UpdateSequenceTool;
use App\Mcp\Tools\UpdateTopicTool;
use App\Mcp\Tools\ViewAccountProfileTool;
use App\Mcp\Tools\ViewAutomationOptionsTool;
use App\Mcp\Tools\ViewNewsletterFiltersTool;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ReflectionClass;

#[Name('BYOMailer')]
#[Version('1.0.0')]
#[Instructions('Access to the authenticated account\'s BYOMailer campaigns. Use the list tools to discover newsletter and sequence ids, then read stats (delivery and engagement metrics) or content (subject and body). To compose a newsletter: call view-newsletter-filters-tool to learn the available audience filters, calculate-newsletter-contacts-tool to preview the recipient count, create-newsletter-tool to save a draft (drafts are never auto-sent), update-newsletter-tool to edit a draft or scheduled newsletter (a newsletter that is sending or sent is locked), and send-newsletter-test-mail-tool to preview a draft in your own inbox. To build an automated drip campaign: call create-sequence-tool to save a draft sequence (it accepts sequence filters in the view-newsletter-filters-tool format, so only matching contacts receive it), update-sequence-tool to rename a sequence or replace its filters, then create-sequence-step-tool for each email in it, update-sequence-step-tool to edit a step, and send-sequence-step-test-mail-tool to preview a single step in your own inbox. Both step tools accept step filters in the view-newsletter-filters-tool format: a contact who does not match them skips only that step. Sequences and steps are always created as drafts. Content variables are the default values of the {{ variable }} placeholders: set them with content_variables on create-newsletter-tool, update-newsletter-tool, create-sequence-tool and update-sequence-tool, and read them (with the detected variables) in newsletter-content-tool and sequence-content-tool. Live records affect real contacts now: a scheduled newsletter, a live sequence, a live step and a live automation. The update tools refuse to change a live record until you show the user the change, the user confirms it, and you call the tool again with confirm_live set to true. To go live or stop, use schedule-newsletter (schedule, reschedule or unschedule a newsletter, never an immediate send), set-sequence-status, set-sequence-step-status and set-automation-status. They run the same checks as the dashboard. A missing {{ unsubscribe_url }} link or a missing postal address never blocks a status change. The preview and the result list them in warnings. Show the warnings to the user. Each status change is two calls: the first call saves nothing and returns a preview of the change and the contacts and mails it affects, you show that preview to the user, and after the user confirms you call the tool again with the same arguments and confirm_live set to true. Never set confirm_live without that confirmation from the user. A sequence sends only through its live steps, so activate the sequence and its steps. Use create-tag-tool to add a tag (tag names are unique per account) for labelling contacts and building audiences. list-tags, rename-tag and delete-tag manage tags. list-contact-attributes and update-contact-attribute-default manage the default values of the contact attribute keys. list-projects, create-project, update-project and delete-project manage projects. delete-tag and delete-project also use two calls: a preview first, then confirm_live after the user confirms. list-segments, create-segment, update-segment and set-segment-archived manage saved segments, and update-segment needs confirm_live when a live newsletter or sequence uses the segment. To work with contacts: find-contact looks up one person by exact email, including unsubscribed contacts; create-contact adds or reuses a contact and can set a name, tag names, and one list; add-contact-to-list puts an existing contact on another list; add-tags-to-contact labels them; remove-contact-from-list takes them off one list. list-contacts lists the contacts with the view-newsletter-filters-tool filters and a search, page by page with after_id. bulk-update-contacts changes up to 100 contacts in one call: name, add and remove tags, set and remove custom attributes, and add to and remove from lists. Each item reports updated or failed on its own. Adding a contact to a list that requires opt-in queues the opt-in email. Find list ids with view-newsletter-filters-tool. Manage mailing lists and their topics with list-lists, create-list, update-list, create-topic, update-topic and delete-topic (an opt-in change and a topic delete need the preview and confirm_live). Sender identities (verified sending domains) are managed with list-email-identities-tool, email-identity-details-tool (includes the DNS records to publish), create-email-identity-tool (registers the domain in the account\'s own AWS SES; requires AWS credentials configured in the dashboard), refresh-email-identity-status-tool (re-checks verification with AWS after DNS changes) and update-email-identity-tracking-tool (toggles open/click tracking). Identities cannot be deleted through this API. Set or clear the Reply-To address and the custom link domain with update-email-identity-reply-to-tool and update-email-identity-link-domain-tool, then verify the domain with check-email-identity-link-domain-tool. Read and edit email templates with list-email-templates-tool, email-template-content-tool, create-email-template-tool and update-email-template-tool, which needs confirm_live for a template that live records use. Automations (when a contact joins a list, gets a tag, finishes a sequence or has an attribute change, then add or remove tags, lists or a sequence) are managed with list-automations, automation-details, view-automation-options (condition types, action types and the ids to use), create-automation and update-automation. Automations are always created paused, and a live automation can be edited only with confirm_live. A paused automation can add contacts to a draft sequence, but it can go live only after that sequence is live, because a live automation drops each contact it cannot enroll. Automations cannot be deleted through this API. Inspect recipients with list-sequence-step-contact-mails-tool or list-contact-mails-tool (filters, sorting and pagination), and contact-mail-details-tool for individual delivery events, content and clicks. Use export-sequence-step-contact-mails-tool for an authenticated CSV download URL covering all matching mails. Read the postal address, the timezone and the sunset rule of the account with view-account-profile-tool, and change them with update-account-profile-tool. All data is scoped to the authenticating token\'s account.')]
class ByoMailerServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListNewslettersTool::class,
        NewsletterStatsTool::class,
        NewsletterContentTool::class,
        ViewNewsletterFiltersTool::class,
        CalculateNewsletterContactsTool::class,
        CreateNewsletterTool::class,
        UpdateNewsletterTool::class,
        SendNewsletterTestMailTool::class,
        ScheduleNewsletterTool::class,
        ListSequencesTool::class,
        CreateSequenceTool::class,
        UpdateSequenceTool::class,
        CreateSequenceStepTool::class,
        UpdateSequenceStepTool::class,
        SetSequenceStatusTool::class,
        SetSequenceStepStatusTool::class,
        SendSequenceStepTestMailTool::class,
        SequenceStatsTool::class,
        SequenceContentTool::class,
        SequenceStepContentTool::class,
        SequenceStepStatsTool::class,
        ListSequenceStepContactMailsTool::class,
        ExportSequenceStepContactMailsTool::class,
        ListContactMailsTool::class,
        ContactMailDetailsTool::class,
        CreateTagTool::class,
        CreateProjectTool::class,
        CreateSegmentTool::class,
        DeleteProjectTool::class,
        DeleteTagTool::class,
        ListContactAttributesTool::class,
        ListProjectsTool::class,
        ListSegmentsTool::class,
        ListTagsTool::class,
        RenameTagTool::class,
        SetSegmentArchivedTool::class,
        UpdateContactAttributeDefaultTool::class,
        UpdateProjectTool::class,
        UpdateSegmentTool::class,
        FindContactTool::class,
        ListContactsTool::class,
        CreateContactTool::class,
        AddContactToListTool::class,
        AddTagsToContactTool::class,
        RemoveContactFromListTool::class,
        BulkUpdateContactsTool::class,
        ListListsTool::class,
        CreateListTool::class,
        UpdateListTool::class,
        CreateTopicTool::class,
        UpdateTopicTool::class,
        DeleteTopicTool::class,
        ListEmailIdentitiesTool::class,
        EmailIdentityDetailsTool::class,
        CreateEmailIdentityTool::class,
        RefreshEmailIdentityStatusTool::class,
        UpdateEmailIdentityTrackingTool::class,
        UpdateEmailIdentityReplyToTool::class,
        UpdateEmailIdentityLinkDomainTool::class,
        CheckEmailIdentityLinkDomainTool::class,
        ListEmailTemplatesTool::class,
        EmailTemplateContentTool::class,
        CreateEmailTemplateTool::class,
        UpdateEmailTemplateTool::class,
        ListAutomationsTool::class,
        AutomationDetailsTool::class,
        ViewAutomationOptionsTool::class,
        CreateAutomationTool::class,
        UpdateAutomationTool::class,
        SetAutomationStatusTool::class,
        ViewAccountProfileTool::class,
        UpdateAccountProfileTool::class,
    ];

    /**
     * The context lists and runs only the tools that the user and the token
     * can use. A tool without the IsReadOnly annotation needs write access.
     */
    public function createContext(): ServerContext
    {
        $allTools = $this->tools;
        $this->tools = $this->toolsForCurrentUser($allTools);

        try {
            return parent::createContext();
        } finally {
            $this->tools = $allTools;
        }
    }

    /**
     * @param  array<int, class-string<Tool>>  $tools
     * @return array<int, class-string<Tool>>
     */
    private function toolsForCurrentUser(array $tools): array
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->hasReadAccess()) {
            return [];
        }

        if ($user->hasWriteAccess()) {
            return $tools;
        }

        return array_values(array_filter($tools, fn (string $tool): bool => $this->isReadOnlyTool($tool)));
    }

    /**
     * @param  class-string<Tool>  $tool
     */
    private function isReadOnlyTool(string $tool): bool
    {
        $attributes = (new ReflectionClass($tool))->getAttributes(IsReadOnly::class);

        return $attributes !== [] && $attributes[0]->newInstance()->value;
    }

    /**
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        //
    ];

    /**
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        //
    ];
}
