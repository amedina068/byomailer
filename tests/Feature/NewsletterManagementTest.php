<?php

namespace Tests\Feature;

use App\Enums\NewsletterStatus;
use App\Jobs\DispatchNewsletterBatchJob;
use App\Jobs\SendNewsletterChunkJob;
use App\Jobs\SendNewsletterJob;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactAttribute;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Tag;
use App\Models\User;
use App\Services\SendNewsletterService;
use App\Services\SesBulkEmailService;
use App\Services\SesClientManager;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\SerializableClosure\SerializableClosure;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use ReflectionFunction;
use Tests\TestCase;

class NewsletterManagementTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_user_can_view_lists_index(): void
    {
        $user = User::factory()->create();
        $lists = MailList::factory()->count(2)->for($user)->create();
        MailList::factory()->create();

        $response = $this->actingAs($user)->get(route('lists.index'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('lists/Index')
            ->where('lists', fn ($data): bool => collect($data)->count() === 2
                && collect($data)->pluck('name')->diff($lists->pluck('name'))->isEmpty())
        );
    }

    public function test_user_can_view_contacts_index(): void
    {
        $user = User::factory()->create();
        $contacts = Contact::factory()->count(3)->for($user)->create();
        Contact::factory()->create();

        $response = $this->actingAs($user)->get(route('contacts.index'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Index')
            ->where('contacts.data', fn ($data): bool => collect($data)->count() === 3
                && collect($data)->pluck('email')->diff($contacts->pluck('email'))->isEmpty())
        );
    }

    public function test_contacts_index_hides_unsubscribed_contacts_by_default(): void
    {
        $user = User::factory()->create();
        $active = Contact::factory()->for($user)->create(['unsubscribed_at' => null]);
        Contact::factory()->for($user)->create([
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ]);

        $response = $this->actingAs($user)->get(route('contacts.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Index')
            ->has('contacts.data', 1)
            ->where('contacts.data.0.id', $active->id)
        );
    }

    public function test_contacts_index_can_include_unsubscribed_contacts(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create(['unsubscribed_at' => null]);
        $unsubscribed = Contact::factory()->for($user)->create([
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ]);

        $response = $this->actingAs($user)->get(route('contacts.index', [
            'include_unsubscribed' => 1,
        ]));

        $response->assertInertia(function (Assert $page) use ($unsubscribed): void {
            $page
                ->component('contacts/Index')
                ->has('contacts.data', 2)
                ->where('contacts.data', fn ($contacts): bool => collect($contacts)->pluck('id')->contains($unsubscribed->id))
                // The contacts table shows an "Unsubscribed" badge from this field.
                ->where('contacts.data', fn ($contacts): bool => collect($contacts)->firstWhere('id', $unsubscribed->id)['unsubscribed_at'] !== null);
        });
    }

    public function test_contacts_index_can_be_filtered_by_list(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $inList = Contact::factory()->for($user)->create();
        $inList->lists()->attach($list);
        Contact::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('contacts.index', [
            'lists' => $list->id,
        ]));

        $response->assertInertia(function (Assert $page) use ($inList): void {
            $page
                ->component('contacts/Index')
                ->has('contacts.data', 1)
                ->where('contacts.data.0.id', $inList->id);
        });
    }

    public function test_contacts_index_can_be_filtered_by_multiple_lists(): void
    {
        $user = User::factory()->create();
        $list1 = MailList::factory()->for($user)->create();
        $list2 = MailList::factory()->for($user)->create();
        $inList1 = Contact::factory()->for($user)->create();
        $inList1->lists()->attach($list1);
        $inList2 = Contact::factory()->for($user)->create();
        $inList2->lists()->attach($list2);
        Contact::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('contacts.index', [
            'lists' => $list1->id.','.$list2->id,
        ]));

        $response->assertInertia(function (Assert $page): void {
            $page
                ->component('contacts/Index')
                ->has('contacts.data', 2);
        });
    }

    public function test_contacts_index_can_be_filtered_by_tags(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create();
        $taggedContact = Contact::factory()->for($user)->create();
        $taggedContact->tags()->attach($tag);
        Contact::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('contacts.index', [
            'tags' => $tag->id,
        ]));

        $response->assertInertia(function (Assert $page) use ($taggedContact): void {
            $page
                ->component('contacts/Index')
                ->has('contacts.data', 1)
                ->where('contacts.data.0.id', $taggedContact->id);
        });
    }

    public function test_contacts_index_returns_lists_and_tags_for_filtering(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create();
        Contact::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('contacts.index'));

        $response->assertInertia(function (Assert $page) use ($list, $tag): void {
            $page
                ->component('contacts/Index')
                ->has('lists', 1)
                ->where('lists.0.id', $list->id)
                ->has('tags', 1)
                ->where('tags.0.id', $tag->id)
                ->has('filters');
        });
    }

    public function test_contact_import_can_preselect_list(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('contacts.import', [
            'list_id' => $list->id,
        ]));

        $response->assertInertia(function (Assert $page) use ($list): void {
            $page
                ->component('contacts/Import')
                ->where('selectedListId', $list->id);
        });
    }

    public function test_user_can_view_contact_details(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();

        $identity = EmailIdentity::factory()->for($user)->create();
        $list = MailList::factory()->for($user)->create();
        $contact->lists()->attach($list);
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        $this->updateNewsletterMessage($newsletter, $identity, [
            'subject' => 'Launch Campaign',
        ]);
        $newsletter->refresh();

        $response = $this->actingAs($user)->get(route('contacts.show', $contact));

        $response->assertOk();

        ContactMail::create([
            'contact_id' => $contact->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
            'subject' => $newsletter->message->subject,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('contacts.show', $contact));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Show')
            ->where('contact.email', $contact->email)
            ->where('contact.lists', function ($lists) use ($list): bool {
                return collect($lists)->pluck('name')->contains($list->name);
            })
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('mails', 1)
                ->where('mails.0.subject', $newsletter->message->subject)
            )
        );
    }

    public function test_user_can_view_tags_index(): void
    {
        $user = User::factory()->create();
        $tags = Tag::factory()->count(2)->for($user)->create();
        Tag::factory()->create();

        $response = $this->actingAs($user)->get(route('tags.index'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('tags/Index')
            ->where('tags', fn ($data): bool => collect($data)->count() === 2
                && collect($data)->pluck('name')->diff($tags->pluck('name'))->isEmpty())
        );
    }

    public function test_user_can_view_newsletters_index(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $list = MailList::factory()->for($user)->create();
        $newsletters = Newsletter::factory()
            ->count(2)
            ->for($user)
            ->withListFilters([$list->id])
            ->create();
        $primaryNewsletter = $newsletters->first();
        $newsletters->each(function (Newsletter $newsletter) use ($identity): void {
            $this->updateNewsletterMessage($newsletter, $identity);
        });

        if ($primaryNewsletter) {
            $factoryStates = ['sent', 'pending', 'bounced', 'unsubscribed', 'complained'];
            foreach ($factoryStates as $state) {
                $contact = Contact::factory()->for($user)->create();
                $contact->lists()->attach($list);
                ContactMail::factory()
                    ->for($contact)
                    ->forNewsletter($primaryNewsletter)
                    ->{$state}()
                    ->create();
            }
        }

        $otherUser = User::factory()->create();
        $otherIdentity = EmailIdentity::factory()->for($otherUser)->create();
        $otherList = MailList::factory()->for($otherUser)->create();
        $otherNewsletter = Newsletter::factory()
            ->for($otherUser)
            ->withListFilters([$otherList->id])
            ->create();
        $this->updateNewsletterMessage($otherNewsletter, $otherIdentity);

        $response = $this->actingAs($user)->get(route('newsletters.index'));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Index')
            ->has('newsletters', 2)
            ->where('newsletters', function ($newsletters) use ($primaryNewsletter): bool {
                if (! $primaryNewsletter) {
                    return true;
                }

                $target = collect($newsletters)->firstWhere('id', $primaryNewsletter->id);

                if (! $target) {
                    return false;
                }

                return $target['metrics']['sent'] === 4
                    && $target['metrics']['pending'] === 1
                    && $target['metrics']['opened'] === 0
                    && $target['metrics']['bounced'] === 1
                    && $target['metrics']['unsubscribed'] === 1
                    && $target['metrics']['complained'] === 1
                    && $target['metrics']['total'] === 5;
            })
        );
    }

    public function test_show_metrics_total_uses_total_contacts_reached_while_chunks_in_flight(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create([
            'status' => 'queued',
            'total_contacts_reached' => 100,
        ]);

        // Only some chunks have completed yet, so ContactMail rows are < 100.
        $contacts = Contact::factory()->count(3)->for($user)->create();
        $contacts->each(fn (Contact $contact) => ContactMail::factory()
            ->for($contact)
            ->sent()
            ->forNewsletter($newsletter)
            ->create()
        );

        $response = $this->actingAs($user)->get(route('newsletters.show', $newsletter));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Show')
            ->where('newsletter.metrics.total', 100)
            ->where('newsletter.metrics.sent', 3)
        );
    }

    public function test_the_newsletter_pages_and_the_dashboard_send_the_status_labels(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => NewsletterStatus::AbTesting]);
        $this->actingAs($user);

        $hasStatuses = fn (Assert $page): Assert => $page
            ->has('newsletterStatuses', count(NewsletterStatus::cases()))
            ->where('newsletterStatuses.0', ['value' => 'draft', 'label' => 'Draft'])
            ->where('newsletterStatuses.3', ['value' => 'ab_testing', 'label' => 'A/B testing']);

        $this->get(route('newsletters.index'))->assertInertia(fn (Assert $page) => $hasStatuses($page->component('newsletters/Index')));
        $this->get(route('newsletters.show', $newsletter))->assertInertia(fn (Assert $page) => $hasStatuses($page->component('newsletters/Show')));
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $hasStatuses($page->component('Dashboard')));
    }

    public function test_user_can_create_newsletter_which_auto_creates_draft_and_redirects(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('newsletters.create'));

        $newsletter = Newsletter::where('user_id', $user->id)->first();

        $this->assertNotNull($newsletter);
        $this->assertSame(NewsletterStatus::Draft, $newsletter->status);
        $this->assertNotNull($newsletter->message);

        $response->assertRedirect(route('newsletters.content.show', $newsletter));
    }

    public function test_content_step_shows_lists_tags_and_email_identities(): void
    {
        $user = User::factory()->create();
        MailList::factory()->count(2)->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create(['email' => 'hello@example.com']);
        EmailIdentity::factory()->create();
        $alphaTag = Tag::factory()->for($user)->create(['name' => 'Alpha']);
        Tag::factory()->create();
        ContactAttribute::create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'plan',
        ]);
        ContactAttribute::create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'status',
        ]);
        $otherUser = User::factory()->create();
        ContactAttribute::create([
            'user_id' => $otherUser->id,
            'client_id' => $otherUser->client_id,
            'name' => 'ignored',
        ]);
        $newsletter = $user->newsletters()->create([
            'status' => 'draft',
            'filters' => [],
            'client_id' => $user->client_id,
        ]);

        $response = $this->actingAs($user)->get(route('newsletters.content.show', $newsletter));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Create')
            ->has('lists', 2)
            ->has('emailIdentities', 1)
            ->has('tags', 1)
            ->where('tags.0.name', $alphaTag->name)
            ->where('contactAttributes', fn ($attributes): bool => collect($attributes)->contains('plan')
                && collect($attributes)->contains('status'))
            ->where('emailIdentities.0.email', $identity->email)
        );
    }

    public function test_user_can_resume_draft_newsletter_via_content_step(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create(['status' => 'draft']);
        $this->updateNewsletterMessage($newsletter, $identity, [
            'subject' => 'Draft Subject',
            'html_content' => '<p>Draft content</p>',
        ]);

        $response = $this->actingAs($user)->get(route('newsletters.content.show', $newsletter));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Create')
            ->where('draftNewsletter.id', $newsletter->id)
            ->where('draftNewsletter.subject', 'Draft Subject')
        );
    }

    public function test_user_can_visit_newsletter_content_step_via_get(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('newsletters.content.show', $newsletter));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Create')
            ->where('initialStep', 'content')
            ->where('draftNewsletter.id', $newsletter->id)
        );
    }

    public function test_user_can_visit_newsletter_filters_step_via_get(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('newsletters.filters.show', $newsletter));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Create')
            ->where('initialStep', 'filters')
            ->where('draftNewsletter.id', $newsletter->id)
        );
    }

    public function test_user_can_visit_newsletter_preview_step_via_get(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('newsletters.preview.show', $newsletter));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Create')
            ->where('initialStep', 'preview')
            ->where('draftNewsletter.id', $newsletter->id)
        );
    }

    public function test_user_can_visit_newsletter_schedule_step_via_get(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $response = $this->actingAs($user)->get(route('newsletters.schedule.show', $newsletter));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Create')
            ->where('initialStep', 'schedule')
            ->where('draftNewsletter.id', $newsletter->id)
        );
    }

    public function test_user_cannot_access_content_step_of_sent_newsletter(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create(['status' => 'sent', 'sent_at' => now()]);
        $this->updateNewsletterMessage($newsletter, $identity);

        $response = $this->actingAs($user)->get(route('newsletters.content.show', $newsletter));

        $response->assertNotFound();
    }

    public function test_user_cannot_access_other_users_draft_content_step(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $list = MailList::factory()->for($otherUser)->create();
        $newsletter = Newsletter::factory()
            ->for($otherUser)
            ->withListFilters([$list->id])
            ->create(['status' => 'draft']);

        $response = $this->actingAs($user)->get(route('newsletters.content.show', $newsletter));

        $response->assertNotFound();
    }

    public function test_user_can_view_newsletter_details(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $list = MailList::factory()->for($user)->create();
        $contactFirstName = 'Casey';
        $contact = Contact::factory()->for($user)->create([
            'extra_attributes' => [
                'first_name' => $contactFirstName,
            ],
        ]);
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        $this->updateNewsletterMessage($newsletter, $identity, [
            'subject' => 'Product Update',
        ]);

        $contactMailData = [
            ['contact' => $contact, 'state' => 'sent'],
            ['contact' => Contact::factory()->for($user)->create(), 'state' => 'pending'],
            ['contact' => Contact::factory()->for($user)->create(), 'state' => 'bounced'],
            ['contact' => Contact::factory()->for($user)->create(), 'state' => 'unsubscribed'],
            ['contact' => Contact::factory()->for($user)->create(), 'state' => 'complained'],
        ];

        foreach ($contactMailData as $entry) {
            $entry['contact']->lists()->syncWithoutDetaching([$list->id]);

            ContactMail::factory()
                ->for($entry['contact'])
                ->forNewsletter($newsletter)
                ->{$entry['state']}()
                ->create();
        }

        $newsletter->refresh();

        $response = $this->actingAs($user)->get(route('newsletters.show', $newsletter));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Show')
            ->where('newsletter.subject', $newsletter->message->subject)
            ->has('recipients.data', 5)
            ->where('recipients.total', 5)
            ->where('recipientStatusFilter', 'all')
            ->where('recipientStatusOptions', fn ($statuses): bool => collect($statuses)->contains('sent')
                && collect($statuses)->contains('pending')
                && collect($statuses)->contains('bounced')
                && collect($statuses)->contains('unsubscribed')
                && collect($statuses)->contains('complained'))
            ->where('recipients.data', fn ($recipients): bool => collect($recipients)
                ->pluck('contact_mail_status')
                ->contains('complained'))
            ->where('newsletter.metrics.sent', 4) // sent, bounced, unsubscribed, complained all have sent_at
            ->where('newsletter.metrics.pending', 1)
            ->where('newsletter.metrics.bounced', 1)
            ->where('newsletter.metrics.unsubscribed', 1)
            ->where('newsletter.metrics.complained', 1)
            ->where('newsletter.metrics.delivered', 2) // unsubscribed + complained have delivered_at
            ->where('newsletter.metrics.delivery_rate', 50) // 2 delivered / 4 sent
        );
    }

    public function test_newsletter_show_returns_fresh_progress_and_stats_on_reload(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'queued']);

        $makeMail = fn (string $state): ContactMail => ContactMail::factory()
            ->for(Contact::factory()->for($user))
            ->forNewsletter($newsletter)
            ->{$state}()
            ->create();

        $pending = collect(['pending', 'pending', 'pending', 'pending'])->map($makeMail);

        // Initial poll snapshot — nothing sent yet.
        $this->actingAs($user)->get(route('newsletters.show', $newsletter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('newsletters/Show')
                ->where('newsletter.metrics.pending', 4)
                ->where('newsletter.metrics.sent', 0)
                ->where('newsletter.metrics.opened', 0)
                ->where('newsletter.metrics.bounced', 0)
            );

        // Send progresses: 3 of 4 recipients resolve to sent / opened / bounced.
        $pending->take(3)->each->delete();
        $makeMail('sent');
        $makeMail('opened');
        $makeMail('bounced');

        // Next poll re-hits the same controller action — must reflect new state.
        $this->actingAs($user)->get(route('newsletters.show', $newsletter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('newsletters/Show')
                ->where('newsletter.metrics.pending', 1)
                ->where('newsletter.metrics.sent', 3) // sent + opened + bounced have sent_at
                ->where('newsletter.metrics.opened', 1)
                ->where('newsletter.metrics.bounced', 1)
                ->has('recipients.data', 4)
            );
    }

    public function test_user_can_filter_newsletter_recipients_by_status(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'sent']);

        ContactMail::factory()->for(Contact::factory()->for($user))->forNewsletter($newsletter)->sent()->create();
        ContactMail::factory()->for(Contact::factory()->for($user))->forNewsletter($newsletter)->bounced()->create();

        $response = $this->actingAs($user)->get(route('newsletters.show', [
            'newsletter' => $newsletter,
            'status' => 'bounced',
        ]));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Show')
            ->where('recipientStatusFilter', 'bounced')
            ->has('recipients.data', 1)
            ->where('recipients.data.0.contact_mail_status', 'bounced')
        );
    }

    public function test_contact_mail_records_are_created_when_sending(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->production()->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contactFirstName = 'Casey';
        $contact = Contact::factory()->for($user)->create([
            'extra_attributes' => [
                'first_name' => $contactFirstName,
            ],
        ]);
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create([
                'status' => 'queued',
                'scheduled_at' => now(),
            ]);
        $this->updateNewsletterMessage($newsletter, $identity, [
            'html_content' => '<p>Hello {{ first_name }} ({{ email }}) <a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
            'text_content' => 'Plain {{ first_name }} copy',
        ]);

        $batch = $this->setupNewsletterBatchMocks($newsletter);

        app(SendNewsletterService::class)->send($newsletter, $batch);

        Bus::assertBatchCount(1);
        $this->assertCount(1, $batch->added);
        $this->assertContains($contact->id, $batch->added[0]->contactIds);
    }

    public function test_saving_content_step_redirects_to_variables_step(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);

        $response = $this->actingAs($user)->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'April Newsletter',
            'subject' => 'Monthly Update',
            'html_content' => '<h1>Hello world</h1>',
        ]);

        $response->assertRedirect(route('newsletters.variables.show', $newsletter));

        $newsletter->refresh()->load('message');
        $this->assertSame('April Newsletter', $newsletter->title);
        $this->assertSame('Monthly Update', $newsletter->message?->subject);
        $this->assertSame('<h1>Hello world</h1>', $newsletter->message?->html_content);
    }

    public function test_user_can_update_filters_for_a_draft_newsletter(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();
        $this->updateNewsletterMessage($newsletter, $identity);
        $newList = MailList::factory()->for($user)->create();
        ContactAttribute::factory()->for($user)->create(['name' => 'plan']);
        Tag::factory()->for($user)->create(['name' => 'vip']);
        Tag::factory()->for($user)->create(['name' => 'beta']);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$newList->id],
            ],
            [
                'connector' => 'and',
                'category' => 'attributes',
                'mode' => 'all',
                'values' => [
                    ['key' => 'plan', 'value' => 'pro'],
                ],
            ],
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'any',
                'values' => ['vip', 'beta'],
            ],
        ];

        $response = $this->actingAs($user)->patch(route('newsletters.filters.update', $newsletter), [
            'filters' => $filters,
        ]);

        $response->assertRedirect(route('newsletters.preview.show', $newsletter));

        $newsletter->refresh();

        $this->assertSame([$newList->id], $newsletter->filters[0]['values']);
        $this->assertSame('pro', $newsletter->filters[1]['values'][0]['value']);
        $this->assertSame(['vip', 'beta'], $newsletter->filters[2]['values']);
    }

    public function test_user_can_update_content_for_an_existing_draft(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();
        $this->updateNewsletterMessage($newsletter, $identity);

        $response = $this->actingAs($user)->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'Spring Campaign',
            'subject' => 'First Draft',
            'html_content' => '<h1>Intro</h1>',
        ]);

        $response->assertRedirect(route('newsletters.variables.show', $newsletter));

        $newsletter->refresh();
        $this->assertSame('Spring Campaign', $newsletter->title);
        $this->assertSame('First Draft', $newsletter->message?->subject);
        $this->assertSame('<h1>Intro</h1>', $newsletter->message?->html_content);
    }

    public function test_user_can_delete_draft_newsletter(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);

        $response = $this->actingAs($user)->delete(route('newsletters.destroy', $newsletter));

        $response->assertRedirect(route('newsletters.index'));

        $this->assertDatabaseMissing('newsletters', ['id' => $newsletter->id]);
        $this->assertDatabaseMissing('messages', [
            'messageable_type' => Newsletter::class,
            'messageable_id' => $newsletter->id,
        ]);
    }

    public function test_user_cannot_delete_non_draft_newsletter(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'sent']);

        $response = $this->actingAs($user)->delete(route('newsletters.destroy', $newsletter));

        $response->assertNotFound();

        $this->assertDatabaseHas('newsletters', ['id' => $newsletter->id]);
    }

    public function test_user_cannot_delete_newsletter_of_another_user(): void
    {
        $owner = User::factory()->create();
        $newsletter = Newsletter::factory()->for($owner)->create();
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)->delete(route('newsletters.destroy', $newsletter));

        $response->assertNotFound();

        $this->assertDatabaseHas('newsletters', ['id' => $newsletter->id]);
    }

    public function test_user_can_update_newsletter_title_inline(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['title' => 'Old Title']);

        $response = $this->actingAs($user)->patch(route('newsletters.title.update', $newsletter), [
            'title' => 'New Title',
        ]);

        $response->assertRedirect();

        $this->assertSame('New Title', $newsletter->fresh()->title);
    }

    public function test_title_update_requires_a_non_empty_value(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['title' => 'Old Title']);

        $response = $this->actingAs($user)->from(route('newsletters.show', $newsletter))
            ->patch(route('newsletters.title.update', $newsletter), [
                'title' => '',
            ]);

        $response->assertSessionHasErrors('title');
        $this->assertSame('Old Title', $newsletter->fresh()->title);
    }

    public function test_user_cannot_update_title_of_another_users_newsletter(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $newsletter = Newsletter::factory()->for($other)->create(['title' => 'Other Title']);

        $response = $this->actingAs($user)->patch(route('newsletters.title.update', $newsletter), [
            'title' => 'Hijacked',
        ]);

        $response->assertNotFound();
        $this->assertSame('Other Title', $newsletter->fresh()->title);
    }

    public function test_newsletter_show_includes_title(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['title' => 'My Campaign Title']);

        $response = $this->actingAs($user)->get(route('newsletters.show', $newsletter));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Show')
            ->where('newsletter.title', 'My Campaign Title')
        );
    }

    public function test_content_step_requires_title(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);

        $response = $this->actingAs($user)->from(route('newsletters.content.show', $newsletter))
            ->patch(route('newsletters.content.update', $newsletter), [
                'subject' => 'Monthly Update',
                'html_content' => '<h1>Hello world</h1>',
            ]);

        $response->assertSessionHasErrors('title');
    }

    public function test_user_can_schedule_a_draft_newsletter_for_later(): void
    {
        $user = User::factory()->for(Client::factory()->state(['timezone' => 'America/Los_Angeles']))->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();
        $this->updateNewsletterMessage($newsletter, $identity);

        Carbon::setTestNow($now = now()->startOfMinute());
        $scheduledForUtc = $now->copy()->addDay();
        $localScheduledFor = $scheduledForUtc->clone()->setTimezone($user->client->timezone)->format('Y-m-d\TH:i');

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'schedule',
            'scheduled_for' => $localScheduledFor,
            'email_identity_id' => $identity->id,
        ]);

        $response->assertRedirect(route('newsletters.index'));

        $newsletter->refresh();

        $this->assertTrue($newsletter->scheduled_at->equalTo($scheduledForUtc));
        $this->assertSame(NewsletterStatus::Scheduled, $newsletter->status);
        $this->assertSame($identity->id, $newsletter->message?->email_identity_id);
        $this->assertSame($identity->email, $newsletter->message?->from_email);
    }

    public function test_schedule_times_are_parsed_using_user_timezone(): void
    {
        $user = User::factory()->for(Client::factory()->state(['timezone' => 'Asia/Tokyo']))->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();
        $this->updateNewsletterMessage($newsletter, $identity);

        Carbon::setTestNow(Carbon::create(2025, 3, 9, 0, 0, 0, 'UTC'));
        $localScheduledFor = '2025-03-10T09:45';
        $expectedUtc = Carbon::createFromFormat('Y-m-d\TH:i', $localScheduledFor, $user->client->timezone)->utc();

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'schedule',
            'scheduled_for' => $localScheduledFor,
            'email_identity_id' => $identity->id,
        ]);

        $response
            ->assertRedirect(route('newsletters.index'))
            ->assertSessionHas('success', 'Newsletter scheduled for Mar 10, 2025, 9:45 AM (Asia/Tokyo).');

        $this->assertTrue($newsletter->fresh()->scheduled_at?->equalTo($expectedUtc));
    }

    public function test_saving_a_draft_from_the_schedule_step_flashes_a_message(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();
        $this->updateNewsletterMessage($newsletter, $identity);

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'draft',
            'email_identity_id' => $identity->id,
        ]);

        $response
            ->assertRedirect(route('newsletters.index'))
            ->assertSessionHas('success', 'Draft saved.');
    }

    public function test_schedule_time_must_be_in_the_future_in_user_timezone(): void
    {
        $user = User::factory()->for(Client::factory()->state(['timezone' => 'Europe/Berlin']))->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();
        $this->updateNewsletterMessage($newsletter, $identity);

        Carbon::setTestNow(Carbon::create(2025, 4, 10, 10, 0, 0, 'UTC'));
        $pastLocal = now($user->client->timezone)->subMinute()->format('Y-m-d\TH:i');

        $response = $this->actingAs($user)
            ->from(route('newsletters.create'))
            ->post(route('newsletters.schedule', $newsletter), [
                'action' => 'schedule',
                'scheduled_for' => $pastLocal,
                'email_identity_id' => $identity->id,
            ]);

        $response
            ->assertSessionHasErrors('scheduled_for')
            ->assertRedirect(route('newsletters.create'));

        $this->assertNull($newsletter->fresh()->scheduled_at);
    }

    public function test_user_can_send_a_newsletter_immediately_from_schedule_step(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();
        $this->updateNewsletterMessage($newsletter, $identity);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
            [
                'connector' => 'and',
                'category' => 'attributes',
                'mode' => 'all',
                'values' => [
                    ['key' => 'plan', 'value' => 'pro'],
                ],
            ],
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'any',
                'values' => ['vip', 'beta'],
            ],
        ];

        $newsletter->update([
            'filters' => $filters,
        ]);

        Bus::fake();
        Carbon::setTestNow($now = now()->startOfMinute());

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertRedirect(route('newsletters.show', $newsletter));

        $newsletter->refresh();

        $this->assertTrue($newsletter->scheduled_at?->equalTo($now));
        $this->assertSame(NewsletterStatus::Queued, $newsletter->status);
        $this->assertSame($identity->id, $newsletter->message?->email_identity_id);

        Bus::assertDispatched(DispatchNewsletterBatchJob::class, function (DispatchNewsletterBatchJob $job) use ($newsletter): bool {
            return $job->newsletter->is($newsletter);
        });
    }

    public function test_schedule_step_requires_a_selected_identity(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();

        $response = $this->actingAs($user)->from(route('newsletters.create'))->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
        ]);

        $response->assertRedirect(route('newsletters.create'));
        $response->assertSessionHasErrors('email_identity_id');
    }

    public function test_user_cannot_mutate_newsletters_they_do_not_own(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherList = MailList::factory()->for($other)->create();
        $originalFilters = [[
            'connector' => 'and',
            'category' => 'lists',
            'mode' => 'any',
            'values' => [$otherList->id],
        ]];
        $newsletter = Newsletter::factory()->for($other)->withFilters($originalFilters)->create();

        $response = $this->actingAs($user)->patch(route('newsletters.filters.update', $newsletter), [
            'filters' => $originalFilters,
        ]);

        $response->assertSessionHasErrors('filters.0.values');

        $newsletter->refresh();
        $this->assertSame($originalFilters[0]['values'], $newsletter->filters[0]['values']);
    }

    public function test_user_cannot_use_foreign_list_in_filters(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $otherList = MailList::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();
        $this->updateNewsletterMessage($newsletter, $identity);

        $response = $this->actingAs($user)->patch(route('newsletters.filters.update', $newsletter), [
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$otherList->id],
                ],
            ],
        ]);

        $response->assertSessionHasErrors('filters.0.values');

        $newsletter->refresh();
        $this->assertSame([$list->id], $newsletter->filters[0]['values']);
    }

    public function test_send_newsletter_job_dispatches_batch_jobs(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->production()->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create([
                'status' => 'queued',
                'scheduled_at' => now(),
            ]);
        $this->updateNewsletterMessage($newsletter, $identity);

        $batch = $this->setupNewsletterBatchMocks($newsletter);

        app(SendNewsletterService::class)->send($newsletter, $batch);

        Bus::assertBatchCount(1);
        $this->assertCount(1, $batch->added);
        $this->assertInstanceOf(SendNewsletterChunkJob::class, $batch->added[0]);
        $this->assertTrue($batch->added[0]->newsletter->is($newsletter));
        $this->assertContains($contact->id, $batch->added[0]->contactIds);
    }

    public function test_send_newsletter_job_applies_attribute_and_tag_filters(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->production()->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $vip = Tag::factory()->for($user)->create(['name' => 'vip']);
        $trial = Tag::factory()->for($user)->create(['name' => 'trial']);

        $matching = Contact::factory()->for($user)->create([
            'extra_attributes' => ['status' => 'active', 'plan' => 'pro'],
        ]);
        $matching->tags()->attach([$vip->id, $trial->id]);

        $nonMatching = Contact::factory()->for($user)->create([
            'extra_attributes' => ['status' => 'inactive', 'plan' => 'pro'],
        ]);
        $nonMatching->tags()->attach([$vip->id]);

        $noneTagContact = Contact::factory()->for($user)->create([
            'extra_attributes' => ['status' => 'active', 'plan' => 'free'],
        ]);

        $list->contacts()->sync([$matching->id, $nonMatching->id, $noneTagContact->id]);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
            [
                'connector' => 'and',
                'category' => 'attributes',
                'mode' => 'all',
                'values' => [
                    ['key' => 'status', 'value' => 'active'],
                ],
            ],
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'all',
                'values' => ['vip', 'trial'],
            ],
        ];

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create([
                'status' => 'queued',
                'scheduled_at' => now(),
                'filters' => $filters,
            ]);
        $this->updateNewsletterMessage($newsletter, $identity);

        $batch = $this->setupNewsletterBatchMocks($newsletter);

        app(SendNewsletterService::class)->send($newsletter, $batch);

        Bus::assertBatchCount(1);
        $this->assertCount(1, $batch->added);
        $contactIds = collect($batch->added)->flatMap(fn (SendNewsletterChunkJob $job) => $job->contactIds)->all();
        $this->assertCount(1, $contactIds);
        $this->assertContains($matching->id, $contactIds);
    }

    public function test_send_newsletter_job_applies_single_tag_filter_selection(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->production()->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $vip = Tag::factory()->for($user)->create(['name' => 'vip']);
        $beta = Tag::factory()->for($user)->create(['name' => 'beta']);

        $matching = Contact::factory()->for($user)->create();
        $nonMatching = Contact::factory()->for($user)->create();
        $untagged = Contact::factory()->for($user)->create();

        $list->contacts()->sync([$matching->id, $nonMatching->id, $untagged->id]);

        $matching->tags()->attach($vip);
        $nonMatching->tags()->attach($beta);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'any',
                'values' => ['vip', null, ''],
            ],
        ];

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create([
                'status' => 'queued',
                'scheduled_at' => now(),
                'filters' => $filters,
            ]);
        $this->updateNewsletterMessage($newsletter, $identity);

        $batch = $this->setupNewsletterBatchMocks($newsletter);

        app(SendNewsletterService::class)->send($newsletter, $batch);

        Bus::assertBatchCount(1);
        $this->assertCount(1, $batch->added);
        $contactIds = collect($batch->added)->flatMap(fn (SendNewsletterChunkJob $job) => $job->contactIds)->all();
        $this->assertCount(1, $contactIds);
        $this->assertContains($matching->id, $contactIds);
    }

    public function test_user_can_view_contact_mail_details(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $mail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'subject' => 'Hello world',
            ]);

        $response = $this->actingAs($user)->get(route('contact-mails.show', $mail));

        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('contact-mails/Show')
            ->where('mail.subject', 'Hello world')
        );
    }

    public function test_user_cannot_view_contact_mail_for_other_accounts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $contact = Contact::factory()->for($otherUser)->create();
        $newsletter = Newsletter::factory()->for($otherUser)->create();

        $mail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'subject' => 'Private mail',
            ]);

        $response = $this->actingAs($user)->get(route('contact-mails.show', $mail));

        $response->assertNotFound();
    }

    public function test_contact_can_unsubscribe(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create(['unsubscribed_at' => null]);
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create([
                'status' => 'draft',
            ]);

        $this->updateNewsletterMessage($newsletter, $identity);

        $contactMail = ContactMail::factory()
            ->for($contact)
            ->for($newsletter, 'mailable')
            ->create([
                'status' => 'sent',
            ]);

        $this->get('/unsubscribe/'.$contactMail->uuid)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Unsubscribe'));

        $response = $this->post('/unsubscribe/'.$contactMail->uuid);

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Unsubscribed')
            ->where('contact.email', $contact->email)
        );

        $contact->refresh();
        $contactMail->refresh();

        $this->assertTrue($contact->unsubscribed_by_contact);
        $this->assertNotNull($contact->unsubscribed_at);
        $this->assertNotNull($contactMail->unsubscribed_at);
    }

    public function test_send_newsletter_job_dispatches_chunk_jobs_of_fourteen(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->production()->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contacts = Contact::factory()->count(43)->for($user)->create();
        $contacts->each(fn (Contact $contact) => $contact->lists()->attach($list));

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create([
                'status' => 'queued',
                'scheduled_at' => now(),
            ]);
        $this->updateNewsletterMessage($newsletter, $identity);

        $templateName = 'tmpl-'.$newsletter->id;

        $service = Mockery::mock(SesBulkEmailService::class);
        $this->app->instance(SesBulkEmailService::class, $service);

        $service->shouldReceive('createNewsletterTemplate')
            ->once()
            ->andReturn($templateName);

        $clientManager = Mockery::mock(SesClientManager::class);
        $this->app->instance(SesClientManager::class, $clientManager);
        $clientManager->shouldReceive('getMaxSendRateFor')->with(Mockery::on(fn ($u) => $u->id === $user->id))->andReturn(14);

        Bus::fake([SendNewsletterChunkJob::class]);

        $batch = Bus::batch([])->name("newsletter-{$newsletter->id}")->onQueue('newsletter')->dispatch();

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $jobs = $batch->added;

        $this->assertCount(4, $jobs);

        $chunkSizes = collect($jobs)->map(fn (SendNewsletterChunkJob $job) => count($job->contactIds))->all();
        $this->assertSame([14, 14, 14, 1], $chunkSizes);

        $allContactIds = collect($jobs)->flatMap(fn (SendNewsletterChunkJob $job) => $job->contactIds)->all();
        $this->assertCount(43, $allContactIds);
        $this->assertSame($contacts->pluck('id')->sort()->values()->all(), collect($allContactIds)->sort()->values()->all());
    }

    public function test_send_newsletter_job_caps_chunk_size_at_fifty_for_high_rate_users(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->production()->withTenant()->create(['max_send_rate' => 75]);
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contacts = Contact::factory()->count(75)->for($user)->create();
        $contacts->each(fn (Contact $contact) => $contact->lists()->attach($list));

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create([
                'status' => 'queued',
                'scheduled_at' => now(),
            ]);
        $this->updateNewsletterMessage($newsletter, $identity);

        $templateName = 'tmpl-'.$newsletter->id;

        $service = Mockery::mock(SesBulkEmailService::class);
        $this->app->instance(SesBulkEmailService::class, $service);
        $service->shouldReceive('createNewsletterTemplate')->once()->andReturn($templateName);

        $clientManager = Mockery::mock(SesClientManager::class);
        $this->app->instance(SesClientManager::class, $clientManager);
        $clientManager->shouldReceive('getMaxSendRateFor')->with(Mockery::on(fn ($u) => $u->id === $user->id))->andReturn(75);

        Bus::fake([SendNewsletterChunkJob::class]);

        $batch = Bus::batch([])->name("newsletter-{$newsletter->id}")->onQueue('newsletter')->dispatch();

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $jobs = $batch->added;

        $this->assertCount(3, $jobs);

        $chunkSizes = collect($jobs)->map(fn (SendNewsletterChunkJob $job) => count($job->contactIds))->all();
        $this->assertSame([37, 37, 1], $chunkSizes);

        $allContactIds = collect($jobs)->flatMap(fn (SendNewsletterChunkJob $job) => $job->contactIds)->all();
        $this->assertCount(75, $allContactIds);
    }

    public function test_send_newsletter_job_uses_default_chunk_for_sandbox_users(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create(['max_send_rate' => 1]);
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contacts = Contact::factory()->count(3)->for($user)->create();
        $contacts->each(fn (Contact $contact) => $contact->lists()->attach($list));

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create([
                'status' => 'queued',
                'scheduled_at' => now(),
            ]);
        $this->updateNewsletterMessage($newsletter, $identity);

        $templateName = 'tmpl-'.$newsletter->id;

        $service = Mockery::mock(SesBulkEmailService::class);
        $this->app->instance(SesBulkEmailService::class, $service);
        $service->shouldReceive('createNewsletterTemplate')->once()->andReturn($templateName);

        $clientManager = Mockery::mock(SesClientManager::class);
        $this->app->instance(SesClientManager::class, $clientManager);
        $clientManager->shouldReceive('getMaxSendRateFor')->with(Mockery::on(fn ($u) => $u->id === $user->id))->andReturn(1);

        Bus::fake([SendNewsletterChunkJob::class]);

        $batch = Bus::batch([])->name("newsletter-{$newsletter->id}")->onQueue('newsletter')->dispatch();

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $jobs = $batch->added;

        $this->assertCount(3, $jobs);

        $chunkSizes = collect($jobs)->map(fn (SendNewsletterChunkJob $job) => count($job->contactIds))->all();
        $this->assertSame([1, 1, 1], $chunkSizes);
    }

    public function test_send_newsletter_job_dispatches_chunks_without_delay(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->production()->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $contacts = Contact::factory()->count(42)->for($user)->create();
        $contacts->each(fn (Contact $contact) => $contact->lists()->attach($list));

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create([
                'status' => 'queued',
                'scheduled_at' => now(),
            ]);
        $this->updateNewsletterMessage($newsletter, $identity);

        $templateName = 'tmpl-'.$newsletter->id;

        $service = Mockery::mock(SesBulkEmailService::class);
        $this->app->instance(SesBulkEmailService::class, $service);

        $service->shouldReceive('createNewsletterTemplate')
            ->once()
            ->andReturn($templateName);

        $clientManager = Mockery::mock(SesClientManager::class);
        $this->app->instance(SesClientManager::class, $clientManager);
        $clientManager->shouldReceive('getMaxSendRateFor')->with(Mockery::on(fn ($u) => $u->id === $user->id))->andReturn(14);

        Bus::fake([SendNewsletterChunkJob::class]);

        $batch = Bus::batch([])->name("newsletter-{$newsletter->id}")->onQueue('newsletter')->dispatch();

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $jobs = $batch->added;

        $this->assertCount(3, $jobs);

        foreach ($jobs as $index => $job) {
            $this->assertNull($job->delay, "Job {$index} should have no delay");
        }
    }

    public function test_send_newsletter_saves_total_contacts_reached_before_dispatching(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->production()->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $contacts = Contact::factory()->count(3)->for($user)->create();
        $contacts->each(fn (Contact $contact) => $contact->lists()->attach($list));

        // Unsubscribed contact should not be counted
        $unsubscribed = Contact::factory()->for($user)->create(['unsubscribed_at' => now()]);
        $unsubscribed->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create([
                'status' => 'queued',
                'scheduled_at' => now(),
            ]);
        $this->updateNewsletterMessage($newsletter, $identity);

        $batch = $this->setupNewsletterBatchMocks($newsletter);

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $newsletter->refresh();
        $this->assertSame(3, $newsletter->total_contacts_reached);
    }

    public function test_send_newsletter_saves_total_contacts_reached_with_filters(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->production()->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $vip = Tag::factory()->for($user)->create(['name' => 'vip']);

        $matching = Contact::factory()->count(2)->for($user)->create();
        $matching->each(function (Contact $contact) use ($list, $vip): void {
            $contact->lists()->attach($list);
            $contact->tags()->attach($vip);
        });

        $nonMatching = Contact::factory()->for($user)->create();
        $nonMatching->lists()->attach($list);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'any',
                'values' => ['vip'],
            ],
        ];

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create([
                'status' => 'queued',
                'scheduled_at' => now(),
                'filters' => $filters,
            ]);
        $this->updateNewsletterMessage($newsletter, $identity);

        $batch = $this->setupNewsletterBatchMocks($newsletter);

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $newsletter->refresh();
        $this->assertSame(2, $newsletter->total_contacts_reached);
    }

    public function test_batch_callback_updates_total_contacts_reached_with_actual_count(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->production()->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $contacts = Contact::factory()->count(5)->for($user)->create();
        $contacts->each(fn (Contact $contact) => $contact->lists()->attach($list));

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create([
                'status' => 'draft',
                'scheduled_at' => now(),
            ]);
        $this->updateNewsletterMessage($newsletter, $identity);

        $templateName = 'tmpl-'.$newsletter->id;
        $newsletter->update(['template_name' => $templateName]);

        $service = Mockery::mock(SesBulkEmailService::class);
        $this->app->instance(SesBulkEmailService::class, $service);

        $service->shouldReceive('deleteTemplate')
            ->once()
            ->with($templateName, Mockery::on(fn ($u) => $u->is($user)));

        Bus::fake([SendNewsletterJob::class, SendNewsletterChunkJob::class]);

        (new DispatchNewsletterBatchJob($newsletter))->handle();

        // Simulate only 3 contact mails actually created by chunk jobs
        ContactMail::factory()
            ->count(3)
            ->forNewsletter($newsletter)
            ->sequence(
                ...array_map(fn ($c) => ['contact_id' => $c->id], $contacts->take(3)->all())
            )
            ->create();

        // Execute the batch finally callback. In the worker it runs inside the
        // last batch job, which carries the tenant of the newsletter.
        $this->actingAsTenant($user->client_id);
        $pendingBatch = Bus::dispatchedBatches()[0];
        $batch = $this->createStub(Batch::class);

        $finallyCallbacks = $pendingBatch->finallyCallbacks();
        $this->assertNotEmpty($finallyCallbacks);
        $finallyCallbacks[0]($batch);

        $newsletter->refresh();
        $this->assertSame(NewsletterStatus::Sent, $newsletter->status);
        $this->assertNotNull($newsletter->sent_at);
        $this->assertSame(3, $newsletter->total_contacts_reached);
    }

    public function test_batch_callback_updates_total_contacts_reached_for_ab_test(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->production()->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $contacts = Contact::factory()->count(4)->for($user)->create();
        $contacts->each(fn (Contact $contact) => $contact->lists()->attach($list));

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withAbTest()
            ->withListFilters([$list->id])
            ->create([
                'status' => 'draft',
                'scheduled_at' => now(),
            ]);
        $this->updateNewsletterMessage($newsletter, $identity);
        $newsletter->messageB()->withoutGlobalScopes()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
        ]);

        $templateNameA = 'tmpl-'.$newsletter->id;
        $templateNameB = 'tmpl-'.$newsletter->id.'-b';
        $newsletter->update([
            'template_name' => $templateNameA,
            'ab_template_name' => $templateNameB,
        ]);

        $service = Mockery::mock(SesBulkEmailService::class);
        $this->app->instance(SesBulkEmailService::class, $service);

        $service->shouldReceive('deleteTemplate')
            ->twice();

        Bus::fake([SendNewsletterJob::class, SendNewsletterChunkJob::class]);

        (new DispatchNewsletterBatchJob($newsletter))->handle();

        // Simulate contact mails created by chunk jobs
        ContactMail::factory()
            ->count(4)
            ->forNewsletter($newsletter)
            ->sequence(
                ...array_map(fn ($c) => ['contact_id' => $c->id], $contacts->all())
            )
            ->create();

        // Execute the batch finally callback. In the worker it runs inside the
        // last batch job, which carries the tenant of the newsletter.
        $this->actingAsTenant($user->client_id);
        $pendingBatch = Bus::dispatchedBatches()[0];
        $batch = $this->createStub(Batch::class);

        $finallyCallbacks = $pendingBatch->finallyCallbacks();
        $this->assertNotEmpty($finallyCallbacks);
        $finallyCallbacks[0]($batch);

        $newsletter->refresh();
        $this->assertSame(NewsletterStatus::AbTesting, $newsletter->status);
        $this->assertSame(4, $newsletter->total_contacts_reached);
    }

    public function test_batch_callbacks_are_static_and_serializable(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->production()->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create(['status' => 'draft', 'scheduled_at' => now()]);
        $this->updateNewsletterMessage($newsletter, $identity);

        Bus::fake([SendNewsletterJob::class]);

        (new DispatchNewsletterBatchJob($newsletter))->handle();

        $pendingBatch = Bus::dispatchedBatches()[0];

        $callbacks = [
            ...$pendingBatch->thenCallbacks(),
            ...$pendingBatch->catchCallbacks(),
            ...$pendingBatch->finallyCallbacks(),
        ];

        $this->assertCount(1, $callbacks);

        foreach ($callbacks as $callback) {
            $closure = $callback instanceof SerializableClosure ? $callback->getClosure() : $callback;
            $reflection = new ReflectionFunction($closure);

            $this->assertNull(
                $reflection->getClosureThis(),
                'Batch callbacks must not bind $this — a bound job pulls the queue/console object graph into closure serialization (SerializableClosure InputArgument error).'
            );

            $this->assertIsString(serialize(new SerializableClosure($closure)));
        }
    }

    /**
     * @return Batch
     */
    private function setupNewsletterBatchMocks(Newsletter $newsletter): mixed
    {
        $templateName = 'tmpl-'.$newsletter->id;

        $service = Mockery::mock(SesBulkEmailService::class);
        $this->app->instance(SesBulkEmailService::class, $service);

        $service->shouldReceive('createNewsletterTemplate')
            ->once()
            ->withArgs(fn (Newsletter $model, ?User $user = null): bool => $model->is($newsletter) && $user?->is($newsletter->user))
            ->andReturn($templateName);

        Bus::fake([SendNewsletterChunkJob::class]);

        return Bus::batch([])
            ->name("newsletter-{$newsletter->id}")
            ->onQueue('newsletter')
            ->dispatch();
    }

    private function updateNewsletterMessage(Newsletter $newsletter, EmailIdentity $identity, array $overrides = []): void
    {
        $newsletter->message()->withoutGlobalScopes()->update(array_merge([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>'.fake()->sentence().'</p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
            'text_content' => fake()->sentence(),
            'markdown_content' => '# '.fake()->sentence(),
        ], $overrides));
    }
}
