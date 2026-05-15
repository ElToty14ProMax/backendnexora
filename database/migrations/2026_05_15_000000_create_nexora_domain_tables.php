<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_tokens', function (Blueprint $table) {
            $table->string('token_hash')->primary();
            $table->string('user_id');
            $table->bigInteger('expires_at');
            $table->bigInteger('created_at_ms');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('support_requests', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('requester_id');
            $table->string('public_code')->unique();
            $table->bigInteger('amount_cents');
            $table->bigInteger('funded_cents')->default(0);
            $table->integer('due_days');
            $table->bigInteger('due_at')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 40);
            $table->bigInteger('created_at_ms');
            $table->bigInteger('approved_at')->nullable();
            $table->bigInteger('returned_at')->nullable();
            $table->text('rejected_reason')->nullable();

            $table->foreign('requester_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('contributions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('request_id');
            $table->string('donor_id');
            $table->bigInteger('amount_cents');
            $table->string('status', 40);
            $table->bigInteger('created_at_ms');
            $table->bigInteger('confirmed_at')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('sender_receipt_hash', 64)->nullable();
            $table->longText('sender_receipt_image_base64')->nullable();
            $table->string('sender_receipt_mime_type')->nullable();
            $table->string('sender_receipt_date')->nullable();
            $table->bigInteger('sender_receipt_submitted_at')->nullable();
            $table->string('receiver_receipt_hash', 64)->nullable();
            $table->longText('receiver_receipt_image_base64')->nullable();
            $table->string('receiver_receipt_mime_type')->nullable();
            $table->string('receiver_receipt_date')->nullable();
            $table->bigInteger('receiver_receipt_submitted_at')->nullable();

            $table->foreign('request_id')->references('id')->on('support_requests')->cascadeOnDelete();
            $table->foreign('donor_id')->references('id')->on('users')->cascadeOnDelete();
        });
        DB::statement('CREATE UNIQUE INDEX idx_contributions_transaction_id ON contributions(transaction_id) WHERE transaction_id IS NOT NULL');

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('actor_user_id')->nullable();
            $table->string('action');
            $table->text('target');
            $table->bigInteger('created_at_ms');

            $table->foreign('actor_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('pix_receipts', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('contribution_id');
            $table->string('receipt_hash', 64)->unique();
            $table->bigInteger('amount_cents');
            $table->string('receipt_date');
            $table->bigInteger('submitted_at');
            $table->string('status', 40);

            $table->foreign('contribution_id')->references('id')->on('contributions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pix_receipts');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('contributions');
        Schema::dropIfExists('support_requests');
        Schema::dropIfExists('auth_tokens');
    }
};
