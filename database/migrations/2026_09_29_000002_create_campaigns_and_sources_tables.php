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
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->text('ai_brief')->nullable();
            $table->string('template', 64)->nullable();
            $table->json('categories')->nullable();
            $table->json('extraction_fields')->nullable();
            $table->json('keywords')->nullable();
            $table->string('region', 8)->default('IN');
            $table->string('language', 16)->default('en-IN');
            $table->unsignedSmallInteger('freshness_days')->default(30);
            $table->string('status', 32)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('driver', 32)->nullable();
            $table->string('name');
            $table->text('target');
            $table->json('config')->nullable();
            $table->unsignedInteger('fetch_interval_minutes')->default(60);
            $table->timestamp('next_fetch_at')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->string('last_status', 32)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('consecutive_errors')->default(0);
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['workspace_id', 'campaign_id']);
            $table->index(['is_active', 'next_fetch_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
        Schema::dropIfExists('campaigns');
    }
};
