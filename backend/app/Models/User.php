<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Documents created by this user. */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'owner_id');
    }

    /** Invitations to sign documents. */
    public function signingInvitations(): HasMany
    {
        return $this->hasMany(DocumentSigner::class);
    }

    /** Signatures this user has applied. */
    public function signatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class);
    }

    /** Deletion requests this user created. */
    public function deletionRequests(): HasMany
    {
        return $this->hasMany(DocumentDeletionRequest::class, 'requested_by');
    }

    /** Consent decisions this user has taken. */
    public function deletionConsents(): HasMany
    {
        return $this->hasMany(DocumentDeletionConsent::class);
    }
}
