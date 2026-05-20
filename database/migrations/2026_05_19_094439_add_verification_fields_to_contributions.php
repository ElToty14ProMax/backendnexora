<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            if (! Schema::hasColumn('contributions', 'verification_status')) {
                $table->string('verification_status', 30)->nullable()->after('ocr_comparison_notes');
            }
            if (! Schema::hasColumn('contributions', 'admin_review_required')) {
                $table->boolean('admin_review_required')->default(false)->after('verification_status');
            }
            if (! Schema::hasColumn('contributions', 'has_sender_receipt')) {
                $table->boolean('has_sender_receipt')->default(false)->after('admin_review_required');
            }
            if (! Schema::hasColumn('contributions', 'has_receiver_receipt')) {
                $table->boolean('has_receiver_receipt')->default(false)->after('has_sender_receipt');
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter([
            'has_sender_receipt',
            'has_receiver_receipt',
            'admin_review_required',
            'verification_status',
        ], fn (string $column): bool => Schema::hasColumn('contributions', $column)));

        if ($columns === []) {
            return;
        }

        Schema::table('contributions', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
