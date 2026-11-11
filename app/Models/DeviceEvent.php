<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceEvent extends Model
{
    public const RESULT_UNAVAILABLE = 'unavailable';

    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'user_id',
        'event_type',
        'operation',
        'result',
        'message',
        'metadata',
        'duration_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
