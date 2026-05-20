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

        Schema::table('contributions', function (Blueprint $table) {
            if (! Schema::hasColumn('contributions', 'rejected_reason')) {
                $table->text('rejected_reason')->nullable()->after('status');
            }
            if (! Schema::hasColumn('contributions', 'sender_ocr_transaction_id')) {
                $table->string('sender_ocr_transaction_id', 80)->nullable()->after('sender_receipt_submitted_at');
            }
            if (! Schema::hasColumn('contributions', 'sender_ocr_amount_cents')) {
                $table->bigInteger('sender_ocr_amount_cents')->nullable()->after('sender_ocr_transaction_id');
            }
            if (! Schema::hasColumn('contributions', 'sender_ocr_confidence')) {
                $table->string('sender_ocr_confidence', 20)->nullable()->after('sender_ocr_amount_cents');
            }
            if (! Schema::hasColumn('contributions', 'sender_ocr_provider')) {
                $table->string('sender_ocr_provider', 20)->nullable()->after('sender_ocr_confidence');
            }
            if (! Schema::hasColumn('contributions', 'sender_ocr_raw_text')) {
                $table->text('sender_ocr_raw_text')->nullable()->after('sender_ocr_provider');
            }
            if (! Schema::hasColumn('contributions', 'receiver_ocr_transaction_id')) {
                $table->string('receiver_ocr_transaction_id', 80)->nullable()->after('receiver_receipt_submitted_at');
            }
            if (! Schema::hasColumn('contributions', 'receiver_ocr_amount_cents')) {
                $table->bigInteger('receiver_ocr_amount_cents')->nullable()->after('receiver_ocr_transaction_id');
            }
            if (! Schema::hasColumn('contributions', 'receiver_ocr_confidence')) {
                $table->string('receiver_ocr_confidence', 20)->nullable()->after('receiver_ocr_amount_cents');
            }
            if (! Schema::hasColumn('contributions', 'receiver_ocr_provider')) {
                $table->string('receiver_ocr_provider', 20)->nullable()->after('receiver_ocr_confidence');
            }
            if (! Schema::hasColumn('contributions', 'receiver_ocr_raw_text')) {
                $table->text('receiver_ocr_raw_text')->nullable()->after('receiver_ocr_provider');
            }
            if (! Schema::hasColumn('contributions', 'ocr_comparison_result')) {
                $table->string('ocr_comparison_result', 20)->nullable()->after('receiver_ocr_raw_text');
            }
            if (! Schema::hasColumn('contributions', 'ocr_comparison_notes')) {
                $table->text('ocr_comparison_notes')->nullable()->after('ocr_comparison_result');
            }
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
        // This migration repairs production drift; rolling it back should not remove data.
    }
};
