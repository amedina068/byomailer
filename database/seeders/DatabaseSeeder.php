<?php

namespace Database\Seeders;

use App\Actions\SeedClientDefaultTemplate;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use App\Models\VisualAutomation;
use App\Models\VisualAutomationContactStep;
use App\Models\VisualAutomationEnrollment;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The demo data has a login with a known password and fake AWS keys.
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder does not run in production. Use php artisan byomailer:install to create the owner account.');
        }

        $user = User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
            'client_id' => Client::factory()->create([
                'timezone' => 'America/New_York',
            ])->id,
        ]);

        // Each client-scoped query needs a tenant, as in a request of the app.
        Tenant::run($user->client_id, fn () => $this->seedDemoClient($user));

        $this->seedClient('Acme Co', 'owner@acme.test');
        $this->seedClient('Northwind', 'owner@northwind.test');
        $this->seedClient('Free Tier Co', 'owner@freetier.test');
        $this->seedClient('Trial Labs', 'owner@triallabs.test');
    }

    /**
     * Seed the data of the demo user: identities, lists, topics, tags, 500
     * contacts, newsletters, sequences, automations, mails and logs.
     */
    private function seedDemoClient(User $user): void
    {
        EmailCredential::factory()->for($user)->create([
            'access_key' => env('TEST_AWS_KEY_ID', 'AKIADEMOUSER12345678'),
            'secret_key' => env('TEST_AWS_KEY_SECRET', 'demo-secret-access-key-value-1234567890-cred'),
            'region' => env('TEST_AWS_REGION', 'us-east-1'),
            'sns_topic_arn' => env('TEST_SNS_TOPIC_ARN', 'arn:aws:sns:us-east-1:123456789012:ses-webhooks'),
            'sns_subscription_arn' => env('TEST_SNS_SUBSCRIPTION_ARN', 'arn:aws:sns:us-east-1:123456789012:ses-webhooks:00000000-0000-0000-0000-000000000000'),
        ]);

        $fromEmail = env('TEST_EMAIL_FROM', 'updates@example.com');
        $fromName = env('TEST_EMAIL_NAME', 'Updates Team');
        $domain = env('TEST_EMAIL_DOMAIN');
        $mailFromSubdomain = env('TEST_EMAIL_FROM_SUBDOMAIN');
        $emailRegion = env('TEST_EMAIL_REGION', env('TEST_AWS_REGION', 'us-east-1'));
        $emailStatus = env('TEST_EMAIL_STATUS', 'verified');

        $emailIdentities = EmailIdentity::factory()
            ->count(2)
            ->for($user)
            ->sequence(
                [
                    'email' => $fromEmail,
                    'name' => $fromName,
                    'configuration_set_name' => env('TEST_EMAIL_CONFIGURATION_SET'),
                    'domain' => $domain,
                    'mail_from_subdomain' => $mailFromSubdomain,
                    'region' => $emailRegion,
                    'identity_status' => $emailStatus,
                    'verified_at' => $emailStatus === 'verified' ? now() : null,
                ],
                [
                    'email' => 'announcements@'.($domain ?? 'example.com'),
                    'name' => 'Announcements',
                    'domain' => $domain,
                    'mail_from_subdomain' => $mailFromSubdomain,
                    'region' => $emailRegion,
                    'identity_status' => $emailStatus,
                    'verified_at' => $emailStatus === 'verified' ? now()->subDay() : null,
                ],
            )
            ->create();

        $lists = MailList::factory()
            ->count(3)
            ->for($user)
            ->sequence(
                ['name' => 'Customers'],
                ['name' => 'Leads'],
                ['name' => 'VIP Members'],
            )
            ->create();

        $topicsByList = [
            'Customers' => ['Product Updates', 'Billing & Account', 'Tips & Tutorials'],
            'Leads' => ['Case Studies', 'Webinars'],
            'VIP Members' => ['Early Access', 'Private Events', 'Insider Digest'],
        ];

        foreach ($lists as $list) {
            foreach ($topicsByList[$list->name] ?? [] as $topicName) {
                Topic::factory()->create([
                    'user_id' => $user->id,
                    'client_id' => $user->client_id,
                    'list_id' => $list->id,
                    'name' => $topicName,
                    'description' => fake()->sentence(),
                ]);
            }
        }

        $tags = Tag::factory()
            ->count(5)
            ->for($user)
            ->sequence(
                ['name' => 'vip'],
                ['name' => 'trial'],
                ['name' => 'prospect'],
                ['name' => 'premium'],
                ['name' => 'churn-risk'],
            )
            ->create();

        $contacts = Contact::factory()
            ->count(500)
            ->for($user)
            ->create();

        $contacts->each(function (Contact $contact) use ($lists, $tags): void {
            $contact->forceFill([
                'extra_attributes' => [
                    'plan' => Arr::random(['free', 'starter', 'pro', 'enterprise']),
                    'status' => Arr::random(['active', 'inactive']),
                ],
            ])->save();

            $listSelection = $lists->random(rand(1, $lists->count()));
            $listIds = $listSelection instanceof MailList
                ? [$listSelection->id]
                : $listSelection->pluck('id')->all();
            $contact->syncLists($listIds, withoutDetaching: true);

            $tagSelection = $tags->random(rand(1, $tags->count()));
            $tagIds = $tagSelection instanceof Tag
                ? [$tagSelection->id]
                : $tagSelection->pluck('id')->all();
            $contact->tags()->syncWithoutDetaching($tagIds);
        });

        $testContact = Contact::factory()->for($user)->create([
            'email' => 'demo@example.com',
            'name' => 'Test Contact',
            'extra_attributes' => ['test-contact' => true],
        ]);
        $testContact->syncLists([$lists->first()->id], withoutDetaching: true);
        $testContact->syncAttributeDefinitions();

        $localTestList = MailList::factory()->for($user)->create(['name' => 'local-test']);

        $simulatorAddresses = [
            'success@simulator.amazonses.com' => 'SES Success',
            'bounce@simulator.amazonses.com' => 'SES Bounce',
            'complaint@simulator.amazonses.com' => 'SES Complaint',
            'ooto@simulator.amazonses.com' => 'SES Out-of-Office',
            'suppressionlist@simulator.amazonses.com' => 'SES Suppression List',
        ];

        $localTestContacts = collect($simulatorAddresses)->map(function (string $name, string $email) use ($user): Contact {
            return Contact::factory()->for($user)->create([
                'email' => $email,
                'name' => $name,
                'extra_attributes' => ['local-test' => true],
            ]);
        })->push($testContact);

        $localTestContacts->each(function (Contact $contact) use ($localTestList): void {
            $contact->syncLists([$localTestList->id], withoutDetaching: true);
        });

        $pivotIds = DB::table('contact_topic')->pluck('id')->shuffle();
        $unsubscribeIds = $pivotIds->take((int) ($pivotIds->count() * 0.25))->all();

        if (! empty($unsubscribeIds)) {
            DB::table('contact_topic')
                ->whereIn('id', $unsubscribeIds)
                ->update(['unsubscribed_at' => now()]);
        }

        $sentNewsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$lists->first()->id])
            ->create([
                'status' => 'sent',
                'sent_at' => now()->subDays(2),
                'scheduled_at' => now()->subDays(3),
            ]);
        $sentMarkdown = "# Welcome Series\n\nThanks for joining us!";
        $sentNewsletter->message()->update([
            'email_identity_id' => $emailIdentities->first()->id,
            'subject' => 'Welcome Series',
            'from_email' => $emailIdentities->first()->email,
            'from_name' => $emailIdentities->first()->name,
            'markdown_content' => $sentMarkdown,
            'html_content' => Str::markdown($sentMarkdown),
            'text_content' => strip_tags(Str::markdown($sentMarkdown)),
        ]);

        $draftNewsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$lists->get(1)->id])
            ->create([
                'status' => 'draft',
                'scheduled_at' => null,
            ]);
        $draftMarkdown = "# Upcoming Features\n\nStay tuned for next week's updates.";
        $draftNewsletter->message()->update([
            'email_identity_id' => $emailIdentities->last()->id,
            'subject' => 'Upcoming Features',
            'from_email' => $emailIdentities->last()->email,
            'from_name' => $emailIdentities->last()->name,
            'markdown_content' => $draftMarkdown,
            'html_content' => Str::markdown($draftMarkdown),
            'text_content' => strip_tags(Str::markdown($draftMarkdown)),
        ]);

        $lists->first()->contacts->each(function (Contact $contact, int $index) use ($sentNewsletter): void {
            $status = match (true) {
                $index % 7 === 0 => 'bounced',
                $index % 5 === 0 => 'unsubscribed',
                default => 'sent',
            };

            $contactMail = ContactMail::create([
                'contact_id' => $contact->id,
                'mailable_type' => Newsletter::class,
                'mailable_id' => $sentNewsletter->id,
                'sent_at' => now()->subDays(2),
                'bounced_at' => $status === 'bounced' ? now()->subDays(1) : null,
                'unsubscribed_at' => $status === 'unsubscribed' ? now()->subDay() : null,
                'template_data' => [],
                'status' => $status,
            ]);

            if ($status === 'unsubscribed') {
                $contact->forceFill([
                    'unsubscribed_at' => $contactMail->unsubscribed_at,
                    'unsubscribed_by_contact' => true,
                ])->save();
            }
        });

        $sequences = Sequence::factory()
            ->count(2)
            ->for($user)
            ->sequence(
                ['name' => 'Welcome Journey', 'status' => 'live'],
                ['name' => 'Re-Engagement', 'status' => 'draft'],
            )
            ->create();

        $sequences->each(function (Sequence $sequence): void {
            SequenceStep::factory()
                ->count(3)
                ->for($sequence)
                ->sequence(
                    ['delay_days' => 0, 'delay_type' => 'days'],
                    ['delay_days' => 6, 'delay_type' => 'hours'],
                    ['delay_days' => 5, 'delay_type' => 'days'],
                )
                ->create();
        });

        if ($sequences->isNotEmpty()) {
            $contacts->each(function (Contact $contact, int $index) use ($sequences): void {
                $sequence = $sequences[$index % $sequences->count()];
                $contact->syncSequences([$sequence->id], true);
            });

            $sequences->each(function (Sequence $sequence) use ($contacts): void {
                $sequence->loadMissing('steps');

                if ($sequence->steps->isEmpty()) {
                    return;
                }

                $sampleContacts = $contacts->take(6);
                $sampleContacts->each(function (Contact $contact, int $index) use ($sequence): void {
                    $step = $sequence->steps[$index % $sequence->steps->count()];

                    // The enrollment above can schedule this step already. A
                    // contact has one mail for each step.
                    $scheduled = ContactMail::query()
                        ->where('contact_id', $contact->id)
                        ->where('mailable_type', SequenceStep::class)
                        ->where('mailable_id', $step->id)
                        ->exists();

                    if ($scheduled) {
                        return;
                    }

                    $mail = ContactMail::factory()
                        ->for($contact)
                        ->forSequenceStep($step)
                        ->clicked()
                        ->create([
                            'status' => $index % 4 === 0 ? 'unsubscribed' : 'opened',
                            'unsubscribed_at' => $index % 4 === 0 ? now()->subHours(3) : null,
                        ]);

                    if ($mail->unsubscribed_at && ! $contact->unsubscribed_at) {
                        $contact->forceFill([
                            'unsubscribed_at' => $mail->unsubscribed_at,
                            'unsubscribed_by_contact' => true,
                        ])->save();
                    }

                    ContactMailClick::create([
                        'contact_mail_id' => $mail->id,
                        'contact_id' => $contact->id,
                        'link' => 'https://example.com/'.Str::slug($sequence->name).'/'.($index + 1),
                        'clicked_at' => now()->subMinutes(rand(10, 200)),
                    ]);
                });
            });
        }

        ContactImport::factory()
            ->completed()
            ->for($user)
            ->for($lists->first(), 'mailList')
            ->create([
                'original_filename' => 'demo-contacts.csv',
                'total_count' => 125,
                'processed_count' => 125,
                'skipped_count' => 0,
            ]);

        ContactImport::factory()
            ->failed()
            ->for($user)
            ->for($lists->last(), 'mailList')
            ->create([
                'original_filename' => 'vip-import.csv',
                'total_count' => 80,
                'processed_count' => 40,
                'skipped_count' => 40,
                'error_message' => 'Invalid header row detected',
            ]);

        $customersListId = $lists->firstWhere('name', 'Customers')->id;
        $leadsListId = $lists->firstWhere('name', 'Leads')->id;
        $vipListId = $lists->firstWhere('name', 'VIP Members')->id;
        $vipTagId = $tags->firstWhere('name', 'vip')->id;
        $trialTagId = $tags->firstWhere('name', 'trial')->id;
        $premiumTagId = $tags->firstWhere('name', 'premium')->id;
        $churnTagId = $tags->firstWhere('name', 'churn-risk')->id;
        $welcomeSequenceId = $sequences->firstWhere('name', 'Welcome Journey')->id;

        // Automation 1: New Lead Nurture (live, with contacts at various stages)
        $leadNurture = VisualAutomation::factory()->for($user)->live()->create([
            'name' => 'New Lead Nurture',
            'workflow' => [
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'contact_added_to_list', 'value' => $leadsListId]],
                    ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => 0, 'y' => 280], 'data' => ['action_type' => 'add_tag', 'value' => $trialTagId]],
                    ['id' => 'delay-1', 'type' => 'delay', 'position' => ['x' => 0, 'y' => 560], 'data' => ['duration' => 3, 'unit' => 'days']],
                    ['id' => 'action-2', 'type' => 'action', 'position' => ['x' => 0, 'y' => 840], 'data' => ['action_type' => 'add_sequence', 'value' => $welcomeSequenceId]],
                    ['id' => 'delay-2', 'type' => 'delay', 'position' => ['x' => 0, 'y' => 1120], 'data' => ['duration' => 7, 'unit' => 'days']],
                    ['id' => 'cond-1', 'type' => 'condition', 'position' => ['x' => 0, 'y' => 1400], 'data' => ['condition_type' => 'has_tag', 'value' => $premiumTagId]],
                    ['id' => 'action-3', 'type' => 'action', 'position' => ['x' => -200, 'y' => 1680], 'data' => ['action_type' => 'add_list', 'value' => $vipListId]],
                    ['id' => 'action-4', 'type' => 'action', 'position' => ['x' => 200, 'y' => 1680], 'data' => ['action_type' => 'add_tag', 'value' => $churnTagId]],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'action-1'],
                    ['id' => 'e2', 'source' => 'action-1', 'target' => 'delay-1'],
                    ['id' => 'e3', 'source' => 'delay-1', 'target' => 'action-2'],
                    ['id' => 'e4', 'source' => 'action-2', 'target' => 'delay-2'],
                    ['id' => 'e5', 'source' => 'delay-2', 'target' => 'cond-1'],
                    ['id' => 'e6', 'source' => 'cond-1', 'target' => 'action-3', 'sourceHandle' => 'yes'],
                    ['id' => 'e7', 'source' => 'cond-1', 'target' => 'action-4', 'sourceHandle' => 'no'],
                ],
            ],
        ]);

        // Enroll contacts at different stages
        $subscribedContacts = $contacts->filter(fn (Contact $c) => $c->unsubscribed_at === null)->values();

        // 8 contacts waiting at delay-1
        $subscribedContacts->slice(0, 8)->each(function (Contact $contact) use ($leadNurture): void {
            $enrollment = VisualAutomationEnrollment::create([
                'visual_automation_id' => $leadNurture->id,
                'contact_id' => $contact->id,
                'status' => 'active',
                'enrolled_at' => now()->subDays(2),
            ]);
            VisualAutomationContactStep::create([
                'enrollment_id' => $enrollment->id,
                'node_id' => 'action-1',
                'status' => VisualAutomationContactStep::STATUS_COMPLETED,
                'processed_at' => now()->subDays(2),
            ]);
            VisualAutomationContactStep::create([
                'enrollment_id' => $enrollment->id,
                'node_id' => 'delay-1',
                'status' => VisualAutomationContactStep::STATUS_WAITING,
                'wait_until' => now()->addDay(),
            ]);
        });

        // 5 contacts waiting at the sequence step (action-2)
        $subscribedContacts->slice(8, 5)->each(function (Contact $contact) use ($leadNurture): void {
            $enrollment = VisualAutomationEnrollment::create([
                'visual_automation_id' => $leadNurture->id,
                'contact_id' => $contact->id,
                'status' => 'active',
                'enrolled_at' => now()->subDays(5),
            ]);
            VisualAutomationContactStep::create([
                'enrollment_id' => $enrollment->id,
                'node_id' => 'action-1',
                'status' => VisualAutomationContactStep::STATUS_COMPLETED,
                'processed_at' => now()->subDays(5),
            ]);
            VisualAutomationContactStep::create([
                'enrollment_id' => $enrollment->id,
                'node_id' => 'delay-1',
                'status' => VisualAutomationContactStep::STATUS_COMPLETED,
                'processed_at' => now()->subDays(2),
            ]);
            VisualAutomationContactStep::create([
                'enrollment_id' => $enrollment->id,
                'node_id' => 'action-2',
                'status' => VisualAutomationContactStep::STATUS_WAITING,
            ]);
        });

        // 3 contacts waiting at delay-2
        $subscribedContacts->slice(13, 3)->each(function (Contact $contact) use ($leadNurture): void {
            $enrollment = VisualAutomationEnrollment::create([
                'visual_automation_id' => $leadNurture->id,
                'contact_id' => $contact->id,
                'status' => 'active',
                'enrolled_at' => now()->subDays(10),
            ]);
            VisualAutomationContactStep::create([
                'enrollment_id' => $enrollment->id,
                'node_id' => 'action-1',
                'status' => VisualAutomationContactStep::STATUS_COMPLETED,
                'processed_at' => now()->subDays(10),
            ]);
            VisualAutomationContactStep::create([
                'enrollment_id' => $enrollment->id,
                'node_id' => 'delay-1',
                'status' => VisualAutomationContactStep::STATUS_COMPLETED,
                'processed_at' => now()->subDays(7),
            ]);
            VisualAutomationContactStep::create([
                'enrollment_id' => $enrollment->id,
                'node_id' => 'action-2',
                'status' => VisualAutomationContactStep::STATUS_COMPLETED,
                'processed_at' => now()->subDays(7),
            ]);
            VisualAutomationContactStep::create([
                'enrollment_id' => $enrollment->id,
                'node_id' => 'delay-2',
                'status' => VisualAutomationContactStep::STATUS_WAITING,
                'wait_until' => now()->addDays(3),
            ]);
        });

        // 10 completed enrollments
        $subscribedContacts->slice(16, 10)->each(function (Contact $contact) use ($leadNurture): void {
            VisualAutomationEnrollment::create([
                'visual_automation_id' => $leadNurture->id,
                'contact_id' => $contact->id,
                'status' => 'completed',
                'enrolled_at' => now()->subDays(20),
                'completed_at' => now()->subDays(3),
            ]);
        });

        // Automation 2: VIP Upgrade (draft)
        VisualAutomation::factory()->for($user)->create([
            'name' => 'VIP Upgrade Path',
            'status' => 'draft',
            'workflow' => [
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'tag_added', 'value' => $premiumTagId]],
                    ['id' => 'delay-1', 'type' => 'delay', 'position' => ['x' => 0, 'y' => 280], 'data' => ['duration' => 1, 'unit' => 'days']],
                    ['id' => 'cond-1', 'type' => 'condition', 'position' => ['x' => 0, 'y' => 560], 'data' => ['condition_type' => 'in_list', 'value' => $customersListId]],
                    ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => -200, 'y' => 840], 'data' => ['action_type' => 'add_tag', 'value' => $vipTagId]],
                    ['id' => 'action-2', 'type' => 'action', 'position' => ['x' => -200, 'y' => 1120], 'data' => ['action_type' => 'add_list', 'value' => $vipListId]],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'delay-1'],
                    ['id' => 'e2', 'source' => 'delay-1', 'target' => 'cond-1'],
                    ['id' => 'e3', 'source' => 'cond-1', 'target' => 'action-1', 'sourceHandle' => 'yes'],
                    ['id' => 'e4', 'source' => 'action-1', 'target' => 'action-2'],
                ],
            ],
        ]);

        // Automation 3: Churn Prevention (live, simple)
        $churnPrevention = VisualAutomation::factory()->for($user)->live()->create([
            'name' => 'Churn Prevention',
            'workflow' => [
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'tag_added', 'value' => $churnTagId]],
                    ['id' => 'delay-1', 'type' => 'delay', 'position' => ['x' => 0, 'y' => 280], 'data' => ['duration' => 2, 'unit' => 'days']],
                    ['id' => 'cond-1', 'type' => 'condition', 'position' => ['x' => 0, 'y' => 560], 'data' => ['condition_type' => 'has_tag', 'value' => $churnTagId]],
                    ['id' => 'action-1', 'type' => 'action', 'position' => ['x' => -200, 'y' => 840], 'data' => ['action_type' => 'remove_tag', 'value' => $churnTagId]],
                    ['id' => 'action-2', 'type' => 'action', 'position' => ['x' => -200, 'y' => 1120], 'data' => ['action_type' => 'add_list', 'value' => $vipListId]],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'delay-1'],
                    ['id' => 'e2', 'source' => 'delay-1', 'target' => 'cond-1'],
                    ['id' => 'e3', 'source' => 'cond-1', 'target' => 'action-1', 'sourceHandle' => 'yes'],
                    ['id' => 'e4', 'source' => 'action-1', 'target' => 'action-2'],
                ],
            ],
        ]);

        // 4 contacts waiting at the delay
        $subscribedContacts->slice(26, 4)->each(function (Contact $contact) use ($churnPrevention): void {
            $enrollment = VisualAutomationEnrollment::create([
                'visual_automation_id' => $churnPrevention->id,
                'contact_id' => $contact->id,
                'status' => 'active',
                'enrolled_at' => now()->subDay(),
            ]);
            VisualAutomationContactStep::create([
                'enrollment_id' => $enrollment->id,
                'node_id' => 'delay-1',
                'status' => VisualAutomationContactStep::STATUS_WAITING,
                'wait_until' => now()->addDay(),
            ]);
        });

        app(DefaultEmailTemplateSeeder::class)->run();
        app(SeedClientDefaultTemplate::class)->handle($user->client, $user);

        $starterFooter = '<p style="font-size:12px;color:#666;text-align:center;"><a href="{{ unsubscribe_url }}">Unsubscribe</a> · <a href="{{ preferences_url }}">Manage preferences</a></p>';

        EmailTemplate::factory()->system()->create([
            'name' => 'Simple Welcome',
            'html_content' => '<h1>Welcome, {{ name }}!</h1><p>{{ content }}</p>'.$starterFooter,
        ]);

        EmailTemplate::factory()->system()->create([
            'name' => 'Product Announcement',
            'html_content' => '<h1>Something new is here</h1><p>Hi {{ name }},</p><p>{{ content }}</p>'.$starterFooter,
        ]);

        EmailTemplate::factory()->system()->create([
            'name' => 'Plain Text Style',
            'html_content' => '<p>Hi {{ name }},</p><p>{{ content }}</p><p>Best,<br>The Team</p>'.$starterFooter,
        ]);

        EmailTemplate::factory()->for($user)->create([
            'name' => 'My Newsletter Layout',
            'html_content' => '<h2>Weekly Update</h2><p>Hi {{ name }}, here\'s what happened this week.</p><p>{{ content }}</p><hr><p>Thanks for reading!</p>'.$starterFooter,
        ]);

        EmailTemplate::factory()->for($user)->create([
            'name' => 'Re-engagement Email',
            'html_content' => '<h1>We miss you, {{ name }}!</h1><p>{{ content }}</p>'.$starterFooter,
        ]);

        $sampleContacts = $contacts->take(30);
        $logEntries = [];

        $sampleContacts->each(function (Contact $contact, int $index) use ($user, $fromEmail, &$logEntries): void {
            $loggedAt = now()->subMinutes(rand(10, 10080));
            $messageId = fake()->uuid();

            $status = match (true) {
                $index % 15 === 0 => 'bounced',
                $index % 12 === 0 => 'complained',
                $index % 3 === 0 => 'opened',
                $index % 5 !== 0 => 'delivered',
                default => 'sent',
            };

            $snsPayload = match ($status) {
                'delivered' => ['notificationType' => 'Delivery', 'mail' => ['messageId' => $messageId]],
                'opened' => ['notificationType' => 'Open', 'mail' => ['messageId' => $messageId]],
                'bounced' => ['notificationType' => 'Bounce', 'bounce' => ['bounceType' => 'Permanent'], 'mail' => ['messageId' => $messageId]],
                'complained' => ['notificationType' => 'Complaint', 'complaint' => ['complaintFeedbackType' => 'abuse'], 'mail' => ['messageId' => $messageId]],
                default => null,
            };

            $updatedAt = $snsPayload ? $loggedAt->copy()->addSeconds(rand(1, 120)) : $loggedAt;

            $logEntries[] = [
                'client_id' => $user->client_id,
                'provider' => 'ses',
                'operation' => 'SendEmail',
                'recipient' => $contact->email,
                'message_id' => $messageId,
                'status' => $status,
                'error' => $status === 'bounced' ? 'Permanent bounce: address does not exist' : null,
                'request_payload' => json_encode(['from' => $fromEmail, 'to' => $contact->email, 'subject' => 'Welcome Series']),
                'response_payload' => json_encode(['MessageId' => $messageId]),
                'sns_payload' => $snsPayload ? json_encode($snsPayload) : null,
                'logged_at' => $loggedAt,
                'created_at' => $loggedAt,
                'updated_at' => $updatedAt,
            ];
        });

        // A few failed API calls
        for ($i = 0; $i < 5; $i++) {
            $loggedAt = now()->subMinutes(rand(30, 7200));
            $logEntries[] = [
                'client_id' => $user->client_id,
                'provider' => 'ses',
                'operation' => Arr::random(['SendEmail', 'SendBulkEmail', 'CreateEmailTemplate']),
                'recipient' => $i < 3 ? fake()->email() : null,
                'message_id' => null,
                'status' => 'failed',
                'error' => Arr::random([
                    'MessageRejected: Email address is not verified.',
                    'Throttling: Maximum sending rate exceeded.',
                    'InvalidParameterValue: The template does not exist.',
                ]),
                'request_payload' => json_encode(['from' => $fromEmail]),
                'response_payload' => null,
                'sns_payload' => null,
                'logged_at' => $loggedAt,
                'created_at' => $loggedAt,
                'updated_at' => $loggedAt,
            ];
        }

        EmailLog::insert($logEntries);

        app(SegmentSeeder::class)->seedDemoSegments($user->client, $customersListId);
    }

    /**
     * Seed a self-contained client with a verified identity, lists, contacts,
     * a draft newsletter, and a live sequence.
     */
    private function seedClient(string $clientName, string $ownerEmail): void
    {
        $client = Client::factory()->create([
            'name' => $clientName,
            'timezone' => 'America/New_York',
        ]);

        $user = User::factory()->for($client)->create([
            'name' => $clientName.' Owner',
            'email' => $ownerEmail,
        ]);

        Tenant::run($client->id, fn () => $this->seedClientData($client, $user));
    }

    private function seedClientData(Client $client, User $user): void
    {
        $clientName = $client->name;

        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIA'.Str::upper(Str::random(16)),
            'secret_key' => Str::random(40),
            'region' => 'us-east-1',
            'can_create_template' => true,
            'can_delete_template' => true,
            'can_send_bulk_email' => true,
            'can_send_email' => true,
            'permissions_checked_at' => now(),
        ]);

        $domain = Str::slug($clientName).'.test';
        $identities = EmailIdentity::factory()
            ->count(2)
            ->for($user)
            ->sequence(
                ['email' => 'updates@'.$domain, 'name' => $clientName.' Updates'],
                ['email' => 'hello@'.$domain, 'name' => $clientName.' Hello'],
            )
            ->create([
                'domain' => $domain,
                'region' => 'us-east-1',
                'identity_status' => 'verified',
                'verified_at' => now(),
            ]);

        $lists = MailList::factory()
            ->count(2)
            ->for($user)
            ->sequence(['name' => 'Subscribers'], ['name' => 'Customers'])
            ->create();

        $contacts = Contact::factory()->count(30)->for($user)->create();
        $contacts->each(function (Contact $contact) use ($lists): void {
            $contact->syncLists([$lists->random()->id], withoutDetaching: true);
        });

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$lists->first()->id])
            ->create(['status' => 'draft', 'scheduled_at' => null]);
        $markdown = "# Hello from {$clientName}\n\nThanks for subscribing.";
        $newsletter->message()->update([
            'email_identity_id' => $identities->first()->id,
            'subject' => "Hello from {$clientName}",
            'from_email' => $identities->first()->email,
            'from_name' => $identities->first()->name,
            'markdown_content' => $markdown,
            'html_content' => Str::markdown($markdown),
            'text_content' => strip_tags(Str::markdown($markdown)),
        ]);

        $segment = app(SegmentSeeder::class)->seedClientSegment(
            $client,
            $lists->firstWhere('name', 'Customers'),
        );

        $sequence = Sequence::factory()
            ->for($user)
            ->create([
                'name' => $clientName.' Welcome',
                'status' => 'live',
                'segment_id' => $segment->id,
                'filters' => [],
            ]);
        SequenceStep::factory()
            ->count(2)
            ->for($sequence)
            ->sequence(
                ['delay_days' => 0, 'delay_type' => 'days', 'status' => 'live'],
                ['delay_days' => 3, 'delay_type' => 'days', 'status' => 'live'],
            )
            ->create();

        app(SeedClientDefaultTemplate::class)->handle($client, $user);
    }
}
