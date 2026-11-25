<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->migrateListIdsToFilters();

        Schema::table('newsletters', function (Blueprint $table) {
            $table->dropForeign(['list_id']);
            $table->dropColumn('list_id');
        });
    }

    /**
     * Migrate existing list_id values to list filters.
     */
    private function migrateListIdsToFilters(): void
    {
        $newsletters = DB::table('newsletters')
            ->whereNotNull('list_id')
            ->where('list_id', '>', 0)
            ->get();

        foreach ($newsletters as $newsletter) {
            $filters = json_decode($newsletter->filters ?? '[]', true);

            if (! is_array($filters)) {
                $filters = [];
            }

            $hasListFilter = collect($filters)->contains(
                fn ($filter) => ($filter['category'] ?? null) === 'lists'
            );

            if ($hasListFilter) {
                continue;
            }

            $listFilter = [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [(int) $newsletter->list_id],
            ];

            array_unshift($filters, $listFilter);

            DB::table('newsletters')
                ->where('id', $newsletter->id)
                ->update(['filters' => json_encode($filters)]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table) {
            $table->foreignId('list_id')->nullable()->after('user_id')->constrained('lists')->cascadeOnDelete();
        });
    }
};
