<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_signers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('email', 190);
            $table->unsignedSmallInteger('position');
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['document_id', 'email']);
            $table->unique(['document_id', 'user_id']);
            $table->unique(['document_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signers');
    }
};
