<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSigner extends Model
{
    protected $fillable = [
        'document_id',
        'user_id',
        'email',
        'position',
        'invited_at',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'invited_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    public function isClaimed(): bool
    {
        return $this->user_id !== null;
    }
}
