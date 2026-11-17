<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_generation_id')->nullable()->unique();
            $table->uuid('idempotency_key')->unique();
            $table->text('prompt');
            $table->string('size', 12);
            $table->string('quality', 10);
            $table->json('reference_images');
            $table->string('status', 24)->default('submitting')->index();
            $table->decimal('estimated_customer_usd', 24, 12);
            $table->decimal('minimum_customer_usd', 24, 12);
            $table->decimal('maximum_customer_usd', 24, 12);
            $table->decimal('provider_cost_usd', 24, 12)->nullable();
            $table->decimal('customer_cost_usd', 24, 12)->nullable();
            $table->json('rates_snapshot')->nullable();
            $table->json('usage')->nullable();
            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('provider_created_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
