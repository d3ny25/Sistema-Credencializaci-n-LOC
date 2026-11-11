<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    protected $fillable = [
        'name',
        'type',
        'manufacturer',
        'model',
        'identifier',
        'location',
        'connection_method',
        'configuration',
        'administrative_status',
        'connection_status',
        'last_checked_at',
        'last_test_result',
        'last_test_message',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceEvent::class)->latest();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
