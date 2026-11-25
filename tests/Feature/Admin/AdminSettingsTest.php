<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create();
    }

    public function test_the_page_shows_the_current_mode_and_the_default(): void
    {
        config(['app.registration_enabled' => false]);

        $this->actingAs($this->owner)
            ->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Settings')
                ->where('registrationMode', Registration::INVITE_ONLY)
                ->where('defaultMode', Registration::INVITE_ONLY)
                ->where('modeIsStored', false));
    }

    public function test_the_owner_can_open_registration(): void
    {
        config(['app.registration_enabled' => false]);

        $this->actingAs($this->owner)
            ->put('/admin/settings', ['registration_mode' => Registration::OPEN])
            ->assertRedirect('/admin/settings')
            ->assertSessionHas('success', 'The settings are saved.');

        $this->assertSame(Registration::OPEN, Registration::mode());
        $this->assertDatabaseHas('instance_settings', ['key' => Registration::SETTING, 'value' => Registration::OPEN]);

        $this->actingAs($this->owner)
            ->get('/admin/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('registrationMode', Registration::OPEN)
                ->where('modeIsStored', true));
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->put('/admin/settings', ['registration_mode' => 'everyone'])
            ->assertSessionHasErrors('registration_mode');

        $this->assertDatabaseCount('instance_settings', 0);
    }
}
