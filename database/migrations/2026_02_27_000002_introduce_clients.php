<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string, int> */
    private array $userClientMap = [];

    public function up(): void
    {
        $users = DB::table('users')->get(['id', 'name', 'email', 'stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']);

        $usedSlugs = [];

        foreach ($users as $user) {
            $emailPrefix = explode('@', $user->email)[0];
            $baseSlug = Str::slug($emailPrefix ?: 'user');
            $slug = $baseSlug;
            $suffix = 1;

            while (in_array($slug, $usedSlugs, true)) {
                $slug = $baseSlug.'-'.$suffix;
                $suffix++;
            }

            $usedSlugs[] = $slug;

            $clientId = DB::table('clients')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'name' => $user->name ?: $emailPrefix,
                'slug' => $slug,
                'email' => $user->email,
                'stripe_id' => $user->stripe_id,
                'pm_type' => $user->pm_type,
                'pm_last_four' => $user->pm_last_four,
                'trial_ends_at' => $user->trial_ends_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->userClientMap[$user->id] = $clientId;
        }

        // 2. Add client_id to users and populate.
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('client_id')->nullable()->after('id');
        });

        foreach ($this->userClientMap as $userId => $clientId) {
            DB::table('users')->where('id', $userId)->update(['client_id' => $clientId]);
        }

        // 3. Add client_id (nullable) to all 15 resource tables.
        $resourceTables = [
            'contacts', 'lists', 'tags', 'newsletters', 'sequences',
            'automations', 'blacklisted_emails', 'email_credentials',
            'email_identities', 'contact_attributes', 'messages',
            'contact_mails', 'contact_imports', 'user_storage_settings',
        ];

        foreach ($resourceTables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('client_id')->nullable()->after('id');
            });
        }

        // 4. Populate client_id on resource tables that have a direct user_id.
        $tablesWithUserId = [
            'contacts', 'lists', 'tags', 'newsletters', 'sequences',
            'automations', 'blacklisted_emails', 'email_credentials',
            'email_identities', 'contact_attributes', 'messages',
            'contact_imports', 'user_storage_settings',
        ];

        foreach ($tablesWithUserId as $table) {
            DB::statement("UPDATE {$table} SET client_id = (SELECT client_id FROM users WHERE users.id = {$table}.user_id)");
        }

        // contact_mails has no direct user_id — go through contacts.
        DB::statement('UPDATE contact_mails SET client_id = (
            SELECT u.client_id FROM users u
            WHERE u.id = (SELECT c.user_id FROM contacts c WHERE c.id = contact_mails.contact_id)
        )');

        // 5. Migrate subscriptions.user_id to point to Client IDs.
        foreach ($this->userClientMap as $userId => $clientId) {
            DB::table('subscriptions')->where('user_id', $userId)->update(['user_id' => $clientId]);
        }

        // 6. Drop old (user_id, X) unique constraints.
        // On MySQL, the composite unique index may be the only index supporting the user_id FK.
        // Add a plain index on user_id before dropping the composite unique to satisfy MySQL.
        Schema::table('contacts', function (Blueprint $table): void {
            $table->index('user_id', 'contacts_user_id_index');
            $table->dropUnique('contacts_user_id_email_unique');
        });

        Schema::table('lists', function (Blueprint $table): void {
            $table->index('user_id', 'lists_user_id_index');
            $table->dropUnique('lists_user_id_name_unique');
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->index('user_id', 'tags_user_id_index');
            $table->dropUnique('tags_user_id_name_unique');
        });

        Schema::table('contact_attributes', function (Blueprint $table): void {
            $table->index('user_id', 'contact_attributes_user_id_index');
            $table->dropUnique('contact_attributes_user_id_name_unique');
        });

        Schema::table('email_identities', function (Blueprint $table): void {
            $table->index('user_id', 'email_identities_user_id_index');
            $table->dropUnique('email_identities_user_id_email_unique');
        });

        Schema::table('email_credentials', function (Blueprint $table): void {
            $table->index('user_id', 'email_credentials_user_id_index');
            $table->dropUnique('email_credentials_user_id_type_unique');
        });

        Schema::table('blacklisted_emails', function (Blueprint $table): void {
            $table->dropUnique('blacklisted_emails_user_id_email_unique');
        });

        // 7. Add new (client_id, X) unique constraints.
        Schema::table('contacts', function (Blueprint $table): void {
            $table->unique(['client_id', 'email']);
        });

        Schema::table('lists', function (Blueprint $table): void {
            $table->unique(['client_id', 'name']);
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->unique(['client_id', 'name']);
        });

        Schema::table('contact_attributes', function (Blueprint $table): void {
            $table->unique(['client_id', 'name']);
        });

        Schema::table('email_identities', function (Blueprint $table): void {
            $table->unique(['client_id', 'email']);
        });

        Schema::table('email_credentials', function (Blueprint $table): void {
            $table->unique(['client_id', 'type']);
        });

        Schema::table('blacklisted_emails', function (Blueprint $table): void {
            $table->unique(['client_id', 'email']);
        });

        // 8. Make client_id NOT NULL on users and most resource tables.
        // blacklisted_emails keeps client_id nullable because user_id is nullable there (global records).
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('client_id')->nullable(false)->change();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });

        $notNullTables = array_filter($resourceTables, fn (string $t) => $t !== 'blacklisted_emails');

        foreach ($notNullTables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('client_id')->nullable(false)->change();
            });
        }

        // 9. Remove Cashier columns from users.
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn(['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']);
        });
    }

    public function down(): void
    {
        // Restore Cashier columns to users.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });

        // Drop new (client_id, X) unique constraints.
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropUnique(['client_id', 'email']);
        });

        Schema::table('lists', function (Blueprint $table): void {
            $table->dropUnique(['client_id', 'name']);
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->dropUnique(['client_id', 'name']);
        });

        Schema::table('contact_attributes', function (Blueprint $table): void {
            $table->dropUnique(['client_id', 'name']);
        });

        Schema::table('email_identities', function (Blueprint $table): void {
            $table->dropUnique(['client_id', 'email']);
        });

        Schema::table('email_credentials', function (Blueprint $table): void {
            $table->dropUnique(['client_id', 'type']);
        });

        Schema::table('blacklisted_emails', function (Blueprint $table): void {
            $table->dropUnique(['client_id', 'email']);
        });

        // Re-add old (user_id, X) unique constraints.
        Schema::table('contacts', function (Blueprint $table): void {
            $table->unique(['user_id', 'email']);
        });

        Schema::table('lists', function (Blueprint $table): void {
            $table->unique(['user_id', 'name']);
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->unique(['user_id', 'name']);
        });

        Schema::table('contact_attributes', function (Blueprint $table): void {
            $table->unique(['user_id', 'name']);
        });

        Schema::table('email_identities', function (Blueprint $table): void {
            $table->unique(['user_id', 'email']);
        });

        Schema::table('email_credentials', function (Blueprint $table): void {
            $table->unique(['user_id', 'type']);
        });

        Schema::table('blacklisted_emails', function (Blueprint $table): void {
            $table->unique(['user_id', 'email']);
        });

        // Remove client_id from users (drop FK first).
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');
        });

        // Remove client_id from all resource tables.
        $resourceTables = [
            'contacts', 'lists', 'tags', 'newsletters', 'sequences',
            'automations', 'blacklisted_emails', 'email_credentials',
            'email_identities', 'contact_attributes', 'messages',
            'contact_mails', 'contact_imports', 'user_storage_settings',
        ];

        foreach ($resourceTables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('client_id');
            });
        }

        // Drop the clients table.
        Schema::dropIfExists('clients');
    }
};
