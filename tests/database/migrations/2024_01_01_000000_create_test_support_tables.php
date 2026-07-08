<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner / relation tables the test suite's models attach to. These live as a
 * real migration (loaded only in tests via TestCase::defineDatabaseMigrations)
 * rather than being created imperatively in setUp()/beforeEach(): RefreshDatabase
 * runs `migrate:fresh`, which drops any table that isn't a migration, and on
 * MySQL — where DDL auto-commits — imperative creation also broke per-test
 * transaction isolation. Going through the migrator makes the tables exist on
 * every driver, for every test, the same way the package's own tables do.
 */
return new class extends Migration
{
    public function up(): void
    {
        $simpleOwner = static function (string $name): void {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->timestamps();
            });
        };

        foreach ([
            'test_users',
            'bulk_test_users',
            'bulk_set_test_users',
            'defined_counter_users',
            'global_test_owners',
            'prune_test_users',
            'sync_test_users',
            'recount_interval_test_users',
            'relation_test_users',
        ] as $name) {
            $simpleOwner($name);
        }

        Schema::create('recount_subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('things_count')->default(0);
            $table->timestamps();
        });

        Schema::create('recount_plain_models', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('recount_interval_test_logins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
        });

        Schema::create('relation_test_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'relation_test_posts',
            'recount_interval_test_logins',
            'recount_plain_models',
            'recount_subjects',
            'relation_test_users',
            'recount_interval_test_users',
            'sync_test_users',
            'prune_test_users',
            'global_test_owners',
            'defined_counter_users',
            'bulk_set_test_users',
            'bulk_test_users',
            'test_users',
        ] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
