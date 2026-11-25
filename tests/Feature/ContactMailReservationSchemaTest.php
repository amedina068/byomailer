<?php

namespace Tests\Feature;

use App\Models\ContactMail;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContactMailReservationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_mails_have_a_nullable_reserved_at_cast_to_a_date(): void
    {
        $this->assertTrue(Schema::hasColumn('contact_mails', 'reserved_at'));

        $contactMail = ContactMail::factory()->create(['reserved_at' => '2026-09-22 10:00:00']);

        $this->assertInstanceOf(CarbonInterface::class, $contactMail->fresh()->reserved_at);
        $this->assertNull(ContactMail::factory()->create()->reserved_at);
    }

    public function test_sequence_due_index_exists(): void
    {
        $index = collect(Schema::getIndexes('contact_mails'))
            ->firstWhere('name', 'contact_mails_sequence_due_index');

        $this->assertNotNull($index);
        $this->assertSame(['mailable_type', 'status', 'scheduled_at'], $index['columns']);
    }
}
