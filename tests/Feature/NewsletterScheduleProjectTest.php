<?php

namespace Tests\Feature;

use App\Jobs\DispatchNewsletterBatchJob;
use App\Models\Client;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class NewsletterScheduleProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_action_syncs_project_ids(): void
    {
        [$user, $newsletter, $identity] = $this->makeNewsletter();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'draft',
            'email_identity_id' => $identity->id,
            'project_ids' => [$project->id],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Newsletter::class, 'projectable_id' => $newsletter->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_scheduling_action_syncs_project_ids(): void
    {
        [$user, $newsletter, $identity] = $this->makeNewsletter();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'schedule',
            'scheduled_for' => now($user->client->timezone)->addDay()->format('Y-m-d\TH:i'),
            'email_identity_id' => $identity->id,
            'project_ids' => [$project->id],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Newsletter::class, 'projectable_id' => $newsletter->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_send_now_action_syncs_project_ids(): void
    {
        Bus::fake();

        [$user, $newsletter, $identity] = $this->makeNewsletter();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $identity->id,
            'project_ids' => [$project->id],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Newsletter::class, 'projectable_id' => $newsletter->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_omitting_project_ids_preserves_existing_assignments(): void
    {
        [$user, $newsletter, $identity] = $this->makeNewsletter();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $newsletter->syncProjects([$project->id]);

        $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'draft',
            'email_identity_id' => $identity->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('projectables', [
            'projectable_type' => Newsletter::class, 'projectable_id' => $newsletter->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_empty_project_ids_detaches_all(): void
    {
        [$user, $newsletter, $identity] = $this->makeNewsletter();
        $project = Project::factory()->create(['user_id' => $user->id]);
        $newsletter->syncProjects([$project->id]);

        $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'draft',
            'email_identity_id' => $identity->id,
            'project_ids' => [],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Newsletter::class, 'projectable_id' => $newsletter->id]);
    }

    public function test_cross_client_project_id_rejects_entire_write(): void
    {
        [$user, $newsletter, $identity] = $this->makeNewsletter();
        $foreignProject = Project::factory()->create();

        $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'draft',
            'email_identity_id' => $identity->id,
            'project_ids' => [$foreignProject->id],
        ])->assertSessionHasErrors('project_ids.0');

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Newsletter::class, 'projectable_id' => $newsletter->id]);
        $this->assertSame($newsletter->status, $newsletter->fresh()->status);
    }

    public function test_ab_enabled_newsletter_syncs_projects_once(): void
    {
        [$user, $newsletter, $identity] = $this->makeNewsletter();
        $newsletter->forceFill(['ab_test_enabled' => true])->save();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'draft',
            'email_identity_id' => $identity->id,
            'project_ids' => [$project->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $newsletter->projects()->count());
    }

    public function test_gate_denied_send_does_not_persist_project_ids(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create([
            'type' => 'ses',
            'quota_fetched_at' => now(),
            'sending_enabled' => false,
        ]);

        $list = MailList::factory()->for($user)->create();
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

        $project = Project::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $identity->id,
            'project_ids' => [$project->id],
        ])->assertSessionHasErrors('ses_account');

        $this->assertDatabaseMissing('projectables', ['projectable_type' => Newsletter::class, 'projectable_id' => $newsletter->id]);
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    /**
     * @return array{0: User, 1: Newsletter, 2: EmailIdentity}
     */
    private function makeNewsletter(): array
    {
        $user = User::factory()->for(Client::factory())->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
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
            'markdown_content' => '# Hello',
        ]);

        return [$user, $newsletter, $identity];
    }
}
