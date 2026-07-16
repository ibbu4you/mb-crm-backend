<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-user overrides: permission names the user is DENIED even when a
            // role grants them. Spatie permissions are additive, so this is the
            // only way to revoke a single role-granted permission for one person.
            $table->json('denied_permissions')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('denied_permissions');
        });
    }
};
