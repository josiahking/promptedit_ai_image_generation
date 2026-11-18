<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('type');
            $table->uuid('generation_id')->nullable()->index();
            $table->string('provider_generation_id')->nullable()->index();
            $table->json('payload');
            $table->timestampTz('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
