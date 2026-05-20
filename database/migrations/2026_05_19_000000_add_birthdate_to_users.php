<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'birthdate')) {
                $table->date('birthdate')->nullable()->after('cpf_cipher');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'birthdate')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('birthdate');
        });
    }
};
