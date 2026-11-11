<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('type', 80)->index();
            $table->string('manufacturer', 100);
            $table->string('model', 120);
            $table->string('identifier', 120)->nullable()->unique();
            $table->string('location', 120)->nullable();
            $table->string('connection_method', 80);
            $table->json('configuration');
            $table->string('administrative_status', 24)->default('pending')->index();
            $table->string('connection_status', 24)->default('unknown')->index();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_test_result', 24)->nullable();
            $table->text('last_test_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'administrative_status']);
        });

        Schema::create('device_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40)->index();
            $table->string('operation', 80)->nullable();
            $table->string('result', 24)->nullable();
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_events');
        Schema::dropIfExists('devices');
    }
};
