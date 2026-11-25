<?php

namespace Tests\Feature;

use App\Exceptions\MissingTenantException;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use App\Scopes\ClientScope;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_job_dispatched_in_a_web_request_runs_as_the_client_of_the_user(): void
    {
        $user = User::factory()->create();

        Route::middleware(['web', 'auth'])->post('/_tenant-probe', function () {
            dispatch(fn () => Cache::put('tenant-of-job', Tenant::id()))->onConnection('database');
        });

        $this->actingAs($user)->post('/_tenant-probe')->assertOk();

        $this->workTheDatabaseQueueAsANewProcess();

        $this->assertSame($user->client_id, Cache::get('tenant-of-job'));
    }

    public function test_a_job_dispatched_in_an_api_token_request_runs_as_the_client_of_the_token_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        Route::middleware(['api', 'auth:sanctum'])->post('/_tenant-probe', function () {
            dispatch(fn () => Cache::put('tenant-of-job', Tenant::id()))->onConnection('database');
        });

        $this->withToken($token)->postJson('/_tenant-probe')->assertOk();

        $this->workTheDatabaseQueueAsANewProcess();

        $this->assertSame($user->client_id, Cache::get('tenant-of-job'));
    }

    public function test_a_job_dispatched_inside_tenant_run_carries_the_client(): void
    {
        $user = User::factory()->create();

        Tenant::run($user->client_id, function (): void {
            dispatch(fn () => Cache::put('tenant-of-job', Tenant::id()))->onConnection('database');
        });

        $this->workTheDatabaseQueueAsANewProcess();

        $this->assertSame($user->client_id, Cache::get('tenant-of-job'));
    }

    public function test_a_job_with_no_tenant_does_not_keep_the_tenant_of_the_previous_job(): void
    {
        $user = User::factory()->create();

        Tenant::run($user->client_id, function (): void {
            dispatch(fn () => Cache::put('tenant-of-first-job', Tenant::id()))->onConnection('database');
        });
        dispatch(fn () => Cache::put('tenant-of-second-job', Tenant::id() ?? 'none'))->onConnection('database');

        $this->workTheDatabaseQueueAsANewProcess();

        $this->assertSame($user->client_id, Cache::get('tenant-of-first-job'));
        $this->assertSame('none', Cache::get('tenant-of-second-job'));
    }

    public function test_tenant_run_scopes_queries_and_restores_the_previous_tenant(): void
    {
        $first = Contact::factory()->create();
        $second = Contact::factory()->create();

        $this->assertNull(Tenant::id());

        Tenant::run($first->client_id, function () use ($first, $second): void {
            $this->assertSame([$first->id], Contact::query()->pluck('id')->all());

            Tenant::run($second->client_id, function () use ($second): void {
                $this->assertSame([$second->id], Contact::query()->pluck('id')->all());
            });

            $this->assertSame($first->client_id, Tenant::id());
        });

        $this->assertNull(Tenant::id());
    }

    public function test_tenant_run_restores_the_previous_tenant_when_the_callback_throws(): void
    {
        $contact = Contact::factory()->create();

        try {
            Tenant::run($contact->client_id, fn () => throw new RuntimeException('Failed'));
        } catch (RuntimeException) {
        }

        $this->assertNull(Tenant::id());
    }

    public function test_tenant_run_returns_the_value_of_the_callback(): void
    {
        $contact = Contact::factory()->create();

        $this->assertSame(1, Tenant::run($contact->client_id, fn (): int => Contact::query()->count()));
    }

    public function test_a_record_created_inside_tenant_run_belongs_to_that_client(): void
    {
        $user = User::factory()->create();

        $tag = Tenant::run($user->client_id, fn (): Tag => Tag::query()->create([
            'user_id' => $user->id,
            'name' => 'VIP',
        ]));

        $this->assertSame($user->client_id, $tag->client_id);
    }

    public function test_a_query_with_no_tenant_throws_in_strict_mode(): void
    {
        Contact::factory()->create();

        $this->expectException(MissingTenantException::class);
        $this->expectExceptionMessage('Run the code inside Tenant::run() or use withoutGlobalScopes() with an explicit client_id.');

        Contact::query()->count();
    }

    public function test_a_query_with_no_tenant_matches_no_rows_and_is_reported_in_production(): void
    {
        Exceptions::fake();
        ClientScope::preventQueriesWithoutTenant(false);

        try {
            Contact::factory()->count(2)->create();

            $this->assertSame(0, Contact::query()->count());
            Exceptions::assertReported(fn (MissingTenantException $exception): bool => $exception->model === Contact::class);
        } finally {
            ClientScope::preventQueriesWithoutTenant();
        }
    }

    public function test_a_query_without_global_scopes_still_reads_every_client(): void
    {
        Contact::factory()->count(2)->create();

        $this->assertSame(2, Contact::withoutGlobalScopes()->count());
    }

    public function test_a_teammate_sees_the_rows_of_the_client(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->teammateOf($owner)->create();
        $contact = Contact::factory()->for($owner)->create();
        Contact::factory()->create();

        $this->actingAs($teammate);

        $this->assertSame($owner->client_id, Tenant::id());
        $this->assertSame([$contact->id], Contact::query()->pluck('id')->all());
    }

    public function test_the_tenant_is_a_hidden_context_value(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->assertSame($user->client_id, Context::getHidden(Tenant::CONTEXT_KEY));
        $this->assertArrayNotHasKey(Tenant::CONTEXT_KEY, Context::all());
    }

    public function test_a_second_login_replaces_the_tenant(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->actingAs($first);
        $this->actingAs($second);

        $this->assertSame($second->client_id, Tenant::id());
    }

    public function test_a_logout_removes_the_tenant(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $this->assertSame($user->client_id, Tenant::id());

        Auth::logout();

        $this->assertNull(Tenant::id());
    }

    /**
     * A queue worker is a new process: it has no authenticated user and an
     * empty Context. Only the job payload can give the job its tenant.
     */
    private function workTheDatabaseQueueAsANewProcess(): void
    {
        $this->app->instance('request', Request::create('/'));
        Auth::forgetGuards();
        Auth::shouldUse('web');
        Context::flush();

        // The worker stops when the process passes --memory. This process holds the whole test suite.
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--stop-when-empty' => true,
            '--sleep' => 0,
            '--memory' => 4096,
        ])->assertSuccessful();
    }
}
