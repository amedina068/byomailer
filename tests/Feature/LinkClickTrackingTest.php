<?php

namespace Tests\Feature;

use App\Events\ContactClickedLink;
use App\Jobs\ProcessSesEventJob;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\Newsletter;
use App\Models\Tag;
use App\Models\User;
use App\Models\VisualAutomation;
use App\Models\VisualAutomationEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\BuildsSesNotifications;
use Tests\TestCase;

class LinkClickTrackingTest extends TestCase
{
    use BuildsSesNotifications;
    use RefreshDatabase;

    public function test_a_click_stores_the_link_name_user_agent_and_ip_address(): void
    {
        $mail = $this->makeMail(User::factory()->create());

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, 'black-friday')]));

        $click = ContactMailClick::query()->sole();
        $this->assertSame('https://example.com/sale?utm_source=news', $click->link);
        $this->assertSame('black-friday', $click->link_name);
        $this->assertSame('Mozilla/5.0 (Test)', $click->user_agent);
        $this->assertSame('203.0.113.7', $click->ip_address);
    }

    public function test_a_click_on_an_unnamed_link_stores_no_link_name(): void
    {
        $mail = $this->makeMail(User::factory()->create());

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, null)]));

        $this->assertNull(ContactMailClick::query()->sole()->link_name);
    }

    public function test_a_link_name_from_ses_is_normalized_and_long_values_are_cut(): void
    {
        $mail = $this->makeMail(User::factory()->create());
        $event = $this->clickEvent($mail, 'Black Friday');
        $event['click']['userAgent'] = str_repeat('a', 600);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$event]));

        $click = ContactMailClick::query()->sole();
        $this->assertSame('black-friday', $click->link_name);
        $this->assertSame(512, strlen((string) $click->user_agent));
    }

    public function test_a_live_link_clicked_automation_adds_the_tag(): void
    {
        $user = User::factory()->create();
        $mail = $this->makeMail($user);
        $tag = Tag::factory()->for($user)->create();
        $this->makeAutomation($user, 'black-friday', $tag);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, 'black-friday')]));

        $this->assertTrue($this->contactHasTag($mail, $tag));
    }

    public function test_a_click_on_another_link_does_not_run_the_automation(): void
    {
        $user = User::factory()->create();
        $mail = $this->makeMail($user);
        $tag = Tag::factory()->for($user)->create();
        $this->makeAutomation($user, 'black-friday', $tag);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, 'pricing')]));

        $this->assertFalse($this->contactHasTag($mail, $tag));
    }

    public function test_a_paused_automation_does_not_run(): void
    {
        $user = User::factory()->create();
        $mail = $this->makeMail($user);
        $tag = Tag::factory()->for($user)->create();
        $this->makeAutomation($user, 'black-friday', $tag)->update(['is_live' => false]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, 'black-friday')]));

        $this->assertFalse($this->contactHasTag($mail, $tag));
    }

    public function test_an_automation_of_another_client_does_not_run(): void
    {
        $mail = $this->makeMail(User::factory()->create());
        $otherUser = User::factory()->create();
        $tag = Tag::factory()->for($otherUser)->create();
        $this->makeAutomation($otherUser, 'black-friday', $tag);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, 'black-friday')]));

        $this->assertFalse($this->contactHasTag($mail, $tag));
    }

    public function test_an_automation_of_a_teammate_runs(): void
    {
        $user = User::factory()->create();
        $teammate = User::factory()->teammateOf($user)->create();
        $mail = $this->makeMail($user);
        $tag = Tag::factory()->for($teammate)->create();
        $this->makeAutomation($teammate, 'black-friday', $tag);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, 'black-friday')]));

        $this->assertTrue($this->contactHasTag($mail, $tag));
    }

    public function test_a_duplicate_click_notification_dispatches_the_event_one_time(): void
    {
        Event::fake([ContactClickedLink::class]);
        $user = User::factory()->create();
        $mail = $this->makeMail($user);
        $this->makeAutomation($user, 'black-friday', Tag::factory()->for($user)->create());
        $notification = $this->sesNotification([$this->clickEvent($mail, 'black-friday')]);

        ProcessSesEventJob::dispatchSync($notification);
        ProcessSesEventJob::dispatchSync($notification);

        $this->assertDatabaseCount('contact_mail_clicks', 1);
        Event::assertDispatchedTimes(ContactClickedLink::class, 1);
    }

    public function test_a_click_dispatches_no_event_without_a_live_automation_or_a_link_name(): void
    {
        Event::fake([ContactClickedLink::class]);
        $user = User::factory()->create();
        $mail = $this->makeMail($user);
        $this->makeAutomation($user, 'black-friday', Tag::factory()->for($user)->create())->update(['is_live' => false]);
        Automation::factory()->for($user)->create(['is_live' => true]);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, 'black-friday')]));

        $this->makeAutomation($user, 'pricing', Tag::factory()->for($user)->create());
        $this->travel(1)->minutes();
        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, null)]));

        Event::assertNotDispatched(ContactClickedLink::class);
    }

    public function test_the_event_waits_for_the_commit_and_never_fires_on_rollback(): void
    {
        $user = User::factory()->create();
        $mail = $this->makeMail($user);
        $this->makeAutomation($user, 'black-friday', Tag::factory()->for($user)->create());
        $received = [];
        Event::listen(ContactClickedLink::class, function (ContactClickedLink $event) use (&$received): void {
            $received[] = $event->linkName;
        });

        DB::transaction(function () use ($mail, &$received): void {
            ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, 'black-friday')]));
            $this->assertSame([], $received);
        });

        $this->assertSame(['black-friday'], $received);

        $this->travel(1)->minutes();

        try {
            DB::transaction(function () use ($mail): void {
                ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, 'black-friday')]));

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(['black-friday'], $received);
    }

    public function test_a_live_visual_automation_with_the_link_trigger_enrolls_the_contact_one_time(): void
    {
        $user = User::factory()->create();
        $mail = $this->makeMail($user);
        $automation = $this->makeVisualAutomation($user, 'Black Friday', [
            ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 100], 'data' => ['action_type' => 'add_tag', 'value' => Tag::factory()->for($user)->create()->id]],
        ]);
        $this->makeVisualAutomation($user, 'pricing');
        $this->makeVisualAutomation($user, null);

        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, 'black-friday')]));
        $this->travel(1)->minutes();
        ProcessSesEventJob::dispatchSync($this->sesNotification([$this->clickEvent($mail, 'black-friday')]));

        $this->assertDatabaseCount('contact_mail_clicks', 2);
        $enrollment = VisualAutomationEnrollment::query()->sole();
        $this->assertSame($automation->id, $enrollment->visual_automation_id);
        $this->assertSame($mail->contact_id, $enrollment->contact_id);
        $this->assertSame(['action-1'], $enrollment->steps()->pluck('node_id')->all());
    }

    protected function makeMail(User $user): ContactMail
    {
        return ContactMail::factory()
            ->for(Contact::factory()->for($user))
            ->for(Newsletter::factory()->for($user), 'mailable')
            ->create(['status' => 'delivered']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function clickEvent(ContactMail $mail, ?string $linkName): array
    {
        return [
            'eventType' => 'Click',
            'mail' => ['tags' => ['contact_mail_id' => [(string) $mail->id]]],
            'click' => array_filter([
                'timestamp' => now()->toISOString(),
                'link' => 'https://example.com/sale?utm_source=news',
                'linkTags' => $linkName === null ? null : ['bm_link' => [$linkName]],
                'userAgent' => 'Mozilla/5.0 (Test)',
                'ipAddress' => '203.0.113.7',
            ]),
        ];
    }

    private function makeAutomation(User $user, string $linkName, Tag $tag): Automation
    {
        return Automation::factory()->for($user)->create([
            'condition' => ['type' => 'link_clicked', 'value' => $linkName],
            'actions' => [['type' => 'add_tag', 'value' => $tag->id]],
            'is_live' => true,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     */
    private function makeVisualAutomation(User $user, ?string $linkName, array $nodes = []): VisualAutomation
    {
        return VisualAutomation::factory()->for($user)->live()->create([
            'workflow' => [
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'link_clicked', 'value' => $linkName]],
                    ...$nodes,
                ],
                'edges' => array_map(fn (array $node): array => ['id' => 'edge-'.$node['id'], 'source' => 'trigger-1', 'target' => $node['id']], $nodes),
            ],
        ]);
    }

    private function contactHasTag(ContactMail $mail, Tag $tag): bool
    {
        return DB::table('contact_tag')
            ->where('contact_id', $mail->contact_id)
            ->where('tag_id', $tag->id)
            ->exists();
    }
}
