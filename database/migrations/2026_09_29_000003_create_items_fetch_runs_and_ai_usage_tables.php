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
        Schema::create('fetch_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->unsignedInteger('items_found')->default(0);
            $table->unsignedInteger('items_new')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->index(['source_id', 'started_at']);
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable();
            $table->text('url');
            $table->text('canonical_url');
            $table->char('url_hash', 64);
            $table->string('title', 500);
            $table->text('excerpt')->nullable();
            $table->mediumText('content_text')->nullable();
            $table->json('media')->nullable();
            $table->string('author')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('fetched_at');

            $table->string('ai_status', 20)->default('pending');
            $table->string('ai_title', 500)->nullable();
            $table->text('ai_summary')->nullable();
            $table->string('ai_category', 100)->nullable();
            $table->json('ai_fields')->nullable();
            $table->unsignedTinyInteger('ai_relevance')->nullable();
            $table->text('ai_reason')->nullable();
            $table->string('ai_model', 100)->nullable();
            $table->timestamp('enriched_at')->nullable();
            $table->unsignedBigInteger('cluster_id')->nullable()->index();

            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->string('edited_title', 500)->nullable();
            $table->text('edited_summary')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            $table->unique(['campaign_id', 'url_hash']);
            $table->index(['workspace_id', 'campaign_id', 'status', 'published_at']);
            $table->index(['campaign_id', 'ai_status']);
        });

        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('provider', 32);
            $table->string('model', 100);
            $table->string('purpose', 50);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedInteger('web_search_calls')->default(0);
            $table->decimal('est_cost_usd', 12, 6)->default(0);
            $table->timestamps();

            $table->unique(['workspace_id', 'date', 'provider', 'model', 'purpose']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('items');
        Schema::dropIfExists('fetch_runs');
    }
};
