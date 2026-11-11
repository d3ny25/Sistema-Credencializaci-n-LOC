<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CredentialTemplate extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'status',
        'current_version',
        'published_version',
        'template_config',
        'published_config',
        'updated_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'template_config' => 'array',
            'published_config' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CredentialTemplateVersion::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
