<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Knowledge brain (ADR-0002, plan D2): a versioned virtual filesystem
 * with folder semantics, stored as rows so it works on SQLite in CI and on
 * Postgres in prod. brain_files is the current head of every path,
 * brain_file_versions is the append-only history, brain_proposals are
 * agent/user-suggested changes that only land through ApprovalEngine.
 *
 * Plain Blueprint calls and string jsonb defaults only (no ->vector(), no
 * ::jsonb casts) so the Feature suite runs on sqlite :memory:.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brain_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();

            // Canonical path from packages/brain/manifest.yaml, e.g.
            // business/profile.md, people/user.md, workspaces/growth/scratch/ideas.md
            $table->string('path', 255);
            $table->string('title')->nullable();
            $table->longText('content')->default('');
            // Structured facts projected from their source tables (pricing,
            // proof_points[], links[]...). Prose stays in `content`.
            $table->jsonb('frontmatter')->default('{}');

            // human | projection | agent
            $table->string('source', 16)->default('human');
            // True when BrainSyncService owns <!-- managed --> blocks in this file.
            $table->boolean('managed')->default(false);
            // public | internal | confidential | personal (manifest data_class; EgressGuard reads it)
            $table->string('data_class', 16)->default('internal');

            $table->unsignedInteger('version')->default(1);
            $table->char('content_hash', 64);
            // Version last chunked into memory_nodes by BrainIndexer (null = never).
            $table->unsignedInteger('embedded_version')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'path']);
            $table->index(['tenant_id', 'source']);
            $table->index(['tenant_id', 'managed']);
        });

        Schema::create('brain_file_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('brain_file_id');
            $table->foreign('brain_file_id')->references('id')->on('brain_files')->onDelete('cascade');

            $table->unsignedInteger('version');
            $table->longText('content')->default('');
            $table->jsonb('frontmatter')->default('{}');
            $table->char('content_hash', 64);
            $table->string('source', 16)->default('human');

            // Who wrote this version: user | agent | system (+ a user id, run id or job name).
            $table->string('author_type', 16)->default('user');
            $table->string('author_ref', 128)->nullable();
            $table->string('change_summary')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->unique(['brain_file_id', 'version']);
        });

        Schema::create('brain_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();

            $table->string('path', 255);
            // Null when the proposal creates a new file.
            $table->uuid('brain_file_id')->nullable();
            // Head version the proposal was written against; BrainStore
            // rejects application (409) when the file has moved on.
            $table->unsignedInteger('base_version')->nullable();

            $table->longText('proposed_content');
            $table->jsonb('proposed_frontmatter')->default('{}');
            $table->text('rationale')->nullable();

            // pending | approved | rejected | applied | superseded
            $table->string('status', 16)->default('pending');
            $table->uuid('approval_id')->nullable();

            // agent | user | system, plus the run id / user id that proposed it.
            $table->string('proposed_by_type', 16)->default('agent');
            $table->string('proposed_by_ref', 128)->nullable();
            $table->uuid('agent_run_id')->nullable();

            $table->unsignedInteger('applied_version')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'path']);
            $table->index('approval_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brain_proposals');
        Schema::dropIfExists('brain_file_versions');
        Schema::dropIfExists('brain_files');
    }
};
