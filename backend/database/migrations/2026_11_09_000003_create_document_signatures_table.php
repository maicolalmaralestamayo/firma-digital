<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('document_signer_id')->nullable()->constrained('document_signers')->nullOnDelete();
            $table->string('field_name', 64);
            $table->unsignedSmallInteger('sequence');
            $table->string('signer_name', 190);
            $table->string('signer_email', 190)->nullable();
            $table->string('certificate_subject', 512);
            $table->string('certificate_cn', 190)->nullable();
            $table->string('certificate_serial', 128);
            $table->string('certificate_issuer', 512);
            $table->string('certificate_fingerprint', 128);
            $table->timestampTz('certificate_valid_from')->nullable();
            $table->timestampTz('certificate_valid_to')->nullable();
            $table->timestampTz('signed_at');
            $table->unsignedSmallInteger('page');
            $table->decimal('x', 8, 2);
            $table->decimal('y', 8, 2);
            $table->decimal('width', 8, 2);
            $table->decimal('height', 8, 2);
            $table->char('previous_sha256', 64)->nullable();
            $table->char('signed_sha256', 64);
            $table->string('reason', 190)->nullable();
            $table->string('location', 190)->nullable();
            $table->timestampsTz();

            $table->unique(['document_id', 'sequence']);
            $table->unique(['document_id', 'field_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signatures');
    }
};
