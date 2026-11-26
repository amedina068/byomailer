<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateMailList;
use App\Concerns\MailListValidationRules;
use App\Mcp\Concerns\ConfirmsLiveEdits;
use App\Mcp\Concerns\FormatsMailLists;
use App\Models\MailList;
use App\Models\User;
use App\Support\AudienceUsage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update the name and the opt-in settings of a mailing list, with the same rules as the dashboard. Only the fields that you pass change. The other fields keep their current values. On a list with opt-in, a contact that joins the list gets the opt-in email and counts for audiences only after the confirmation. When opt-in is on, the list needs opt_in_subject, opt_in_html_content and opt_in_email_identity_id. The opt-in email fields are saved only when opt-in is on. A change of opt_in_enabled changes which contacts get the mail that selects the list, so it is two calls. The first call saves nothing and returns a preview with the affected contacts and the items that select the list. Show the preview to the user. After the user confirms, call the tool again with the same arguments and confirm_live set to true. Never set confirm_live without that confirmation. A change of the name or of the opt-in email needs no confirmation. Find list ids with list-lists and sender identity ids with list-email-identities-tool.')]
class UpdateListTool extends Tool
{
    use ConfirmsLiveEdits;
    use FormatsMailLists;
    use MailListValidationRules;

    /**
     * The list fields that the tool can change.
     */
    private const FIELDS = ['name', 'opt_in_enabled', 'opt_in_subject', 'opt_in_html_content', 'opt_in_email_identity_id'];

    protected string $name = 'update-list';

    public function handle(Request $request, UpdateMailList $updateMailList): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'list_id' => ['required', 'integer'],
            ...$this->confirmLiveValidationRules(),
        ]);

        $list = MailList::query()->with('message')->find($validated['list_id']);

        if ($list === null) {
            return Response::error("No list found with id {$validated['list_id']}.");
        }

        $data = Validator::validate(
            [...$this->currentValues($list), ...$request->all(self::FIELDS)],
            [...$this->mailListRules($user->client_id, $list->id), ...$this->mailListOptInRules($user->client_id)],
        );
        $data['opt_in_enabled'] = (bool) $data['opt_in_enabled'];

        if ($data['opt_in_enabled'] !== (bool) $list->opt_in_enabled && ! $this->confirmsLive($validated)) {
            return $this->statusChangePreview($this->optInPreview($list, $data['opt_in_enabled']));
        }

        $refusal = DB::transaction(function () use ($user, $list, $data, $updateMailList): ?Response {
            $changed = $this->statusChangedSinceCheck($list, 'opt_in_enabled', "The opt-in setting of list #{$list->id}");

            if ($changed !== null) {
                return $changed;
            }

            $updateMailList->handle($user, $list, $data);

            return null;
        });

        if ($refusal !== null) {
            return $refusal;
        }

        return Response::structured([
            ...$this->formatMailList($list->refresh()->load('message')),
            'applied' => true,
            'message' => "List #{$list->id} updated.",
        ]);
    }

    /**
     * The values of the list before the change. The tool validates them
     * together with the passed fields, so the rules of the dashboard apply
     * to the complete list.
     *
     * @return array{name: string, opt_in_enabled: bool, opt_in_subject: string|null, opt_in_html_content: string|null, opt_in_email_identity_id: int|null}
     */
    private function currentValues(MailList $list): array
    {
        return [
            'name' => $list->name,
            'opt_in_enabled' => (bool) $list->opt_in_enabled,
            'opt_in_subject' => $list->message?->subject,
            'opt_in_html_content' => $list->message?->html_content,
            'opt_in_email_identity_id' => $list->message?->email_identity_id,
        ];
    }

    /**
     * Describe a change of the opt-in setting without saving it.
     *
     * @return array<string, mixed>
     */
    private function optInPreview(MailList $list, bool $enable): array
    {
        $contacts = $list->contacts()->count();
        $unconfirmed = $list->contacts()->wherePivotNull('opted_in_at')->count();
        $usage = AudienceUsage::ofList($list);

        $effects = $enable ? [
            "{$unconfirmed} of the {$contacts} contacts on the list did not confirm an opt-in email. After the change, they do not count as members of the list, and the mail that selects the list does not go to them.",
            'No opt-in email goes to the contacts that are on the list now.',
            'A contact that joins the list after the change gets the opt-in email and counts only after the confirmation.',
        ] : [
            "{$unconfirmed} of the {$contacts} contacts on the list did not confirm the opt-in email. After the change, they count as members of the list, and the mail that selects the list can go to them.",
            'A contact that joins the list after the change counts at once and gets no opt-in email.',
        ];

        if (AudienceUsage::isInUse($usage)) {
            $effects[] = 'These items select the list or one of its topics, so their audience changes: '.AudienceUsage::describe($usage).'.';
        }

        return [
            ...$this->formatMailList($list),
            'change' => $enable ? 'enable_opt_in' : 'disable_opt_in',
            'new_opt_in_enabled' => $enable,
            'contacts_count' => $contacts,
            'unconfirmed_contacts' => $unconfirmed,
            'used_by' => $usage,
            'effects' => $effects,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()
                ->description('Id of the list. Find list ids with list-lists.')
                ->required(),
            'name' => $schema->string()
                ->description('New name of the list. It must be unique in the account.'),
            'opt_in_enabled' => $schema->boolean()
                ->description('true turns on opt-in, false turns it off. A change needs the preview and confirm_live.'),
            'opt_in_subject' => $schema->string()
                ->description('Subject of the opt-in email. Necessary when opt-in is on.'),
            'opt_in_html_content' => $schema->string()
                ->description('HTML body of the opt-in email. Put the {{ opt_in_url }} link in it. Necessary when opt-in is on.'),
            'opt_in_email_identity_id' => $schema->integer()
                ->description('Id of the sender identity of the opt-in email. Find ids with list-email-identities-tool. Necessary when opt-in is on.'),
            ...$this->confirmLiveSchema($schema),
        ];
    }
}
