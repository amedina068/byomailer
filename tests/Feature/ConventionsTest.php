<?php

namespace Tests\Feature;

use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Checks the project conventions on the source files, so that a tool, not a
 * reviewer, finds a broken rule.
 *
 * Some checks have an allowlist of the code that broke the rule before the check
 * existed. An allowlist can only shrink. The test fails on a new violation, and it
 * fails on an entry that no longer breaks the rule. When you fix an old violation,
 * remove its entry. Do not add an entry: change the code.
 */
class ConventionsTest extends TestCase
{
    private const MAX_VUE_FILE_LINES = 500;

    private const MAX_METHOD_LINES = 80;

    /**
     * Controller actions with an untyped `$...Id` route parameter.
     *
     * @var list<string>
     */
    private const UNTYPED_ID_PARAMETERS = [
        'App\Http\Controllers\Api\ContactController::removeFromList',
        'App\Http\Controllers\AutomationController::destroy',
        'App\Http\Controllers\AutomationController::edit',
        'App\Http\Controllers\AutomationStatusController::__invoke',
        'App\Http\Controllers\ContactAttributeController::destroy',
        'App\Http\Controllers\ContactAttributeController::store',
        'App\Http\Controllers\ContactAttributeController::update',
        'App\Http\Controllers\ContactController::show',
        'App\Http\Controllers\ContactController::subscribe',
        'App\Http\Controllers\ContactController::unsubscribe',
        'App\Http\Controllers\ContactController::update',
        'App\Http\Controllers\ContactImportStatusController::__invoke',
        'App\Http\Controllers\ContactListController::sync',
        'App\Http\Controllers\ContactMailController::show',
        'App\Http\Controllers\ContactTagController::sync',
        'App\Http\Controllers\NewsletterController::destroy',
        'App\Http\Controllers\NewsletterController::schedule',
        'App\Http\Controllers\NewsletterController::show',
        'App\Http\Controllers\NewsletterController::showContent',
        'App\Http\Controllers\NewsletterController::showFilters',
        'App\Http\Controllers\NewsletterController::showPreview',
        'App\Http\Controllers\NewsletterController::showSchedule',
        'App\Http\Controllers\NewsletterController::updateContent',
        'App\Http\Controllers\NewsletterController::updateFilters',
        'App\Http\Controllers\NewsletterController::updateTitle',
        'App\Http\Controllers\NewsletterVariablesController::show',
        'App\Http\Controllers\NewsletterVariablesController::update',
        'App\Http\Controllers\SequenceContactController::index',
        'App\Http\Controllers\SequenceController::show',
        'App\Http\Controllers\SequenceStatusController::__invoke',
        'App\Http\Controllers\SequenceStepContactController::index',
        'App\Http\Controllers\SequenceStepFiltersController::__invoke',
        'App\Http\Controllers\SequenceStepStatusController::__invoke',
        'App\Http\Controllers\VisualAutomationController::destroy',
        'App\Http\Controllers\VisualAutomationController::show',
        'App\Http\Controllers\VisualAutomationController::update',
        'App\Http\Controllers\VisualAutomationStatusController::__invoke',
    ];

    /**
     * Files that resolve a service from the container, with the number of calls.
     *
     * @var array<string, int>
     */
    private const SERVICE_LOCATION_CALLS = [
        'app/Actions/Fortify/CreateNewUser.php' => 1,
        'app/Http/Controllers/DashboardController.php' => 1,
        'app/Http/Controllers/NewsletterController.php' => 1,
        'app/Http/Controllers/SequenceController.php' => 1,
        'app/Http/Controllers/SequenceStepContactMailExportController.php' => 1,
        'app/Http/Requests/Api/StoreWebhookEndpointRequest.php' => 1,
        'app/Http/Requests/StoreUserStorageSettingRequest.php' => 1,
        'app/Http/Resources/SequenceStatsResource.php' => 1,
        'app/Http/Resources/SequenceStepStatsResource.php' => 1,
        'app/Jobs/DispatchNewsletterBatchJob.php' => 1,
        'app/Jobs/ProcessSesEventJob.php' => 1,
        'app/Mcp/Concerns/ListsContactMails.php' => 1,
        'app/Mcp/Tools/ContactMailDetailsTool.php' => 1,
        'app/Models/Contact.php' => 1,
        'app/Providers/AppServiceProvider.php' => 1,
        'app/Services/SequenceStatusService.php' => 1,
        'app/Services/UserFilesystemManager.php' => 1,
        'app/Support/Registration.php' => 3,
    ];

    /**
     * Vue files over the line limit, with their line count. A file must not grow.
     *
     * @var array<string, int>
     */
    private const LONG_VUE_FILES = [
        'resources/js/components/newsletters/FiltersForm.vue' => 673,
        'resources/js/components/TiptapEditor.vue' => 783,
        'resources/js/pages/contacts/Show.vue' => 1453,
        'resources/js/pages/Dashboard.vue' => 1035,
        'resources/js/pages/documentation/api/Newsletters.vue' => 1129,
        'resources/js/pages/documentation/Api.vue' => 737,
        'resources/js/pages/documentation/guides/McpServer.vue' => 1266,
        'resources/js/pages/newsletters/Create.vue' => 1526,
        'resources/js/pages/newsletters/Show.vue' => 603,
        'resources/js/pages/sequences/Show.vue' => 886,
        'resources/js/pages/sequences/steps/Show.vue' => 785,
        'resources/js/pages/visual-automations/Show.vue' => 871,
    ];

    /**
     * Methods over the line limit, with their line count. A method must not grow.
     *
     * @var array<string, int>
     */
    private const LONG_METHODS = [
        'App\Console\Commands\BackfillSesTenantsCommand::handle' => 125,
        'App\Console\Commands\MigrateSnsToSqsCommand::handle' => 97,
        'App\Console\Commands\PollSesEventsCommand::handle' => 90,
        'App\Http\Controllers\DocumentationPostmanController::newslettersFolder' => 207,
        'App\Http\Controllers\Onboarding\AwsOnboardingController::storeCredentials' => 89,
        'App\Http\Middleware\HandleInertiaRequests::mainNavigationLinks' => 107,
        'App\Jobs\SendSequenceContactMailJob::sendMail' => 91,
        'App\Mcp\Tools\BulkUpdateContactsTool::handle' => 83,
        'App\Services\ContactMailQuery::forUser' => 83,
        'App\Services\EmailIdentityProvisioningService::provision' => 93,
        'App\Services\SendNewsletterService::send' => 138,
        'App\Services\VisualAutomationEngine::processEnrollment' => 82,
    ];

    /**
     * Models that use `$fillable`.
     *
     * @var list<string>
     */
    private const MODELS_WITH_FILLABLE = [];

    /**
     * Actions outside `app/Actions/Fortify` that do not expose `handle()`.
     *
     * @var list<string>
     */
    private const ACTIONS_WITHOUT_HANDLE = [
        'App\Actions\Instance\CreateInstanceOwner',
    ];

    public function test_controller_actions_bind_models_instead_of_taking_untyped_ids(): void
    {
        $offenders = [];

        foreach ($this->classesIn('app/Http/Controllers') as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->class !== $class || $method->isConstructor()) {
                    continue;
                }

                foreach ($method->getParameters() as $parameter) {
                    if (! $parameter->hasType() && str_ends_with($parameter->getName(), 'Id')) {
                        $offenders[] = $class.'::'.$method->getName();

                        break;
                    }
                }
            }
        }

        $this->assertOnlyAllowlisted(
            'UNTYPED_ID_PARAMETERS',
            self::UNTYPED_ID_PARAMETERS,
            $offenders,
            'takes an untyped `$...Id` parameter. Type-hint the model and use route model binding, '
                .'for example `show(Newsletter $newsletter)`. An API route that must keep the id takes `int $newsletterId`.',
        );
    }

    public function test_app_code_injects_services_instead_of_resolving_them(): void
    {
        $calls = [];

        foreach ($this->phpFilesIn('app') as $path) {
            $count = $this->serviceLocationCalls($this->tokensOf($path));

            if ($count > 0) {
                $calls[$path] = $count;
            }
        }

        $this->assertCountsMatchAllowlist(
            'SERVICE_LOCATION_CALLS',
            self::SERVICE_LOCATION_CALLS,
            $calls,
            'calls `app(...)` or `resolve(...)`. Inject the service in the constructor or in the method instead.',
        );
    }

    public function test_vue_files_stay_small(): void
    {
        $sizes = [];

        foreach ($this->filesIn('resources/js', '.vue') as $path) {
            $lines = count(file($this->app->basePath($path)));

            if ($lines > self::MAX_VUE_FILE_LINES) {
                $sizes[$path] = $lines;
            }
        }

        $this->assertSizesMatchAllowlist(
            'LONG_VUE_FILES',
            self::LONG_VUE_FILES,
            $sizes,
            'has more than '.self::MAX_VUE_FILE_LINES.' lines. Move parts into child components or composables.',
        );
    }

    public function test_php_methods_stay_short(): void
    {
        $sizes = [];

        foreach ($this->classesIn('app') as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                $lines = $method->getEndLine() - $method->getStartLine() + 1;

                if ($method->class === $class && $lines > self::MAX_METHOD_LINES) {
                    $sizes[$class.'::'.$method->getName()] = $lines;
                }
            }
        }

        $this->assertSizesMatchAllowlist(
            'LONG_METHODS',
            self::LONG_METHODS,
            $sizes,
            'has more than '.self::MAX_METHOD_LINES.' lines. Extract private methods, an Action, or a service.',
        );
    }

    public function test_no_file_declares_strict_types(): void
    {
        $offenders = [];

        foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'tests'] as $directory) {
            foreach ($this->phpFilesIn($directory) as $path) {
                $tokens = $this->tokensOf($path);

                foreach ($tokens as $index => $token) {
                    if ($token->is(T_DECLARE) && ($tokens[$index + 2] ?? null)?->text === 'strict_types') {
                        $offenders[] = $path;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'Remove `declare(strict_types=1);` from these files. The project does not use it: '.implode(', ', $offenders));
    }

    public function test_models_use_guarded_instead_of_fillable(): void
    {
        $offenders = [];

        foreach ($this->classesIn('app/Models') as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->hasProperty('fillable') && $reflection->getProperty('fillable')->class === $class) {
                $offenders[] = $class;
            }
        }

        $this->assertOnlyAllowlisted(
            'MODELS_WITH_FILLABLE',
            self::MODELS_WITH_FILLABLE,
            $offenders,
            'declares `$fillable`. Use `protected $guarded = [];` and validate the input in a FormRequest.',
        );
    }

    public function test_actions_expose_handle_instead_of_execute(): void
    {
        $offenders = [];

        foreach ($this->classesIn('app/Actions') as $class) {
            $reflection = new ReflectionClass($class);

            if (str_starts_with($class, 'App\\Actions\\Fortify\\') || ! $reflection->isInstantiable()) {
                continue;
            }

            $hasPublicHandle = $reflection->hasMethod('handle') && $reflection->getMethod('handle')->isPublic();
            $hasPublicExecute = $reflection->hasMethod('execute') && $reflection->getMethod('execute')->isPublic();

            if (! $hasPublicHandle || $hasPublicExecute) {
                $offenders[] = $class;
            }
        }

        $this->assertOnlyAllowlisted(
            'ACTIONS_WITHOUT_HANDLE',
            self::ACTIONS_WITHOUT_HANDLE,
            $offenders,
            'must have a public `handle()` method and no public `execute()` method. Every Action uses the same method name, '
                .'so a caller can run it without reading it first. The Fortify actions keep the method names of their Fortify contracts.',
        );
    }

    public function test_no_code_uses_the_word_operators_and_or_xor(): void
    {
        $offenders = [];

        foreach (['app', 'config', 'database', 'routes'] as $directory) {
            foreach ($this->phpFilesIn($directory) as $path) {
                foreach ($this->tokensOf($path) as $token) {
                    if ($token->is([T_LOGICAL_AND, T_LOGICAL_OR, T_LOGICAL_XOR])) {
                        $offenders[] = $path.':'.$token->line;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'Use `&&`, `||` or `!==` instead of `and`, `or` or `xor`. '
            .'The word operators bind more loosely than `=`, so `$x = $a xor $b` drops the result: '.implode(', ', $offenders));
    }

    public function test_model_hooks_do_not_read_was_recently_created(): void
    {
        $offenders = $this->filesThatCall('app/Models', ['wasRecentlyCreated']);

        $this->assertSame([], $offenders, 'A model hook must read `isDirty()` and `getOriginal()`. Eloquent never clears '
            .'`wasRecentlyCreated`, so a later save looks like a create: '.implode(', ', $offenders));
    }

    public function test_controllers_do_not_branch_on_the_requested_format(): void
    {
        $offenders = $this->filesThatCall('app/Http/Controllers', ['expectsJson', 'wantsJson']);

        $this->assertSame([], $offenders, 'Return one response type from a controller method. Give the other client '
            .'its own controller method or invokable controller: '.implode(', ', $offenders));
    }

    /**
     * Fail on each offender that is not in the allowlist, and on each allowlist entry that no longer offends.
     *
     * @param  list<string>  $allowlist
     * @param  list<string>  $offenders
     */
    private function assertOnlyAllowlisted(string $name, array $allowlist, array $offenders, string $problem): void
    {
        $failures = [];

        foreach (array_diff($offenders, $allowlist) as $offender) {
            $failures[] = "{$offender} {$problem}";
        }

        foreach (array_diff($allowlist, $offenders) as $fixed) {
            $failures[] = "{$fixed} is in self::{$name}, but it follows the rule now. Remove it from the list.";
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    /**
     * Fail when a count is new or different from the allowlist. A lower count must be written down.
     *
     * @param  array<string, int>  $allowlist
     * @param  array<string, int>  $counts
     */
    private function assertCountsMatchAllowlist(string $name, array $allowlist, array $counts, string $problem): void
    {
        $failures = [];

        foreach ($counts as $path => $count) {
            $allowed = $allowlist[$path] ?? 0;

            if ($count > $allowed) {
                $failures[] = "{$path} {$problem} (found {$count}, allowed {$allowed})";
            } elseif ($count < $allowed) {
                $failures[] = "{$path} has {$count} now. Change its number in self::{$name} to {$count}.";
            }
        }

        foreach (array_diff_key($allowlist, $counts) as $path => $allowed) {
            $failures[] = "{$path} is in self::{$name}, but it follows the rule now. Remove it from the list.";
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    /**
     * Fail when a size is new or larger than the allowlist, and on each entry that is under the limit now.
     *
     * @param  array<string, int>  $allowlist
     * @param  array<string, int>  $sizes
     */
    private function assertSizesMatchAllowlist(string $name, array $allowlist, array $sizes, string $problem): void
    {
        $failures = [];

        foreach ($sizes as $subject => $lines) {
            if (! isset($allowlist[$subject])) {
                $failures[] = "{$subject} {$problem} (found {$lines})";
            } elseif ($lines > $allowlist[$subject]) {
                $failures[] = "{$subject} grew from {$allowlist[$subject]} to {$lines} lines. Make it smaller, not larger.";
            }
        }

        foreach (array_diff_key($allowlist, $sizes) as $subject => $lines) {
            $failures[] = "{$subject} is in self::{$name}, but it is under the limit now. Remove it from the list.";
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    /**
     * Count the calls of `app(...)`, `resolve(...)` and `app()->make(...)`. A call of
     * `app()` with no argument and no `make` (for example `app()->environment()`) is not counted.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function serviceLocationCalls(array $tokens): int
    {
        $calls = 0;

        foreach ($tokens as $index => $token) {
            $name = ltrim($token->text, '\\');
            $previous = $tokens[$index - 1] ?? null;

            if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || ! in_array($name, ['app', 'resolve'], true)
                || ($tokens[$index + 1] ?? null)?->text !== '('
                || $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])) {
                continue;
            }

            $hasNoArgument = ($tokens[$index + 2] ?? null)?->text === ')';
            $chainedMethod = ($tokens[$index + 3] ?? null)?->is(T_OBJECT_OPERATOR) ? ($tokens[$index + 4] ?? null)?->text : null;

            if ($name === 'resolve' || ! $hasNoArgument || in_array($chainedMethod, ['make', 'makeWith'], true)) {
                $calls++;
            }
        }

        return $calls;
    }

    /**
     * @param  list<string>  $methods
     * @return list<string>
     */
    private function filesThatCall(string $directory, array $methods): array
    {
        $offenders = [];

        foreach ($this->phpFilesIn($directory) as $path) {
            foreach ($this->tokensOf($path) as $token) {
                if ($token->is(T_STRING) && in_array($token->text, $methods, true)) {
                    $offenders[] = $path.':'.$token->line;
                }
            }
        }

        return $offenders;
    }

    /**
     * The tokens of a PHP file, without whitespace and comments. TOKEN_PARSE
     * reads a keyword that names an identifier, for example the enum case
     * `FilterConnector::And`, as T_STRING and not as an operator.
     *
     * @return list<PhpToken>
     */
    private function tokensOf(string $path): array
    {
        $tokens = PhpToken::tokenize(file_get_contents($this->app->basePath($path)), TOKEN_PARSE);

        return array_values(array_filter($tokens, fn (PhpToken $token): bool => ! $token->isIgnorable()));
    }

    /**
     * The classes, interfaces, traits and enums of a directory under `app/`.
     *
     * @return list<class-string>
     */
    private function classesIn(string $directory): array
    {
        return array_map(
            fn (string $path): string => 'App\\'.str_replace('/', '\\', substr($path, strlen('app/'), -strlen('.php'))),
            $this->phpFilesIn($directory),
        );
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $directory): array
    {
        return $this->filesIn($directory, '.php');
    }

    /**
     * The files with the extension under a directory, as paths from the project root, in a fixed order.
     *
     * @return list<string>
     */
    private function filesIn(string $directory, string $extension): array
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->app->basePath($directory), RecursiveDirectoryIterator::SKIP_DOTS));
        $paths = [];

        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), $extension)) {
                $paths[] = substr($file->getPathname(), strlen($this->app->basePath()) + 1);
            }
        }

        sort($paths);

        return $paths;
    }
}
