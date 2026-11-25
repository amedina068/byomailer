<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactAttribute;
use App\Models\ContactMail;
use App\Models\MailList;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_requires_authentication(): void
    {
        $this->get(route('contacts.export'))->assertRedirect(route('login'));
    }

    public function test_export_returns_csv_download(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['name' => 'Newsletter']);
        $contact = Contact::factory()->for($user)->create([
            'email' => 'alice@example.com',
            'name' => 'Alice',
        ]);
        $contact->lists()->attach($list);

        $response = $this->actingAs($user)->get(route('contacts.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('email,name,lists,tags,subscribed_at,unsubscribed_at,unsubscribed_by_contact,complained_at,bounced_at', $csv);
        $this->assertStringContainsString('alice@example.com', $csv);
        $this->assertStringContainsString('Newsletter', $csv);
    }

    public function test_export_combines_multiple_lists_in_single_row(): void
    {
        $user = User::factory()->create();
        $listA = MailList::factory()->for($user)->create(['name' => 'List A']);
        $listB = MailList::factory()->for($user)->create(['name' => 'List B']);
        $contact = Contact::factory()->for($user)->create([
            'email' => 'bob@example.com',
            'name' => 'Bob',
        ]);
        $contact->lists()->attach([$listA->id, $listB->id]);

        $response = $this->actingAs($user)->get(route('contacts.export'));

        $csv = $response->streamedContent();
        $rows = array_filter(explode("\n", trim($csv)));
        // header + 1 row (lists combined)
        $this->assertCount(2, array_values($rows));
        $this->assertStringContainsString('List A', $csv);
        $this->assertStringContainsString('List B', $csv);
    }

    public function test_export_includes_unsubscribed_at(): void
    {
        $user = User::factory()->create();
        $unsubscribedAt = now()->startOfSecond();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'unsub@example.com',
            'name' => 'Unsub User',
            'unsubscribed_at' => $unsubscribedAt,
        ]);

        $response = $this->actingAs($user)->get(route('contacts.export', [
            'include_unsubscribed' => '1',
        ]));

        $csv = $response->streamedContent();
        $this->assertStringContainsString($unsubscribedAt->toDateTimeString(), $csv);
    }

    public function test_export_includes_unsubscribed_by_contact(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create([
            'email' => 'self-unsub@example.com',
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ]);
        Contact::factory()->for($user)->create([
            'email' => 'admin-unsub@example.com',
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => false,
        ]);

        $response = $this->actingAs($user)->get(route('contacts.export', [
            'include_unsubscribed' => '1',
        ]));

        $csv = $response->streamedContent();
        $rows = array_filter(explode("\n", trim($csv)));

        $selfRow = collect($rows)->first(fn ($row) => str_contains($row, 'self-unsub@example.com'));
        $adminRow = collect($rows)->first(fn ($row) => str_contains($row, 'admin-unsub@example.com'));

        $this->assertStringContainsString('true', $selfRow);
        $this->assertStringContainsString('false', $adminRow);
    }

    public function test_export_excludes_unsubscribed_by_default(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create([
            'email' => 'unsub@example.com',
            'unsubscribed_at' => now(),
        ]);
        Contact::factory()->for($user)->create([
            'email' => 'active@example.com',
        ]);

        $response = $this->actingAs($user)->get(route('contacts.export'));

        $csv = $response->streamedContent();
        $this->assertStringNotContainsString('unsub@example.com', $csv);
        $this->assertStringContainsString('active@example.com', $csv);
    }

    public function test_export_filters_by_list(): void
    {
        $user = User::factory()->create();
        $listA = MailList::factory()->for($user)->create(['name' => 'List A']);
        $listB = MailList::factory()->for($user)->create(['name' => 'List B']);

        $contactA = Contact::factory()->for($user)->create(['email' => 'a@example.com']);
        $contactB = Contact::factory()->for($user)->create(['email' => 'b@example.com']);
        $contactA->lists()->attach($listA);
        $contactB->lists()->attach($listB);

        $response = $this->actingAs($user)->get(route('contacts.export', [
            'lists' => $listA->id,
        ]));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('a@example.com', $csv);
        $this->assertStringNotContainsString('b@example.com', $csv);
    }

    public function test_export_filters_by_tag(): void
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'VIP']);

        $vipContact = Contact::factory()->for($user)->create(['email' => 'vip@example.com']);
        $normalContact = Contact::factory()->for($user)->create(['email' => 'normal@example.com']);
        $vipContact->tags()->attach($tag);

        $response = $this->actingAs($user)->get(route('contacts.export', [
            'tags' => $tag->id,
        ]));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('vip@example.com', $csv);
        $this->assertStringNotContainsString('normal@example.com', $csv);
    }

    public function test_export_filters_by_search(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create(['email' => 'alice@example.com', 'name' => 'Alice']);
        Contact::factory()->for($user)->create(['email' => 'bob@example.com', 'name' => 'Bob']);

        $response = $this->actingAs($user)->get(route('contacts.export', [
            'search' => 'alice',
        ]));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('alice@example.com', $csv);
        $this->assertStringNotContainsString('bob@example.com', $csv);
    }

    public function test_export_only_includes_own_contacts(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Contact::factory()->for($user)->create(['email' => 'mine@example.com']);
        Contact::factory()->for($otherUser)->create(['email' => 'theirs@example.com']);

        $response = $this->actingAs($user)->get(route('contacts.export'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('mine@example.com', $csv);
        $this->assertStringNotContainsString('theirs@example.com', $csv);
    }

    public function test_export_includes_contact_with_no_list(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create([
            'email' => 'nolist@example.com',
            'name' => 'No List',
        ]);

        $response = $this->actingAs($user)->get(route('contacts.export'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('nolist@example.com', $csv);
    }

    public function test_export_includes_tags_column(): void
    {
        $user = User::factory()->create();
        $tagA = Tag::factory()->for($user)->create(['name' => 'VIP']);
        $tagB = Tag::factory()->for($user)->create(['name' => 'Early Adopter']);

        $contact = Contact::factory()->for($user)->create([
            'email' => 'tagged@example.com',
            'name' => 'Tagged User',
        ]);
        $contact->tags()->attach([$tagA->id, $tagB->id]);

        $response = $this->actingAs($user)->get(route('contacts.export'));

        $csv = $response->streamedContent();
        $rows = array_filter(explode("\n", trim($csv)));
        $dataRow = $rows[1];

        $this->assertStringContainsString('VIP', $dataRow);
        $this->assertStringContainsString('Early Adopter', $dataRow);
    }

    public function test_export_includes_extra_attribute_columns(): void
    {
        $user = User::factory()->create();

        ContactAttribute::create([
            'name' => 'company',
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);
        ContactAttribute::create([
            'name' => 'city',
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);

        Contact::factory()->for($user)->create([
            'email' => 'attrs@example.com',
            'name' => 'Attr User',
            'extra_attributes' => ['company' => 'Acme', 'city' => 'Portland'],
        ]);

        $response = $this->actingAs($user)->get(route('contacts.export'));

        $csv = $response->streamedContent();
        $rows = array_filter(explode("\n", trim($csv)));

        $header = $rows[0];
        $this->assertStringContainsString('city', $header);
        $this->assertStringContainsString('company', $header);

        $dataRow = $rows[1];
        $this->assertStringContainsString('Acme', $dataRow);
        $this->assertStringContainsString('Portland', $dataRow);
    }

    public function test_export_handles_missing_extra_attributes_gracefully(): void
    {
        $user = User::factory()->create();

        ContactAttribute::create([
            'name' => 'company',
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);

        Contact::factory()->for($user)->create([
            'email' => 'noattr@example.com',
            'name' => 'No Attr',
            'extra_attributes' => [],
        ]);

        $response = $this->actingAs($user)->get(route('contacts.export'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('noattr@example.com', $csv);
        $this->assertStringContainsString('company', $csv);
    }

    public function test_export_includes_complained_at_from_contact_mails(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'complainer@example.com',
        ]);

        $complainedAt = now()->subDay()->startOfSecond();
        ContactMail::factory()->for($contact)->create([
            'complained_at' => $complainedAt,
            'status' => 'complained',
            'client_id' => $user->client_id,
        ]);

        $response = $this->actingAs($user)->get(route('contacts.export'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString($complainedAt->toDateTimeString(), $csv);
    }

    public function test_export_includes_bounced_at_from_contact_mails(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'bouncer@example.com',
        ]);

        $bouncedAt = now()->subDays(2)->startOfSecond();
        ContactMail::factory()->for($contact)->create([
            'bounced_at' => $bouncedAt,
            'status' => 'bounced',
            'client_id' => $user->client_id,
        ]);

        $response = $this->actingAs($user)->get(route('contacts.export'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString($bouncedAt->toDateTimeString(), $csv);
    }

    public function test_export_includes_subscribed_at(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create([
            'email' => 'subscriber@example.com',
        ]);

        $response = $this->actingAs($user)->get(route('contacts.export'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString($contact->created_at->toDateTimeString(), $csv);
    }

    public function test_export_escapes_values_that_a_spreadsheet_reads_as_formulas(): void
    {
        $user = User::factory()->create();
        ContactAttribute::create(['name' => 'company', 'user_id' => $user->id, 'client_id' => $user->client_id]);
        $list = MailList::factory()->for($user)->create(['name' => '@list']);
        $tag = Tag::factory()->create(['user_id' => $user->id, 'client_id' => $user->client_id, 'name' => '+tag']);

        $contact = Contact::factory()->for($user)->create([
            'email' => 'formula@example.com',
            'name' => '=1+1',
            'extra_attributes' => ['company' => '-2+3'],
        ]);
        $contact->lists()->attach($list);
        $contact->tags()->attach($tag);

        $rows = $this->csvRows($this->actingAs($user)->get(route('contacts.export'))->streamedContent());
        $row = array_combine($rows[0], $rows[1]);

        $this->assertSame("'=1+1", $row['name']);
        $this->assertSame("'@list", $row['lists']);
        $this->assertSame("'+tag", $row['tags']);
        $this->assertSame("'-2+3", $row['company']);
        $this->assertSame('formula@example.com', $row['email']);
    }

    public function test_export_writes_a_structured_attribute_value_as_json(): void
    {
        $user = User::factory()->create();
        ContactAttribute::create(['name' => 'plans', 'user_id' => $user->id, 'client_id' => $user->client_id]);
        Contact::factory()->for($user)->create([
            'email' => 'json@example.com',
            'extra_attributes' => ['plans' => ['=pro', 'team']],
        ]);

        $rows = $this->csvRows($this->actingAs($user)->get(route('contacts.export'))->streamedContent());
        $row = array_combine($rows[0], $rows[1]);

        $this->assertSame('["=pro","team"]', $row['plans']);
    }

    public function test_export_escapes_an_attribute_name_in_the_header(): void
    {
        $user = User::factory()->create();
        ContactAttribute::create(['name' => '=total', 'user_id' => $user->id, 'client_id' => $user->client_id]);

        $rows = $this->csvRows($this->actingAs($user)->get(route('contacts.export'))->streamedContent());

        $this->assertContains("'=total", $rows[0]);
        $this->assertNotContains('=total', $rows[0]);
    }

    /**
     * @return list<list<string>>
     */
    private function csvRows(string $csv): array
    {
        return array_map(
            fn (string $line): array => str_getcsv($line, escape: ''),
            array_values(array_filter(explode("\n", trim($csv)))),
        );
    }
}
