<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CreateListTool;
use App\Mcp\Tools\CreateTopicTool;
use App\Mcp\Tools\DeleteTopicTool;
use App\Mcp\Tools\ListListsTool;
use App\Mcp\Tools\UpdateListTool;
use App\Mcp\Tools\UpdateTopicTool;
use App\Models\Contact;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_tools_use_the_documented_names(): void
    {
        $this->assertSame('list-lists', app(ListListsTool::class)->name());
        $this->assertSame('create-list', app(CreateListTool::class)->name());
        $this->assertSame('update-list', app(UpdateListTool::class)->name());
        $this->assertSame('create-topic', app(CreateTopicTool::class)->name());
        $this->assertSame('update-topic', app(UpdateTopicTool::class)->name());
        $this->assertSame('delete-topic', app(DeleteTopicTool::class)->name());
    }

    public function test_list_lists_returns_the_lists_of_the_client_with_counts_and_topics(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->teammateOf($owner)->create();
        $open = MailList::factory()->for($owner)->create(['name' => 'Open list']);
        $optIn = MailList::factory()->for($teammate)->create(['name' => 'Opt-in list', 'opt_in_enabled' => true]);
        MailList::factory()->create(['name' => 'Foreign list']);

        $contacts = Contact::factory()->for($owner)->count(3)->create();
        $open->contacts()->attach($contacts->pluck('id'));
        $optIn->contacts()->attach($contacts[0]->id, ['opted_in_at' => now()]);
        $optIn->contacts()->attach([$contacts[1]->id, $contacts[2]->id]);

        $topic = Topic::factory()->for($owner)->create(['list_id' => $open->id, 'name' => 'Product news', 'description' => 'New features']);
        $topic->contacts()->attach($contacts[0]->id, ['subscribed_at' => now()]);
        $topic->contacts()->attach($contacts[1]->id, ['subscribed_at' => now(), 'unsubscribed_at' => now()]);

        ByoMailerServer::actingAs($owner)->tool(ListListsTool::class, [])
            ->assertOk()
            ->assertDontSee('Foreign list')
            ->assertStructuredContent(fn ($json) => $json
                ->where('count', 2)
                ->where('lists.0.id', $optIn->id)
                ->where('lists.0.opt_in_enabled', true)
                ->where('lists.0.contacts_count', 3)
                ->where('lists.0.confirmed_contacts_count', 1)
                ->where('lists.0.topics', [])
                ->where('lists.1.id', $open->id)
                ->where('lists.1.opt_in_enabled', false)
                ->where('lists.1.contacts_count', 3)
                ->where('lists.1.confirmed_contacts_count', 3)
                ->where('lists.1.topics', [[
                    'id' => $topic->id,
                    'list_id' => $open->id,
                    'name' => 'Product news',
                    'description' => 'New features',
                    'subscribed_count' => 1,
                ]])
                ->etc());
    }

    public function test_list_lists_filters_by_name(): void
    {
        $user = User::factory()->create();
        MailList::factory()->for($user)->create(['name' => 'Customers']);
        MailList::factory()->for($user)->create(['name' => 'Partners']);

        ByoMailerServer::actingAs($user)->tool(ListListsTool::class, ['search' => 'custom'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('count', 1)
                ->where('lists.0.name', 'Customers')
                ->etc());
    }

    public function test_a_read_only_token_sees_list_lists_but_not_the_write_tools(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('reader', ['read'])->plainTextToken;

        $names = collect($this->withToken($token)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['per_page' => 100],
        ])->json('result.tools'))->pluck('name');

        $this->assertContains('list-lists', $names);
        $this->assertEmpty($names->intersect(['create-list', 'update-list', 'create-topic', 'update-topic', 'delete-topic']));
    }

    public function test_create_list_saves_the_list_in_the_client_of_the_user(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateListTool::class, ['name' => 'Customers'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('name', 'Customers')
                ->where('opt_in_enabled', false)
                ->where('opt_in_email', null)
                ->etc());

        $list = MailList::query()->where('name', 'Customers')->firstOrFail();

        $this->assertSame($user->client_id, $list->client_id);
        $this->assertSame($user->id, $list->user_id);
    }

    public function test_create_list_rejects_a_name_that_a_teammate_used(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->teammateOf($owner)->create();
        MailList::factory()->for($owner)->create(['name' => 'Customers']);

        ByoMailerServer::actingAs($teammate)->tool(CreateListTool::class, ['name' => 'Customers'])
            ->assertHasErrors(['The name has already been taken.']);

        $this->assertSame(1, MailList::query()->where('name', 'Customers')->count());
    }

    public function test_create_list_allows_a_name_that_another_client_used(): void
    {
        MailList::factory()->create(['name' => 'Customers']);
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateListTool::class, ['name' => 'Customers'])->assertOk();

        $this->assertSame(2, MailList::query()->withoutGlobalScopes()->where('name', 'Customers')->count());
    }

    public function test_update_list_renames_a_list_without_confirmation_and_keeps_the_opt_in(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['name' => 'Old name', 'opt_in_enabled' => true]);
        $identity = EmailIdentity::factory()->for($user)->create();
        $list->message()->create($this->optInMessage($user, $identity, 'Confirm please'));

        ByoMailerServer::actingAs($user)->tool(UpdateListTool::class, [
            'list_id' => $list->id,
            'name' => 'New name',
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('name', 'New name')
            ->where('opt_in_enabled', true)
            ->where('opt_in_email.subject', 'Confirm please')
            ->where('applied', true)
            ->etc());

        $list->refresh();
        $this->assertSame('New name', $list->name);
        $this->assertTrue($list->opt_in_enabled);
    }

    public function test_update_list_rejects_the_name_of_another_list_of_the_client(): void
    {
        $user = User::factory()->create();
        MailList::factory()->for($user)->create(['name' => 'Customers']);
        $list = MailList::factory()->for($user)->create(['name' => 'Partners']);

        ByoMailerServer::actingAs($user)->tool(UpdateListTool::class, [
            'list_id' => $list->id,
            'name' => 'Customers',
        ])->assertHasErrors(['The name has already been taken.']);

        $this->assertSame('Partners', $list->refresh()->name);
    }

    public function test_update_list_previews_the_opt_in_change_without_saving(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contacts = Contact::factory()->for($user)->count(3)->create();
        $list->contacts()->attach($contacts[0]->id, ['opted_in_at' => now()]);
        $list->contacts()->attach([$contacts[1]->id, $contacts[2]->id]);
        $newsletter = Newsletter::factory()->for($user)->withFilters([
            ['connector' => 'and', 'category' => 'lists', 'mode' => 'any', 'values' => [$list->id]],
        ])->create(['title' => 'Spring sale']);

        ByoMailerServer::actingAs($user)->tool(UpdateListTool::class, [
            'list_id' => $list->id,
            'name' => 'Renamed',
            ...$this->optInArguments($identity),
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', false)
            ->where('change', 'enable_opt_in')
            ->where('opt_in_enabled', false)
            ->where('new_opt_in_enabled', true)
            ->where('contacts_count', 3)
            ->where('unconfirmed_contacts', 2)
            ->where('used_by.newsletters', [['id' => $newsletter->id, 'name' => 'Spring sale']])
            ->etc());

        $list->refresh();
        $this->assertFalse($list->opt_in_enabled);
        $this->assertNotSame('Renamed', $list->name);
        $this->assertNull($list->message);
    }

    public function test_update_list_turns_on_the_opt_in_after_confirmation(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        ByoMailerServer::actingAs($user)->tool(UpdateListTool::class, [
            'list_id' => $list->id,
            ...$this->optInArguments($identity),
            'confirm_live' => true,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', true)
            ->where('opt_in_enabled', true)
            ->where('opt_in_email', ['subject' => 'Confirm your subscription', 'email_identity_id' => $identity->id])
            ->etc());

        $list->refresh();
        $this->assertTrue($list->opt_in_enabled);
        $this->assertSame('<p>Click {{ opt_in_url }}</p>', $list->message->html_content);
        $this->assertSame($identity->email, $list->message->from_email);
    }

    public function test_update_list_needs_the_opt_in_email_to_turn_on_the_opt_in(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        ByoMailerServer::actingAs($user)->tool(UpdateListTool::class, [
            'list_id' => $list->id,
            'opt_in_enabled' => true,
            'opt_in_html_content' => '<p>Click {{ opt_in_url }}</p>',
            'opt_in_email_identity_id' => $identity->id,
            'confirm_live' => true,
        ])->assertHasErrors(['The opt in subject field is required when opt in enabled is true.']);

        $this->assertFalse($list->refresh()->opt_in_enabled);
    }

    public function test_update_list_rejects_the_sender_identity_of_another_client(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $foreignIdentity = EmailIdentity::factory()->create();

        ByoMailerServer::actingAs($user)->tool(UpdateListTool::class, [
            'list_id' => $list->id,
            ...$this->optInArguments($foreignIdentity),
            'confirm_live' => true,
        ])->assertHasErrors(['The selected opt in email identity id is invalid.']);

        $this->assertFalse($list->refresh()->opt_in_enabled);
    }

    public function test_update_list_changes_the_opt_in_email_of_an_opt_in_list_without_confirmation(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['opt_in_enabled' => true]);
        $identity = EmailIdentity::factory()->for($user)->create();
        $list->message()->create($this->optInMessage($user, $identity, 'Old subject'));

        ByoMailerServer::actingAs($user)->tool(UpdateListTool::class, [
            'list_id' => $list->id,
            'opt_in_subject' => 'New subject',
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', true)
            ->where('opt_in_email.subject', 'New subject')
            ->etc());

        $this->assertSame('New subject', $list->refresh()->message->subject);
        $this->assertSame('<p>Old body {{ opt_in_url }}</p>', $list->message->html_content);
    }

    public function test_update_list_previews_then_turns_off_the_opt_in(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['opt_in_enabled' => true]);
        $contacts = Contact::factory()->for($user)->count(2)->create();
        $list->contacts()->attach($contacts->pluck('id'));

        ByoMailerServer::actingAs($user)->tool(UpdateListTool::class, [
            'list_id' => $list->id,
            'opt_in_enabled' => false,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', false)
            ->where('change', 'disable_opt_in')
            ->where('unconfirmed_contacts', 2)
            ->etc());

        $this->assertTrue($list->refresh()->opt_in_enabled);

        ByoMailerServer::actingAs($user)->tool(UpdateListTool::class, [
            'list_id' => $list->id,
            'opt_in_enabled' => false,
            'confirm_live' => true,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', true)
            ->where('opt_in_enabled', false)
            ->etc());

        $this->assertFalse($list->refresh()->opt_in_enabled);
    }

    public function test_update_list_does_not_find_the_list_of_another_client(): void
    {
        $user = User::factory()->create();
        $foreign = MailList::factory()->create(['name' => 'Foreign']);

        ByoMailerServer::actingAs($user)->tool(UpdateListTool::class, [
            'list_id' => $foreign->id,
            'name' => 'Taken over',
        ])->assertHasErrors(["No list found with id {$foreign->id}."]);

        $this->assertSame('Foreign', MailList::withoutGlobalScopes()->findOrFail($foreign->id)->name);
    }

    public function test_create_topic_saves_the_topic_and_subscribes_the_contacts_of_the_list(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $list->contacts()->attach($contact->id);

        ByoMailerServer::actingAs($user)->tool(CreateTopicTool::class, [
            'list_id' => $list->id,
            'name' => 'Product news',
            'description' => 'New features',
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('list_id', $list->id)
            ->where('name', 'Product news')
            ->where('description', 'New features')
            ->etc());

        $topic = Topic::query()->where('name', 'Product news')->firstOrFail();

        $this->assertSame($user->id, $topic->user_id);
        $this->assertSame($user->client_id, $topic->client_id);
        $this->assertSame([$contact->id], $topic->contacts()->pluck('contacts.id')->all());
    }

    public function test_create_topic_rejects_a_name_that_the_list_has(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        Topic::factory()->for($user)->create(['list_id' => $list->id, 'name' => 'Product news']);

        ByoMailerServer::actingAs($user)->tool(CreateTopicTool::class, [
            'list_id' => $list->id,
            'name' => 'Product news',
        ])->assertHasErrors(['The name has already been taken.']);

        $this->assertSame(1, Topic::query()->where('name', 'Product news')->count());
    }

    public function test_create_topic_allows_a_name_that_another_list_has(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        Topic::factory()->for($user)->create(['name' => 'Product news']);

        ByoMailerServer::actingAs($user)->tool(CreateTopicTool::class, [
            'list_id' => $list->id,
            'name' => 'Product news',
        ])->assertOk();

        $this->assertSame(2, Topic::query()->where('name', 'Product news')->count());
    }

    public function test_create_topic_does_not_find_the_list_of_another_client(): void
    {
        $user = User::factory()->create();
        $foreign = MailList::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateTopicTool::class, [
            'list_id' => $foreign->id,
            'name' => 'Product news',
        ])->assertHasErrors(["No list found with id {$foreign->id}."]);

        $this->assertSame(0, Topic::query()->withoutGlobalScopes()->count());
    }

    public function test_update_topic_changes_the_name_and_keeps_the_description(): void
    {
        $user = User::factory()->create();
        $topic = Topic::factory()->for($user)->create(['name' => 'Old', 'description' => 'Kept']);

        ByoMailerServer::actingAs($user)->tool(UpdateTopicTool::class, [
            'topic_id' => $topic->id,
            'name' => 'New',
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('name', 'New')
            ->where('description', 'Kept')
            ->etc());

        $topic->refresh();
        $this->assertSame('New', $topic->name);
        $this->assertSame('Kept', $topic->description);
    }

    public function test_update_topic_clears_the_description(): void
    {
        $user = User::factory()->create();
        $topic = Topic::factory()->for($user)->create(['name' => 'Kept', 'description' => 'Old']);

        ByoMailerServer::actingAs($user)->tool(UpdateTopicTool::class, [
            'topic_id' => $topic->id,
            'description' => null,
        ])->assertOk();

        $topic->refresh();
        $this->assertSame('Kept', $topic->name);
        $this->assertNull($topic->description);
    }

    public function test_update_topic_rejects_the_name_of_another_topic_of_the_list(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        Topic::factory()->for($user)->create(['list_id' => $list->id, 'name' => 'Taken']);
        $topic = Topic::factory()->for($user)->create(['list_id' => $list->id, 'name' => 'Mine']);

        ByoMailerServer::actingAs($user)->tool(UpdateTopicTool::class, [
            'topic_id' => $topic->id,
            'name' => 'Taken',
        ])->assertHasErrors(['The name has already been taken.']);

        $this->assertSame('Mine', $topic->refresh()->name);
    }

    public function test_update_topic_rejects_a_description_over_the_limit(): void
    {
        $user = User::factory()->create();
        $topic = Topic::factory()->for($user)->create(['description' => 'Old']);

        ByoMailerServer::actingAs($user)->tool(UpdateTopicTool::class, [
            'topic_id' => $topic->id,
            'description' => str_repeat('a', 1001),
        ])->assertHasErrors(['The description field must not be greater than 1000 characters.']);

        $this->assertSame('Old', $topic->refresh()->description);
    }

    public function test_update_topic_does_not_find_the_topic_of_another_client(): void
    {
        $user = User::factory()->create();
        $foreign = Topic::factory()->create(['name' => 'Foreign']);

        ByoMailerServer::actingAs($user)->tool(UpdateTopicTool::class, [
            'topic_id' => $foreign->id,
            'name' => 'Taken over',
        ])->assertHasErrors(["No topic found with id {$foreign->id}."]);

        $this->assertSame('Foreign', Topic::withoutGlobalScopes()->findOrFail($foreign->id)->name);
    }

    public function test_delete_topic_previews_the_delete_without_saving(): void
    {
        $user = User::factory()->create();
        $topic = Topic::factory()->for($user)->create();
        $contacts = Contact::factory()->for($user)->count(3)->create();
        $topic->contacts()->attach([$contacts[0]->id, $contacts[1]->id], ['subscribed_at' => now()]);
        $topic->contacts()->attach($contacts[2]->id, ['subscribed_at' => now(), 'unsubscribed_at' => now()]);

        ByoMailerServer::actingAs($user)->tool(DeleteTopicTool::class, [
            'topic_id' => $topic->id,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', false)
            ->where('change', 'delete')
            ->where('subscribed_contacts', 2)
            ->where('unsubscribed_contacts', 1)
            ->etc());

        $this->assertModelExists($topic);
        $this->assertSame(3, $topic->contacts()->count());
    }

    public function test_delete_topic_deletes_the_topic_after_confirmation(): void
    {
        $user = User::factory()->create();
        $topic = Topic::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $topic->contacts()->attach($contact->id, ['subscribed_at' => now()]);

        ByoMailerServer::actingAs($user)->tool(DeleteTopicTool::class, [
            'topic_id' => $topic->id,
            'confirm_live' => true,
        ])->assertOk()->assertStructuredContent(fn ($json) => $json
            ->where('applied', true)
            ->where('id', $topic->id)
            ->etc());

        $this->assertModelMissing($topic);
        $this->assertModelExists($contact);
        $this->assertDatabaseMissing('contact_topic', ['topic_id' => $topic->id]);
    }

    public function test_delete_topic_refuses_a_topic_that_a_newsletter_uses(): void
    {
        $user = User::factory()->create();
        $topic = Topic::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->withFilters([
            ['connector' => 'and', 'category' => 'topics', 'mode' => 'any', 'values' => [$topic->id]],
        ])->create(['title' => 'Spring sale']);

        foreach ([false, true] as $confirm) {
            ByoMailerServer::actingAs($user)->tool(DeleteTopicTool::class, [
                'topic_id' => $topic->id,
                'confirm_live' => $confirm,
            ])->assertHasErrors(["Topic #{$topic->id} was not deleted. These items use the topic: newsletter \"Spring sale\" (#{$newsletter->id}). Remove the topic from them, then delete the topic."]);
        }

        $this->assertModelExists($topic);
    }

    public function test_delete_topic_does_not_find_the_topic_of_another_client(): void
    {
        $user = User::factory()->create();
        $foreign = Topic::factory()->create();

        ByoMailerServer::actingAs($user)->tool(DeleteTopicTool::class, [
            'topic_id' => $foreign->id,
            'confirm_live' => true,
        ])->assertHasErrors(["No topic found with id {$foreign->id}."]);

        $this->assertDatabaseHas('topics', ['id' => $foreign->id]);
    }

    /**
     * @return array{opt_in_enabled: bool, opt_in_subject: string, opt_in_html_content: string, opt_in_email_identity_id: int}
     */
    private function optInArguments(EmailIdentity $identity): array
    {
        return [
            'opt_in_enabled' => true,
            'opt_in_subject' => 'Confirm your subscription',
            'opt_in_html_content' => '<p>Click {{ opt_in_url }}</p>',
            'opt_in_email_identity_id' => $identity->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function optInMessage(User $user, EmailIdentity $identity, string $subject): array
    {
        return [
            'client_id' => $user->client_id,
            'user_id' => $user->id,
            'email_identity_id' => $identity->id,
            'subject' => $subject,
            'from_email' => $identity->email,
            'from_name' => 'Sender',
            'html_content' => '<p>Old body {{ opt_in_url }}</p>',
            'text_content' => 'Old body',
            'markdown_content' => '',
        ];
    }
}
