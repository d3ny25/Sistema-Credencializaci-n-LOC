<?php

declare(strict_types=1);

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
        Schema::create('biometrico_index_leases', function (Blueprint $table): void {
            $table->id();
            $table->string('ambito', 50)->unique('uq_bio_lease_ambito');
            $table->string('owner_token', 36)->index('idx_bio_lease_owner');
            $table->foreignId('biometrico_index_run_id')
                ->constrained('biometrico_index_runs')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('fencing_token')->default(1);
            $table->dateTime('lease_expires_at')->index('idx_bio_lease_expires');
            $table->dateTime('heartbeat_at');
            $table->unsignedInteger('worker_pid')->nullable();
            $table->string('worker_host', 100)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometrico_index_leases');
    }
};

