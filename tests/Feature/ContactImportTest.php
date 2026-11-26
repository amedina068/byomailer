<?php

namespace Tests\Feature;

use App\Enums\ContactImportStatus;
use App\Http\Requests\ImportContactsRequest;
use App\Jobs\ProcessContactImportBatchJob;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\MailList;
use App\Models\Tag;
use App\Models\User;
use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContactImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_is_cast_to_the_contact_import_status_enum(): void
    {
        $import = ContactImport::factory()->create(['status' => ContactImportStatus::Processing]);

        $this->assertDatabaseHas('contact_imports', ['id' => $import->id, 'status' => 'processing']);

        $import->markAsFailed('Broken file.');

        $fresh = $import->fresh();
        $this->assertSame(ContactImportStatus::Failed, $fresh->status);
        $this->assertTrue($fresh->isFailed());
        $this->assertDatabaseHas('contact_imports', ['id' => $import->id, 'status' => 'failed']);
    }

    public function test_import_page_lists_available_lists(): void
    {
        $user = User::factory()->create();
        $lists = MailList::factory()->count(2)->for($user)->create();

        $response = $this->actingAs($user)->get(route('contacts.import'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('contacts/Import')
            ->where('lists', fn ($value): bool => collect($value)->pluck('id')->diff($lists->pluck('id'))->isEmpty())
        );
    }

    public function test_user_can_start_contact_import_and_dispatch_batches(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $csv = <<<'CSV'
name,email,tags,Company,Country
Alice Example,alice@example.com,VIP,Acme,USA
Bob Example,bob@example.com,"VIP,Prospect",Stark Industries,Canada
CSV;

        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $response = $this->actingAs($user)->post(route('lists.import'), [
            'list_id' => $list->id,
            'file' => $file,
        ]);

        $response->assertRedirect(route('lists.show', $list));
        $response->assertSessionHas('success', "Importing 2 contacts into {$list->name}.");

        $import = ContactImport::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($import);
        $this->assertSame($list->id, $import->list_id);
        $this->assertSame('contacts.csv', $import->original_filename);
        $this->assertSame(2, $import->total_count);
        $this->assertSame(ContactImportStatus::Processing, $import->status);
        $this->assertNotNull($import->batch_id);

        Bus::assertBatched(function (PendingBatch $batch) use ($import): bool {
            return $batch->name === 'Import contacts: contacts.csv'
                && $batch->jobs->count() === 1
                && $batch->options['contact_import_id'] === $import->id
                && $batch->jobs->first() instanceof ProcessContactImportBatchJob;
        });
    }

    public function test_process_contact_import_batch_job_creates_and_updates_contacts(): void
    {
        $user = User::factory()->create();
        $targetList = MailList::factory()->for($user)->create();
        $existingList = MailList::factory()->for($user)->create();
        $existingTag = Tag::factory()->for($user)->create(['name' => 'VIP']);

        /** @var Contact $contact */
        $contact = Contact::factory()->for($user)->create([
            'email' => 'existing@example.com',
            'name' => 'Old Name',
            'extra_attributes' => ['Industry' => 'SaaS'],
        ]);
        $contact->tags()->attach($existingTag);
        $contact->lists()->attach($existingList);

        $contactImport = ContactImport::factory()
            ->processing()
            ->for($user)
            ->for($targetList, 'mailList')
            ->create([
                'total_count' => 1,
                'processed_count' => 0,
                'skipped_count' => 0,
            ]);

        $rows = [[
            'email' => 'existing@example.com',
            'name' => 'New Name',
            'tags' => 'VIP,Prospect',
            'attributes' => [
                'Industry' => 'AI',
                'Phone' => '555-0101',
            ],
        ]];

        [$job] = (new ProcessContactImportBatchJob($user, $targetList, $contactImport, $rows))->withFakeBatch();

        app()->call([$job, 'handle']);

        $this->actingAsTenant($user->client_id);
        $contact->refresh();
        $this->assertSame('New Name', $contact->name);
        $this->assertTrue($contact->lists()->where('lists.id', $targetList->id)->exists());
        $this->assertTrue($contact->lists()->where('lists.id', $existingList->id)->exists());
        $this->assertTrue($contact->tags()->where('name', 'VIP')->exists());
        $this->assertTrue($contact->tags()->where('name', 'Prospect')->exists());
        $this->assertSameJsonData([
            'Industry' => 'AI',
            'Phone' => '555-0101',
        ], $contact->extra_attributes);

        $contactImport->refresh();
        $this->assertSame(1, $contactImport->processed_count);
        $this->assertSame(0, $contactImport->skipped_count);
    }

    public function test_user_cannot_import_contacts_into_lists_they_do_not_own(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $foreignList = MailList::factory()->create();

        $file = UploadedFile::fake()->createWithContent('contacts.csv', "name,email\nJane,jane@example.com\n");

        $response = $this->actingAs($user)
            ->from(route('lists.index'))
            ->post(route('lists.import'), [
                'list_id' => $foreignList->id,
                'file' => $file,
            ]);

        $response->assertRedirect(route('lists.index'));
        $response->assertSessionHasErrors(['list_id']);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_imports', 0);
        Bus::assertNothingBatched();
    }

    public function test_import_message_counts_the_rows_with_an_invalid_email(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['name' => 'Weekly']);

        $file = UploadedFile::fake()->createWithContent('contacts.csv', <<<'CSV'
name,email
Alice,alice@example.com
Invalid One,not-an-email
,
Invalid Two,
CSV);

        $response = $this->actingAs($user)->post(route('lists.import'), [
            'list_id' => $list->id,
            'file' => $file,
        ]);

        $response->assertRedirect(route('lists.show', $list));
        $response->assertSessionHas('success', 'Importing 1 contact into Weekly. 2 rows were skipped because the email is not valid.');

        $import = ContactImport::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(3, $import->total_count);
        $this->assertSame(2, $import->skipped_count);
        $this->assertSame(0, $import->processed_count);
    }

    public function test_import_message_uses_the_singular_for_one_skipped_row(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['name' => 'Weekly']);

        $file = UploadedFile::fake()->createWithContent('contacts.csv', "email\na@example.com\nb@example.com\nnot-an-email\n");

        $this->actingAs($user)
            ->post(route('lists.import'), ['list_id' => $list->id, 'file' => $file])
            ->assertSessionHas('success', 'Importing 2 contacts into Weekly. 1 row was skipped because the email is not valid.');
    }

    public function test_import_refuses_a_file_where_no_row_has_a_valid_email(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $file = UploadedFile::fake()->createWithContent('contacts.csv', <<<'CSV'
name,email
Invalid Contact,not-an-email
CSV);

        $response = $this->actingAs($user)
            ->from(route('contacts.import'))
            ->post(route('lists.import'), [
                'list_id' => $list->id,
                'file' => $file,
            ]);

        $response->assertRedirect(route('contacts.import'));
        $response->assertSessionHasErrors([
            'file' => 'No row in the file has a valid email address. Check the values in the email column.',
        ]);

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_imports', 0);
        Bus::assertNothingBatched();
    }

    public function test_import_refuses_a_file_without_an_email_column(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $file = UploadedFile::fake()->createWithContent('contacts.csv', "name,address\nJane,jane@example.com\n");

        $response = $this->actingAs($user)
            ->from(route('contacts.import'))
            ->post(route('lists.import'), [
                'list_id' => $list->id,
                'file' => $file,
            ]);

        $response->assertRedirect(route('contacts.import'));
        $response->assertSessionHasErrors([
            'file' => 'The file has no email column. The first row must have a column named "email".',
        ]);

        $this->assertDatabaseCount('contact_imports', 0);
        Bus::assertNothingBatched();
    }

    public function test_import_refuses_a_file_with_no_contact_rows(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $file = UploadedFile::fake()->createWithContent('contacts.csv', "name,email\n");

        $this->actingAs($user)
            ->from(route('contacts.import'))
            ->post(route('lists.import'), ['list_id' => $list->id, 'file' => $file])
            ->assertRedirect(route('contacts.import'))
            ->assertSessionHasErrors([
                'file' => 'The file has no contact rows. Add one row for each contact below the header row.',
            ]);

        $this->assertDatabaseCount('contact_imports', 0);
        Bus::assertNothingBatched();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function emailHeaderAliases(): array
    {
        return [
            'E-mail' => ['E-mail'],
            'Email Address' => ['Email Address'],
            'e_mail' => ['e_mail'],
            'E-mail address' => ['E-mail address'],
        ];
    }

    #[DataProvider('emailHeaderAliases')]
    public function test_import_accepts_common_names_for_the_email_column(string $header): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['name' => 'Weekly']);

        $file = UploadedFile::fake()->createWithContent('contacts.csv', "Name,{$header}\nJane,jane@example.com\n");

        $this->actingAs($user)
            ->post(route('lists.import'), ['list_id' => $list->id, 'file' => $file])
            ->assertRedirect(route('lists.show', $list))
            ->assertSessionHas('success', 'Importing 1 contact into Weekly.');

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->first()->rows === [[
            'email' => 'jane@example.com',
            'name' => 'Jane',
            'tags' => null,
            'attributes' => [],
        ]]);
    }

    public function test_contact_import_status_endpoint_returns_progress_for_owner(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $contactImport = ContactImport::factory()
            ->processing()
            ->for($user)
            ->for($list, 'mailList')
            ->create([
                'total_count' => 10,
                'processed_count' => 4,
                'skipped_count' => 1,
            ]);

        $response = $this->actingAs($user)->getJson(route('contact-imports.status', $contactImport));

        $response->assertOk()
            ->assertJson([
                'id' => $contactImport->id,
                'status' => ContactImportStatus::Processing->value,
                'total_count' => 10,
                'processed_count' => 4,
                'skipped_count' => 1,
                'progress' => 50,
            ])
            ->assertJsonPath('batch', null);
    }

    public function test_contact_import_status_endpoint_requires_authorization(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $contactImport = ContactImport::factory()
            ->processing()
            ->for($user)
            ->for($list, 'mailList')
            ->create();

        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->getJson(route('contact-imports.status', $contactImport))
            ->assertNotFound();
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function createExcelUpload(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows);

        $tempPath = tempnam(sys_get_temp_dir(), 'xls');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        register_shutdown_function(static function () use ($tempPath): void {
            if (is_string($tempPath) && file_exists($tempPath)) {
                unlink($tempPath);
            }
        });

        return new UploadedFile($tempPath, 'contacts.xlsx', null, null, true);
    }

    public function test_import_refuses_a_file_over_the_size_limit(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $file = UploadedFile::fake()->create('contacts.csv', ImportContactsRequest::MAX_FILE_KILOBYTES + 1, 'text/csv');

        $this->actingAs($user)
            ->from(route('contacts.import'))
            ->post(route('lists.import'), ['list_id' => $list->id, 'file' => $file])
            ->assertSessionHasErrors(['file' => 'The import file must not be larger than 20 MB.']);

        $this->assertSame(0, ContactImport::query()->count());
    }

    public function test_import_accepts_a_file_at_the_size_limit(): void
    {
        Excel::fake();

        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $file = UploadedFile::fake()->create('contacts.csv', ImportContactsRequest::MAX_FILE_KILOBYTES, 'text/csv');

        $this->actingAs($user)
            ->post(route('lists.import'), ['list_id' => $list->id, 'file' => $file])
            ->assertSessionHasNoErrors();
    }

    /**
     * PhpSpreadsheet 1.30.6 and older have known advisories. A crafted file
     * can use all the memory or the CPU of a PHP worker.
     */
    public function test_installed_spreadsheet_reader_has_the_advisory_fixes(): void
    {
        $this->assertTrue(
            InstalledVersions::satisfies(new VersionParser, 'phpoffice/phpspreadsheet', '>=1.30.8'),
            'phpoffice/phpspreadsheet '.InstalledVersions::getPrettyVersion('phpoffice/phpspreadsheet').' has known advisories.',
        );
    }
}
