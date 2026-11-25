<?php

namespace Tests\Unit;

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prefers_extra_attributes_for_template_values(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'Taylor Otwell',
            'extra_attributes' => [
                'first_name' => 'Taylor',
                'address' => [
                    'city' => 'Vancouver',
                ],
            ],
        ]);

        $this->assertSame('Taylor', $contact->getTemplateAttribute('first_name'));
        $this->assertSame('Vancouver', $contact->getTemplateAttribute('address.city'));
    }

    public function test_it_falls_back_to_regular_attributes_for_template_values(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'Jordan',
            'email' => 'jordan@example.com',
            'extra_attributes' => [],
        ]);

        $this->assertSame('Jordan', $contact->getTemplateAttribute('name'));
        $this->assertSame('jordan@example.com', $contact->getTemplateAttribute('email'));
    }

    public function test_it_returns_empty_string_for_unknown_template_attributes(): void
    {
        $contact = Contact::factory()->create([
            'extra_attributes' => [],
        ]);

        $this->assertSame('', $contact->getTemplateAttribute('unknown_attribute'));
    }
}
