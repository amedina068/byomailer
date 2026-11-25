<?php

namespace Tests\Feature;

use App\Models\EmailIdentity;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function validHtml(string $body = '<p>Hello</p>'): string
    {
        return $body.'<p>{{ content }}</p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>';
    }

    public function test_user_can_view_templates_index(): void
    {
        $user = User::factory()->create();
        EmailTemplate::factory()->for($user)->create(['name' => 'My Template']);

        $response = $this->actingAs($user)->get(route('email-templates.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('templates/Index')
            ->has('templates.data', 1)
            ->where('templates.data.0.name', 'My Template')
            ->missing('systemTemplates')
        );
    }

    public function test_index_paginates_user_templates(): void
    {
        $user = User::factory()->create();
        EmailTemplate::factory()->for($user)->count(25)->create();

        $response = $this->actingAs($user)->get(route('email-templates.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('templates.data', 20)
        );

        $nextPage = $this->actingAs($user)->get(route('email-templates.index', ['page' => 2]));

        $nextPage->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('templates.data', 5)
        );
    }

    public function test_index_hides_system_templates(): void
    {
        $user = User::factory()->create();
        EmailTemplate::factory()->system()->create(['name' => 'System Template']);
        EmailTemplate::factory()->for($user)->create(['name' => 'User Template']);

        $response = $this->actingAs($user)->get(route('email-templates.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('templates.data', 1)
            ->where('templates.data.0.name', 'User Template')
        );
    }

    public function test_create_page_shows_starters(): void
    {
        $user = User::factory()->create();
        EmailTemplate::factory()->system()->create(['name' => 'Starter A']);

        $response = $this->actingAs($user)->get(route('email-templates.create'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('templates/Starters')
            ->has('systemTemplates')
        );
    }

    public function test_user_can_create_template(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('email-templates.store'), [
            'name' => 'Welcome Email',
            'html_content' => $this->validHtml('<p>Hello {{ name }}!</p>'),
        ]);

        $response->assertRedirectToRoute('email-templates.edit', EmailTemplate::where('name', 'Welcome Email')->first());

        $this->assertDatabaseHas('email_templates', [
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'Welcome Email',
        ]);
    }

    public function test_first_template_created_becomes_default(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('email-templates.store'), [
            'name' => 'First Template',
            'html_content' => $this->validHtml(),
        ]);

        $this->assertDatabaseHas('email_templates', [
            'name' => 'First Template',
            'client_id' => $user->client_id,
            'is_default' => true,
        ]);
    }

    public function test_second_template_created_does_not_override_default(): void
    {
        $user = User::factory()->create();
        EmailTemplate::factory()->for($user)->default()->create(['name' => 'Existing']);

        $this->actingAs($user)->post(route('email-templates.store'), [
            'name' => 'Second',
            'html_content' => $this->validHtml(),
        ]);

        $this->assertDatabaseHas('email_templates', ['name' => 'Existing', 'is_default' => true]);
        $this->assertDatabaseHas('email_templates', ['name' => 'Second', 'is_default' => false]);
    }

    public function test_create_fails_without_content_placeholder(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('email-templates.store'), [
            'name' => 'No Slot',
            'html_content' => '<p>Just text <a href="{{ unsubscribe_url }}">unsub</a></p>',
        ]);

        $response->assertSessionHasErrors('html_content');
    }

    public function test_create_fails_with_multiple_content_placeholders(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('email-templates.store'), [
            'name' => 'Two Slots',
            'html_content' => '<p>{{ content }}</p><p>{{ content }}</p><a href="{{ unsubscribe_url }}">unsub</a>',
        ]);

        $response->assertSessionHasErrors('html_content');
    }

    public function test_create_requires_unsubscribe_link_without_confirmation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('email-templates.store'), [
            'name' => 'No Unsub',
            'html_content' => '<p>{{ content }}</p>',
        ]);

        $response->assertSessionHasErrors('html_content');
    }

    public function test_create_accepts_no_unsubscribe_when_confirmed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('email-templates.store'), [
            'name' => 'No Unsub Confirmed',
            'html_content' => '<p>{{ content }}</p>',
            'confirmed_no_unsubscribe' => true,
        ]);

        $response->assertSessionDoesntHaveErrors('html_content');
        $this->assertDatabaseHas('email_templates', ['name' => 'No Unsub Confirmed']);
    }

    public function test_user_can_view_edit_page(): void
    {
        $user = User::factory()->create();
        $template = EmailTemplate::factory()->for($user)->create(['name' => 'Editable']);

        $response = $this->actingAs($user)->get(route('email-templates.edit', $template));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('templates/Edit')
            ->where('template.name', 'Editable')
            ->where('template.is_default', false)
            ->where('template.is_system', false)
        );
    }

    public function test_user_can_update_template(): void
    {
        $user = User::factory()->create();
        $template = EmailTemplate::factory()->for($user)->create(['name' => 'Old Name']);

        $response = $this->actingAs($user)->put(route('email-templates.update', $template), [
            'name' => 'New Name',
            'html_content' => $this->validHtml('<h1>Updated</h1>'),
        ]);

        $response->assertRedirect(route('email-templates.index'));

        $this->assertDatabaseHas('email_templates', [
            'id' => $template->id,
            'name' => 'New Name',
        ]);
    }

    public function test_user_can_archive_template(): void
    {
        $user = User::factory()->create();
        $template = EmailTemplate::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('email-templates.archive.store', $template));

        $response->assertRedirect(route('email-templates.index'));
        $this->assertDatabaseHas('email_templates', ['id' => $template->id]);
        $this->assertNotNull($template->fresh()->archived_at);
    }

    public function test_user_cannot_archive_default_template(): void
    {
        $user = User::factory()->create();
        $template = EmailTemplate::factory()->for($user)->default()->create();

        $response = $this->actingAs($user)->from(route('email-templates.index'))
            ->post(route('email-templates.archive.store', $template));

        $response->assertSessionHasErrors(['template']);
        $this->assertNull($template->fresh()->archived_at);
    }

    public function test_user_can_unarchive_template(): void
    {
        $user = User::factory()->create();
        $template = EmailTemplate::factory()->for($user)->create(['archived_at' => now()]);

        $this->actingAs($user)->delete(route('email-templates.archive.destroy', $template))
            ->assertRedirect(route('email-templates.index'));

        $this->assertNull($template->fresh()->archived_at);
    }

    public function test_archived_templates_hidden_from_index_by_default(): void
    {
        $user = User::factory()->create();
        $active = EmailTemplate::factory()->for($user)->create(['name' => 'Active']);
        EmailTemplate::factory()->for($user)->create(['name' => 'Archived', 'archived_at' => now()]);

        $response = $this->actingAs($user)->get(route('email-templates.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('templates.data', 1)
            ->where('templates.data.0.id', $active->id)
            ->where('archivedCount', 1)
            ->where('showingArchived', false)
        );
    }

    public function test_archived_index_view_lists_only_archived(): void
    {
        $user = User::factory()->create();
        EmailTemplate::factory()->for($user)->create(['name' => 'Active']);
        $archived = EmailTemplate::factory()->for($user)->create(['name' => 'Archived', 'archived_at' => now()]);

        $response = $this->actingAs($user)->get(route('email-templates.index', ['archived' => '1']));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('templates.data', 1)
            ->where('templates.data.0.id', $archived->id)
            ->where('showingArchived', true)
        );
    }

    public function test_picker_excludes_archived_templates(): void
    {
        $user = User::factory()->create();
        $active = EmailTemplate::factory()->for($user)->create();
        EmailTemplate::factory()->for($user)->create(['archived_at' => now()]);

        $response = $this->actingAs($user)->get(route('email-templates.list'));

        $response->assertOk();
        $ids = collect($response->json('templates.data'))->pluck('id')->all();
        $this->assertContains($active->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_applying_archived_template_is_rejected(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->create();
        $archived = EmailTemplate::factory()->for($user)->create([
            'html_content' => '<p>{{ content }}</p><a href="{{ unsubscribe_url }}">u</a>',
            'archived_at' => now(),
        ]);

        $this->actingAs($user);
        $newsletter = $user->newsletters()->create(['title' => '', 'status' => 'draft', 'filters' => []]);
        $newsletter->message()->create([
            'user_id' => $user->id,
            'subject' => '',
            'markdown_content' => '',
            'html_content' => '',
            'text_content' => '',
        ]);

        $this->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'Hi',
            'subject' => 'Hi',
            'html_content' => '<p><div data-slot="content"><p>x</p></div></p>',
            'email_template_id' => $archived->id,
            'template_slot_content' => '<p>x</p>',
        ])->assertSessionHasErrors('email_template_id');
    }

    public function test_set_default_clears_other_defaults_in_same_client(): void
    {
        $user = User::factory()->create();
        $oldDefault = EmailTemplate::factory()->for($user)->default()->create(['name' => 'Old']);
        $newDefault = EmailTemplate::factory()->for($user)->create(['name' => 'New']);

        $this->actingAs($user)->patch(route('email-templates.set-default', $newDefault));

        $this->assertDatabaseHas('email_templates', ['id' => $oldDefault->id, 'is_default' => false]);
        $this->assertDatabaseHas('email_templates', ['id' => $newDefault->id, 'is_default' => true]);
    }

    public function test_set_default_does_not_affect_other_clients(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $aDefault = EmailTemplate::factory()->for($userA)->default()->create();
        $bDefault = EmailTemplate::factory()->for($userB)->default()->create();

        $aNew = EmailTemplate::factory()->for($userA)->create();

        $this->actingAs($userA)->patch(route('email-templates.set-default', $aNew));

        $this->assertDatabaseHas('email_templates', ['id' => $aDefault->id, 'is_default' => false]);
        $this->assertDatabaseHas('email_templates', ['id' => $bDefault->id, 'is_default' => true]);
    }

    public function test_start_from_starter_clones_system_template(): void
    {
        $user = User::factory()->create();
        $starter = EmailTemplate::factory()->system()->create([
            'name' => 'Starter A',
            'html_content' => '<p>{{ content }}</p><a href="{{ unsubscribe_url }}">u</a>',
        ]);

        $response = $this->actingAs($user)->post(route('email-templates.from-starter', $starter));

        $response->assertStatus(302);

        $this->assertDatabaseHas('email_templates', [
            'client_id' => $user->client_id,
            'name' => 'Starter A',
            'html_content' => '<p>{{ content }}</p><a href="{{ unsubscribe_url }}">u</a>',
        ]);
    }

    public function test_start_blank_creates_template_with_default_skeleton(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('email-templates.from-blank'));

        $response->assertStatus(302);

        $template = EmailTemplate::where('client_id', $user->client_id)
            ->where('name', 'like', 'Untitled template%')
            ->first();

        $this->assertNotNull($template);
        $this->assertStringContainsString('{{ content }}', $template->html_content);
        $this->assertStringContainsString('{{ unsubscribe_url }}', $template->html_content);
    }

    public function test_user_cannot_edit_another_clients_template(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $template = EmailTemplate::factory()->for($userA)->create();

        $response = $this->actingAs($userB)->get(route('email-templates.edit', $template));

        $response->assertNotFound();
    }

    public function test_user_cannot_archive_another_clients_template(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $template = EmailTemplate::factory()->for($userA)->create();

        $response = $this->actingAs($userB)->post(route('email-templates.archive.store', $template));

        $response->assertNotFound();
        $this->assertNull($template->fresh()->archived_at);
    }

    public function test_user_cannot_edit_system_template(): void
    {
        $user = User::factory()->create();
        $template = EmailTemplate::factory()->system()->create();

        $response = $this->actingAs($user)->get(route('email-templates.edit', $template));

        $response->assertNotFound();
    }

    public function test_user_cannot_duplicate_another_clients_template(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $template = EmailTemplate::factory()->for($userA)->create([
            'name' => 'Secret Template',
            'html_content' => '<p>Secret {{ content }} <a href="{{ unsubscribe_url }}">u</a></p>',
        ]);

        $response = $this->actingAs($userB)->post(route('email-templates.duplicate', $template));

        $response->assertNotFound();
        $this->assertDatabaseMissing('email_templates', [
            'client_id' => $userB->client_id,
            'name' => 'Secret Template (copy)',
        ]);
    }

    public function test_user_cannot_duplicate_system_template(): void
    {
        $user = User::factory()->create();
        $template = EmailTemplate::factory()->system()->create([
            'name' => 'System Starter',
            'html_content' => '<p>{{ content }}</p><a href="{{ unsubscribe_url }}">u</a>',
        ]);

        $response = $this->actingAs($user)->post(route('email-templates.duplicate', $template));

        $response->assertNotFound();
        $this->assertDatabaseMissing('email_templates', [
            'client_id' => $user->client_id,
            'name' => 'System Starter (copy)',
        ]);
    }

    public function test_user_can_duplicate_template(): void
    {
        $user = User::factory()->create();
        $template = EmailTemplate::factory()->for($user)->create([
            'name' => 'Original',
            'html_content' => '<p>Content {{ content }} <a href="{{ unsubscribe_url }}">u</a></p>',
        ]);

        $response = $this->actingAs($user)->post(route('email-templates.duplicate', $template));

        $response->assertRedirect(route('email-templates.index'));

        $this->assertDatabaseHas('email_templates', [
            'user_id' => $user->id,
            'name' => 'Original (copy)',
        ]);
    }

    public function test_html_content_rejects_script_tags(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('email-templates.store'), [
            'name' => 'XSS Test',
            'html_content' => '<p>{{ content }}</p><a href="{{ unsubscribe_url }}">u</a><script>alert("xss")</script>',
        ]);

        $response->assertSessionHasErrors('html_content');
    }

    public function test_html_content_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('email-templates.store'), [
            'name' => 'No Content',
            'html_content' => '',
        ]);

        $response->assertSessionHasErrors('html_content');
    }

    public function test_name_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('email-templates.store'), [
            'name' => '',
            'html_content' => $this->validHtml(),
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_user_can_list_templates_as_json(): void
    {
        $user = User::factory()->create();
        EmailTemplate::factory()->for($user)->create(['name' => 'My Template']);

        $response = $this->actingAs($user)
            ->getJson(route('email-templates.list'));

        $response->assertOk()
            ->assertJsonCount(1, 'templates.data')
            ->assertJsonPath('templates.data.0.name', 'My Template')
            ->assertJsonPath('templates.next_page_url', null);
    }

    public function test_list_paginates_user_templates(): void
    {
        $user = User::factory()->create();
        EmailTemplate::factory()->for($user)->count(25)->create();

        $response = $this->actingAs($user)
            ->getJson(route('email-templates.list'));

        $response->assertOk()
            ->assertJsonCount(20, 'templates.data');

        $this->assertNotNull($response->json('templates.next_page_url'));

        $page2 = $this->actingAs($user)
            ->getJson(route('email-templates.list', ['page' => 2]));

        $page2->assertOk()
            ->assertJsonCount(5, 'templates.data');

        $this->assertNull($page2->json('templates.next_page_url'));
    }

    public function test_list_hides_system_templates(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        EmailTemplate::factory()->for($user)->create(['name' => 'mine']);
        EmailTemplate::factory()->for($otherUser)->create(['name' => 'theirs']);
        EmailTemplate::factory()->system()->create(['name' => 'system']);

        $response = $this->actingAs($user)
            ->getJson(route('email-templates.list'));

        $response->assertOk()
            ->assertJsonCount(1, 'templates.data')
            ->assertJsonPath('templates.data.0.name', 'mine');
    }

    public function test_template_preserves_template_variables(): void
    {
        $user = User::factory()->create();

        $html = '<p>Hello {{ name }}, your email is {{ email }}. {{ content }}</p><a href="{{ unsubscribe_url }}">u</a>';

        $this->actingAs($user)->post(route('email-templates.store'), [
            'name' => 'Variable Template',
            'html_content' => $html,
        ]);

        $this->assertDatabaseHas('email_templates', [
            'name' => 'Variable Template',
            'html_content' => $html,
        ]);
    }
}
