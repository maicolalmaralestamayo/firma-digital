<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Document extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentFactory> */
    use HasFactory, SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_SEALED = 'sealed';

    protected $fillable = [
        'uuid',
        'owner_id',
        'title',
        'status',
        'signers_required',
        'page_count',
        'original_path',
        'current_path',
        'original_sha256',
        'current_sha256',
        'signature_count',
        'sealed_at',
        'sealed_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (Document $document): void {
            $document->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'signers_required' => 'integer',
            'page_count' => 'integer',
            'signature_count' => 'integer',
            'sealed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function sealedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sealed_by');
    }

    public function signers(): HasMany
    {
        return $this->hasMany(DocumentSigner::class)->orderBy('position');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class)->orderBy('sequence');
    }

    public function deletionRequests(): HasMany
    {
        return $this->hasMany(DocumentDeletionRequest::class)->latest('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class)->latest('id');
    }

    public function isSealed(): bool
    {
        return $this->status === self::STATUS_SEALED;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function remainingSignatures(): int
    {
        return max(0, $this->signers_required - $this->signature_count);
    }

    public function signerFor(User $user): ?DocumentSigner
    {
        return $this->signers()->where('user_id', $user->id)->first();
    }

    public function hasParticipant(User $user): bool
    {
        if ($this->owner_id === $user->id) {
            return true;
        }

        return $this->signers()->where('user_id', $user->id)->exists();
    }

    /** Documents the user owns or is invited to sign. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->where('owner_id', $user->id)
                ->orWhereHas('signers', fn (Builder $query) => $query->where('user_id', $user->id));
        });
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('owner_id', $user->id);
    }

    public function scopeSharedWith(Builder $query, User $user): Builder
    {
        return $query->whereHas('signers', fn (Builder $query) => $query->where('user_id', $user->id));
    }
}
