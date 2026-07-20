<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 128)->unique();                 // R3.1 contrainte d'unicité
            $table->string('request_fingerprint', 64);            // sha256 hex
            $table->string('status', 20)->default('processing');  // processing | completed
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();        // R3.4
            $table->foreignId('report_id')                        // R3.2
                ->nullable()
                ->constrained('reports')
                ->nullOnDelete();
            $table->timestamps();
            $table->index('created_at');                          // purge par expiration
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
