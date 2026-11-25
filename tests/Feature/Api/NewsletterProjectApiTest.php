<?php

namespace Tests\Feature\Api;

use App\Jobs\DispatchNewsletterBatchJob;
use App\Models\Contact;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NewsletterProjectApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_newsletter_can_be_created_with_project_ids(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $projects = Project::factory()->count(2)->for($user)->create();

        $response = $this->postJson('/api/v1/newsletters', [
            'title' => 'Projected',
            'status' => 'draft',
            'project_ids' => $projects->pluck('id')->all(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.project_ids', $projects->pluck('id')->all());

        $newsletter = Newsletter::where('title', 'Projected')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            $projects->pluck('id')->all(),
            $newsletter->projects()->pluck('projects.id')->all(),
        );
    }

    public function test_newsletter_creation_with_cross_client_project_ids_is_rejected_entirely(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $foreignProject = Project::factory()->create();

        $response = $this->postJson('/api/v1/newsletters', [
            'title' => 'Should Not Exist',
            'status' => 'draft',
            'project_ids' => [$foreignProject->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['project_ids.0']);

        $this->assertDatabaseMissing('newsletters', ['title' => 'Should Not Exist']);
        $this->assertDatabaseCount('projectables', 0);
    }

    public function test_newsletter_update_with_project_ids_replaces_assignments(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);
        [$old, $new] = Project::factory()->count(2)->for($user)->create();
        $newsletter->syncProjects([$old->id]);

        $response = $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'project_ids' => [$new->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.project_ids', [$new->id]);

        $this->assertSame([$new->id], $newsletter->projects()->pluck('projects.id')->all());
    }

    public function test_newsletter_update_without_project_ids_preserves_assignments(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);
        $project = Project::factory()->for($user)->create();
        $newsletter->syncProjects([$project->id]);

        $response = $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'title' => 'Renamed',
        ]);

        $response->assertOk();
        $this->assertSame([$project->id], $newsletter->projects()->pluck('projects.id')->all());
    }

    public function test_newsletter_update_with_empty_project_ids_detaches_all(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);
        $project = Project::factory()->for($user)->create();
        $newsletter->syncProjects([$project->id]);

        $response = $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'project_ids' => [],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.project_ids', []);

        $this->assertSame([], $newsletter->projects()->pluck('projects.id')->all());
    }

    public function test_newsletter_update_with_cross_client_project_ids_rejects_whole_write(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft', 'title' => 'Original']);
        $foreignProject = Project::factory()->create();

        $response = $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'title' => 'Hijacked',
            'project_ids' => [$foreignProject->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['project_ids.0']);

        $this->assertSame('Original', $newsletter->fresh()->title);
        $this->assertSame([], $newsletter->projects()->pluck('projects.id')->all());
    }

    public function test_gate_denied_live_send_does_not_persist_project_ids(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'type' => 'ses',
            'quota_fetched_at' => now(),
            'sending_enabled' => false,
        ]);
        Sanctum::actingAs($user, ['*']);

        $list = MailList::factory()->for($user)->create();
        Contact::factory()->for($user)->count(2)->create()
            ->each(fn (Contact $contact) => $contact->lists()->attach($list));

        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();

        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Hello</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
            'text_content' => 'Hello',
        ]);

        $project = Project::factory()->for($user)->create();

        $response = $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'status' => 'live',
            'scheduled_at' => now()->toDateTimeString(),
            'email_identity_id' => $identity->id,
            'project_ids' => [$project->id],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('ses_account');

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Newsletter::class, 'projectable_id' => $newsletter->id]);
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_newsletters_can_be_filtered_by_project(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $project = Project::factory()->for($user)->create();
        $inProject = Newsletter::factory()->for($user)->create(['title' => 'In Project']);
        $inProject->syncProjects([$project->id]);
        Newsletter::factory()->for($user)->create(['title' => 'Unassigned']);

        $response = $this->getJson("/api/v1/newsletters?project={$project->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'In Project')
            ->assertJsonPath('data.0.project_ids', [$project->id]);
    }

    public function test_project_filter_with_cross_client_project_returns_no_rows(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        Newsletter::factory()->for($user)->create();

        $otherUser = User::factory()->create();
        $foreignProject = Project::factory()->for($otherUser)->create();
        $foreignNewsletter = Newsletter::factory()->for($otherUser)->create();
        $foreignNewsletter->projects()->sync([$foreignProject->id]);

        $response = $this->getJson("/api/v1/newsletters?project={$foreignProject->id}");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_project_filter_rejects_non_integer_value(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/v1/newsletters?project=abc');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['project']);
    }

    public function test_project_filter_is_retained_across_cursor_pagination(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $project = Project::factory()->for($user)->create();

        $matching = Newsletter::factory()->count(3)->for($user)->create();
        $matching->each(fn (Newsletter $newsletter) => $newsletter->syncProjects([$project->id]));
        Newsletter::factory()->for($user)->create(['title' => 'Not In Project']);

        $firstPage = $this->getJson("/api/v1/newsletters?project={$project->id}&per_page=2");

        $firstPage->assertOk()->assertJsonCount(2, 'data');
        $nextCursor = $firstPage->json('meta.next_cursor');
        $this->assertNotNull($nextCursor);

        $secondPage = $this->getJson("/api/v1/newsletters?project={$project->id}&per_page=2&cursor={$nextCursor}");

        $secondPage->assertOk()->assertJsonCount(1, 'data');

        $returnedIds = array_merge(
            $firstPage->json('data.*.id'),
            $secondPage->json('data.*.id'),
        );
        $this->assertEqualsCanonicalizing($matching->pluck('id')->all(), $returnedIds);
    }
}
