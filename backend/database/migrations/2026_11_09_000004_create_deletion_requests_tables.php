<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_deletion_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('reason', 500)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['document_id', 'status']);
        });

        Schema::create('document_deletion_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deletion_request_id')
                ->constrained('document_deletion_requests')
                ->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('decision', 20);
            $table->string('comment', 500)->nullable();
            $table->timestampTz('decided_at');
            $table->timestampsTz();

            $table->unique(['deletion_request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_deletion_consents');
        Schema::dropIfExists('document_deletion_requests');
    }
};
