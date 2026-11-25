<?php

namespace Tests\Feature;

use App\Enums\NewsletterStatus;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LiquidCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_the_unsent_messages_whose_content_is_not_plain(): void
    {
        $user = User::factory()->create();

        $plain = $this->newsletter($user, '<p>Hi {{ name }}</p>');
        $liquid = $this->newsletter($user, '<p>Hi {{ name | upcase }}</p>');
        $broken = $this->newsletter($user, '<p>{{ first name }}</p>');
        $sent = $this->newsletter($user, '<p>{{ name | upcase }}</p>', NewsletterStatus::Sent);

        $step = SequenceStep::factory()->for(Sequence::factory()->for($user))->create();
        $step->message()->withoutGlobalScopes()->update(['html_content' => '<p>{% if vip %}VIP{% endif %}</p>']);

        $this->assertSame(0, Artisan::call('messages:liquid-check'));
        $output = Artisan::output();

        $this->assertMatchesRegularExpression("/Newsletter {$liquid->id} .*Renders with Liquid/", $output);
        $this->assertMatchesRegularExpression("/Newsletter {$broken->id} .*Sends as before\\. The Liquid code has an error/", $output);
        $this->assertMatchesRegularExpression("/SequenceStep {$step->id} .*Renders with Liquid/", $output);
        $this->assertStringNotContainsString("Newsletter {$plain->id} ", $output);
        $this->assertStringNotContainsString("Newsletter {$sent->id} ", $output);
        $this->assertStringContainsString('2 message(s) render with Liquid. 1 message(s) are not valid Liquid', $output);
    }

    public function test_it_reports_when_all_content_is_plain(): void
    {
        $this->newsletter(User::factory()->create(), '<p>Hi {{ name }}</p>');

        $this->artisan('messages:liquid-check')
            ->expectsOutputToContain('Each unsent message uses plain {{ variable }} placeholders only.')
            ->assertSuccessful();
    }

    private function newsletter(User $user, string $html, NewsletterStatus $status = NewsletterStatus::Draft): Newsletter
    {
        $newsletter = Newsletter::factory()->for($user)->create(['status' => $status]);
        $newsletter->message()->withoutGlobalScopes()->update(['subject' => 'Hi', 'html_content' => $html, 'text_content' => null]);

        return $newsletter;
    }
}
