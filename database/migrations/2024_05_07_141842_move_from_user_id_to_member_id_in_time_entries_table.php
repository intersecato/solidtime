<?php

declare(strict_types=1);

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

        if (! Schema::hasColumn('time_entries', 'member_id')) {
            Schema::table('time_entries', function (Blueprint $table): void {
                $table->foreignUuid('member_id')
                    ->nullable()
                    ->constrained('organization_user')
                    ->cascadeOnDelete()
                    ->cascadeOnUpdate();
            });
        }
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('
                update time_entries
                join organization_user on time_entries.organization_id = organization_user.organization_id
                    and time_entries.user_id = organization_user.user_id
                set time_entries.member_id = organization_user.id
            ');
        } else {
            DB::statement('
                update time_entries
                set member_id = organization_user.id
                from organization_user
                where time_entries.organization_id = organization_user.organization_id and
                      time_entries.user_id = organization_user.user_id
            ');
        }
        Schema::table('time_entries', function (Blueprint $table): void {
            $table->uuid('member_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table): void {
            $table->dropForeign(['member_id']);
            $table->dropColumn('member_id');
        });
    }
};
