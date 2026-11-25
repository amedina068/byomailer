<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContactShowDeferTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_show_defers_the_mail_history(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('contacts.show', $contact))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('contacts/Show')
                ->has('contact')
                ->missing('mails')
                ->loadDeferredProps(fn (Assert $reload) => $reload->has('mails'))
            );
    }
}
