<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('current_version')->default(0);
            $table->unsignedInteger('published_version')->nullable();
            $table->json('template_config');
            $table->json('published_config')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('credential_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_template_id')
                ->constrained('credential_templates')
                ->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('template_config');
            $table->string('change_summary', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['credential_template_id', 'version'],
                'ctv_template_version_unique'
            );

            $table->index(
                ['credential_template_id', 'approved_at'],
                'ctv_template_approved_at_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_template_versions');
        Schema::dropIfExists('credential_templates');
    }
};
