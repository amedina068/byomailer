<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use App\Scopes\ClientScope;
use App\Support\DefaultTemplateContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RefreshDatabase loads database/schema/<driver>-schema.sql, then runs the
 * migrations that the dump does not list. A data migration must still write
 * its rows, as on an install that runs each migration.
 */
class SchemaDumpTest extends TestCase
{
    use RefreshDatabase;

    /** The migrations that write rows on a new database. */
    private const DATA_MIGRATIONS = [
        '2026_04_23_100002_seed_default_email_templates',
    ];

    public function test_the_default_email_template_exists_after_the_dump_loads(): void
    {
        $template = EmailTemplate::query()
            ->withoutGlobalScope(ClientScope::class)
            ->whereNull('client_id')
            ->whereNull('user_id')
            ->where('name', DefaultTemplateContent::NAME)
            ->first();

        $this->assertNotNull($template);
        $this->assertSame(DefaultTemplateContent::html(), $template->html_content);
    }

    public function test_every_migration_is_recorded(): void
    {
        $files = collect(glob(database_path('migrations/*.php')) ?: [])->map(fn (string $path): string => basename($path, '.php'));

        $this->assertEqualsCanonicalizing($files->all(), DB::table('migrations')->pluck('migration')->all());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function schemaDumps(): array
    {
        return ['mysql' => ['mysql-schema.sql'], 'sqlite' => ['sqlite-schema.sql']];
    }

    /**
     * A dump holds no rows except the migrations table. Laravel does not run
     * a migration that the dump lists, so a data migration stays out of it.
     */
    #[DataProvider('schemaDumps')]
    public function test_a_schema_dump_does_not_list_the_data_migrations(string $file): void
    {
        $dump = (string) file_get_contents(database_path("schema/{$file}"));

        $this->assertStringContainsString("'2026_11_17_000001_add_is_owner_to_users_table'", $dump);

        foreach (self::DATA_MIGRATIONS as $migration) {
            $this->assertStringNotContainsString("'{$migration}'", $dump);
        }
    }
}
