<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            if (! Schema::hasColumn('contributions', 'rejected_reason')) {
                $table->text('rejected_reason')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contributions', 'rejected_reason')) {
            return;
        }

        Schema::table('contributions', function (Blueprint $table) {
            $table->dropColumn('rejected_reason');
        });
    }
};
