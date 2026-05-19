<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->string('verification_status', 30)->nullable()->after('ocr_comparison_notes');
            $table->boolean('admin_review_required')->default(false)->after('verification_status');
            $table->boolean('has_sender_receipt')->default(false)->after('admin_review_required');
            $table->boolean('has_receiver_receipt')->default(false)->after('has_sender_receipt');
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropColumn([
                'has_sender_receipt',
                'has_receiver_receipt',
                'admin_review_required',
                'verification_status',
            ]);
        });
    }
};
