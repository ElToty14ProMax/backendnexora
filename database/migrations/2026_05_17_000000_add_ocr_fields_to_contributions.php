<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->string('sender_ocr_transaction_id', 80)->nullable()->after('sender_receipt_submitted_at');
            $table->bigInteger('sender_ocr_amount_cents')->nullable()->after('sender_ocr_transaction_id');
            $table->string('sender_ocr_confidence', 20)->nullable()->after('sender_ocr_amount_cents');
            $table->string('sender_ocr_provider', 20)->nullable()->after('sender_ocr_confidence');
            $table->text('sender_ocr_raw_text')->nullable()->after('sender_ocr_provider');

            $table->string('receiver_ocr_transaction_id', 80)->nullable()->after('receiver_receipt_submitted_at');
            $table->bigInteger('receiver_ocr_amount_cents')->nullable()->after('receiver_ocr_transaction_id');
            $table->string('receiver_ocr_confidence', 20)->nullable()->after('receiver_ocr_amount_cents');
            $table->string('receiver_ocr_provider', 20)->nullable()->after('receiver_ocr_confidence');
            $table->text('receiver_ocr_raw_text')->nullable()->after('receiver_ocr_provider');

            $table->string('ocr_comparison_result', 20)->nullable()->after('receiver_ocr_raw_text');
            $table->text('ocr_comparison_notes')->nullable()->after('ocr_comparison_result');
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropColumn([
                'sender_ocr_transaction_id',
                'sender_ocr_amount_cents',
                'sender_ocr_confidence',
                'sender_ocr_provider',
                'sender_ocr_raw_text',
                'receiver_ocr_transaction_id',
                'receiver_ocr_amount_cents',
                'receiver_ocr_confidence',
                'receiver_ocr_provider',
                'receiver_ocr_raw_text',
                'ocr_comparison_result',
                'ocr_comparison_notes',
            ]);
        });
    }
};