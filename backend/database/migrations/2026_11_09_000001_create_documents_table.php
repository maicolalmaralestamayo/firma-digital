<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 180);
            $table->string('status', 20)->default('open');
            $table->unsignedSmallInteger('signers_required');
            $table->unsignedInteger('page_count');
            $table->string('original_path');
            $table->string('current_path');
            $table->char('original_sha256', 64);
            $table->char('current_sha256', 64);
            $table->unsignedSmallInteger('signature_count')->default(0);
            $table->timestampTz('sealed_at')->nullable();
            $table->foreignId('sealed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();

            $table->index(['owner_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
