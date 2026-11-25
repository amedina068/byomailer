<?php

namespace Tests\Feature;

use App\Events\ContactSequenceFinished;
use App\Models\Contact;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use App\Models\VisualAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Each trigger of the visual automation builder enrolls the contact through
 * one queued listener.
 */
class VisualAutomationEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->contact = Contact::factory()->for($this->user)->create();
        $this->actingAsTenant($this->user->client_id);
    }

    public function test_an_added_list_enrolls_the_contact(): void
    {
        $list = MailList::factory()->for($this->user)->create();
        $automation = $this->liveAutomation('contact_added_to_list', $list->id);

        $this->contact->syncLists([$list->id], true);

        $this->assertEnrolled($automation);
    }

    public function test_a_removed_list_enrolls_the_contact(): void
    {
        $list = MailList::factory()->for($this->user)->create();
        $this->contact->lists()->attach($list);
        $automation = $this->liveAutomation('removed_from_list', $list->id);

        $this->contact->syncLists([]);

        $this->assertEnrolled($automation);
    }

    public function test_an_added_tag_enrolls_the_contact(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $automation = $this->liveAutomation('tag_added', $tag->id);

        $this->contact->syncTags([$tag->id], true);

        $this->assertEnrolled($automation);
    }

    public function test_a_removed_tag_enrolls_the_contact(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $this->contact->tags()->attach($tag);
        $automation = $this->liveAutomation('removed_tag', $tag->id);

        $this->contact->syncTags([]);

        $this->assertEnrolled($automation);
    }

    public function test_an_added_sequence_enrolls_the_contact(): void
    {
        $sequence = Sequence::factory()->for($this->user)->create(['status' => 'live']);
        $automation = $this->liveAutomation('added_to_sequence', $sequence->id);

        $this->contact->syncSequences([$sequence->id], true);

        $this->assertEnrolled($automation);
    }

    public function test_a_finished_sequence_enrolls_the_contact(): void
    {
        $sequence = Sequence::factory()->for($this->user)->create(['status' => 'live']);
        $automation = $this->liveAutomation('sequence_finished', $sequence->id);

        ContactSequenceFinished::dispatch($this->contact, $sequence);

        $this->assertEnrolled($automation);
    }

    public function test_a_trigger_without_a_value_matches_each_tag(): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $automation = $this->liveAutomation('tag_added', null);

        $this->contact->syncTags([$tag->id], true);

        $this->assertEnrolled($automation);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function automationsThatMustNotEnroll(): array
    {
        return [
            'another tag' => ['another tag'],
            'another trigger type' => ['another trigger type'],
            'a draft automation' => ['a draft automation'],
            'another client' => ['another client'],
        ];
    }

    #[DataProvider('automationsThatMustNotEnroll')]
    public function test_a_tag_does_not_enroll_the_contact_in(string $case): void
    {
        $tag = Tag::factory()->for($this->user)->create();
        $automation = match ($case) {
            'another tag' => $this->liveAutomation('tag_added', $tag->id + 100),
            'another trigger type' => $this->liveAutomation('removed_tag', $tag->id),
            'a draft automation' => tap($this->liveAutomation('tag_added', $tag->id))->update(['status' => 'draft']),
            'another client' => VisualAutomation::factory()->live()->create([
                'workflow' => $this->workflow('tag_added', $tag->id),
            ]),
        };

        $this->contact->syncTags([$tag->id], true);

        $this->assertFalse($automation->enrollments()->where('contact_id', $this->contact->id)->exists());
    }

    private function liveAutomation(string $triggerType, ?int $value): VisualAutomation
    {
        return VisualAutomation::factory()->for($this->user)->live()->create([
            'workflow' => $this->workflow($triggerType, $value),
        ]);
    }

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function workflow(string $triggerType, ?int $value): array
    {
        return [
            'nodes' => [
                [
                    'id' => 'trigger-1',
                    'type' => 'trigger',
                    'position' => ['x' => 0, 'y' => 0],
                    'data' => ['trigger_type' => $triggerType, 'value' => $value],
                ],
                [
                    'id' => 'action-1',
                    'type' => 'action',
                    'position' => ['x' => 0, 'y' => 100],
                    'data' => ['action_type' => 'add_tag', 'value' => null],
                ],
            ],
            'edges' => [
                ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'action-1'],
            ],
        ];
    }

    private function assertEnrolled(VisualAutomation $automation): void
    {
        $enrollment = $automation->enrollments()->where('contact_id', $this->contact->id)->sole();

        $this->assertSame('action-1', $enrollment->steps()->sole()->node_id);
    }
}
