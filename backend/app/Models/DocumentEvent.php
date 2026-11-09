<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'document_id',
        'user_id',
        'type',
        'description',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @param  array<string, mixed>  $meta */
    public static function record(Document $document, ?User $user, string $type, string $description, array $meta = []): self
    {
        return self::create([
            'document_id' => $document->id,
            'user_id' => $user?->id,
            'type' => $type,
            'description' => $description,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
