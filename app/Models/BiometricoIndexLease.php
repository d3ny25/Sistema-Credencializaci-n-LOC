<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricoIndexLease extends Model
{
    protected $table = 'biometrico_index_leases';

    protected $fillable = [
        'ambito',
        'owner_token',
        'biometrico_index_run_id',
        'fencing_token',
        'lease_expires_at',
        'heartbeat_at',
        'worker_pid',
        'worker_host',
    ];

    protected $casts = [
        'fencing_token' => 'integer',
        'lease_expires_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'worker_pid' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BiometricoIndexRun::class, 'biometrico_index_run_id');
    }

    public function isExpired(): bool
    {
        return $this->lease_expires_at <= now();
    }
}

