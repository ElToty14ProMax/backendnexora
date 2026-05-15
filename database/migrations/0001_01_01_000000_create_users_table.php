<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('public_id')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->boolean('email_verified')->default(false);
            $table->string('verification_code_hash')->nullable();
            $table->bigInteger('verification_expires_at')->nullable();
            $table->string('password_reset_code_hash')->nullable();
            $table->bigInteger('password_reset_expires_at')->nullable();
            $table->string('cpf_hash')->unique();
            $table->text('cpf_cipher');
            $table->text('pix_cipher');
            $table->text('password_hash');
            $table->string('status', 40);
            $table->string('role', 40);
            $table->bigInteger('xp')->default(0);
            $table->integer('level')->default(1);
            $table->integer('buff_bps')->default(0);
            $table->bigInteger('on_time_returned_cents')->default(0);
            $table->bigInteger('early_returned_cents')->default(0);
            $table->string('invited_by')->nullable();
            $table->string('invite_code')->unique();
            $table->bigInteger('created_at_ms');
            $table->bigInteger('admin_fee_due_cents')->default(0);

        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
