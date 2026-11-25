<?php

namespace Tests\Feature;

use App\Models\EmailIdentity;
use App\Models\EmailTemplate;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterTemplateSlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_template_is_passed_to_content_wizard(): void
    {
        $user = User::factory()->create();
        $default = EmailTemplate::factory()->for($user)->default()->create(['name' => 'Default Shell']);

        $this->actingAs($user);
        $newsletter = $user->newsletters()->create(['title' => '', 'status' => 'draft', 'filters' => []]);
        $newsletter->message()->create([
            'user_id' => $user->id,
            'subject' => '',
            'markdown_content' => '',
            'html_content' => '',
            'text_content' => '',
        ]);

        $response = $this->actingAs($user)->get(route('newsletters.content.show', $newsletter));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('defaultTemplate.id', $default->id)
                ->where('defaultTemplate.name', 'Default Shell')
            );
    }

    public function test_updating_content_stores_template_id_and_slot(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->create();
        $template = EmailTemplate::factory()->for($user)->default()->create([
            'html_content' => '<div>shell</div><p>{{ content }}</p><a href="{{ unsubscribe_url }}">u</a>',
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

        $composed = '<div>shell</div><p><div data-slot="content"><p>My body</p></div></p><a href="{{ unsubscribe_url }}">u</a>';

        $this->actingAs($user)->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'Hello',
            'subject' => 'Hello there',
            'html_content' => $composed,
            'email_template_id' => $template->id,
            'template_slot_content' => '<p>My body</p>',
        ])->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'messageable_type' => Newsletter::class,
            'messageable_id' => $newsletter->id,
            'email_template_id' => $template->id,
            'template_slot_content' => '<p>My body</p>',
        ]);
    }

    public function test_variant_b_persists_template_fields(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->create();
        $template = EmailTemplate::factory()->for($user)->default()->create([
            'html_content' => '<p>{{ content }}</p><a href="{{ unsubscribe_url }}">u</a>',
        ]);

        $this->actingAs($user);
        $newsletter = $user->newsletters()->create(['title' => '', 'status' => 'draft', 'filters' => [], 'ab_test_enabled' => true]);
        $newsletter->message()->create([
            'user_id' => $user->id,
            'subject' => '',
            'markdown_content' => '',
            'html_content' => '',
            'text_content' => '',
            'variant' => 'a',
        ]);

        $composedA = '<p><div data-slot="content"><p>Variant A body</p></div></p><a href="{{ unsubscribe_url }}">u</a>';
        $composedB = '<p><div data-slot="content"><p>Variant B body</p></div></p><a href="{{ unsubscribe_url }}">u</a>';

        $this->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'AB test',
            'subject' => 'Subject A',
            'subject_b' => 'Subject B',
            'html_content' => $composedA,
            'html_content_b' => $composedB,
            'email_template_id' => $template->id,
            'template_slot_content' => '<p>Variant A body</p>',
            'template_slot_content_b' => '<p>Variant B body</p>',
            'ab_test_enabled' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'messageable_type' => Newsletter::class,
            'messageable_id' => $newsletter->id,
            'variant' => 'b',
            'email_template_id' => $template->id,
            'template_slot_content' => '<p>Variant B body</p>',
        ]);
    }

    public function test_cross_client_template_is_rejected(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        EmailIdentity::factory()->for($attacker)->create();

        $foreignTemplate = EmailTemplate::factory()->for($owner)->create([
            'html_content' => '<p>{{ content }}</p><a href="{{ unsubscribe_url }}">u</a>',
        ]);

        $this->actingAs($attacker);

        $newsletter = $attacker->newsletters()->create(['title' => '', 'status' => 'draft', 'filters' => []]);
        $newsletter->message()->create([
            'user_id' => $attacker->id,
            'subject' => '',
            'markdown_content' => '',
            'html_content' => '',
            'text_content' => '',
        ]);

        $this->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'Evil',
            'subject' => 'Evil',
            'html_content' => '<p><div data-slot="content"><p>x</p></div></p>',
            'email_template_id' => $foreignTemplate->id,
            'template_slot_content' => '<p>x</p>',
        ])->assertSessionHasErrors('email_template_id');
    }

    public function test_variant_a_missing_slot_fails_validation(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->create();
        $template = EmailTemplate::factory()->for($user)->default()->create([
            'html_content' => '<p>{{ content }}</p><a href="{{ unsubscribe_url }}">u</a>',
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
            'title' => 'Hello',
            'subject' => 'Hello',
            'html_content' => '<p>Raw content with no slot</p>',
            'email_template_id' => $template->id,
            'template_slot_content' => '<p>Raw content with no slot</p>',
        ])->assertSessionHasErrors('html_content');
    }

    public function test_reopening_draft_with_non_default_template_hydrates_applied_template(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        EmailTemplate::factory()->for($user)->default()->create(['name' => 'Default']);
        $custom = EmailTemplate::factory()->for($user)->create([
            'name' => 'Custom',
            'html_content' => '<p>{{ content }}</p><a href="{{ unsubscribe_url }}">u</a>',
        ]);

        $newsletter = $user->newsletters()->create(['title' => 'Draft', 'status' => 'draft', 'filters' => []]);
        $newsletter->message()->create([
            'user_id' => $user->id,
            'subject' => 'Hi',
            'markdown_content' => '',
            'html_content' => '<p><div data-slot="content"><p>Body</p></div></p><a href="{{ unsubscribe_url }}">u</a>',
            'text_content' => 'Body',
            'email_template_id' => $custom->id,
            'template_slot_content' => '<p>Body</p>',
        ]);

        $response = $this->actingAs($user)->get(route('newsletters.content.show', $newsletter));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('draftNewsletter.email_template_id', $custom->id)
            ->where('draftNewsletter.email_template.id', $custom->id)
            ->where('draftNewsletter.email_template.name', 'Custom')
        );
    }

    public function test_variant_b_missing_slot_fails_validation(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->create();
        $template = EmailTemplate::factory()->for($user)->default()->create([
            'html_content' => '<p>{{ content }}</p><a href="{{ unsubscribe_url }}">u</a>',
        ]);

        $this->actingAs($user);
        $newsletter = $user->newsletters()->create(['title' => '', 'status' => 'draft', 'filters' => [], 'ab_test_enabled' => true]);
        $newsletter->message()->create([
            'user_id' => $user->id,
            'subject' => '',
            'markdown_content' => '',
            'html_content' => '',
            'text_content' => '',
            'variant' => 'a',
        ]);

        $this->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'AB test',
            'subject' => 'A',
            'subject_b' => 'B',
            'html_content' => '<p><div data-slot="content"><p>A</p></div></p>',
            'html_content_b' => '<p>Raw variant B with no slot</p>',
            'email_template_id' => $template->id,
            'template_slot_content' => '<p>A</p>',
            'ab_test_enabled' => true,
        ])->assertSessionHasErrors('html_content_b');
    }
}
