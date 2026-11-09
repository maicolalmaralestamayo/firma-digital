<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSignature extends Model
{
    protected $fillable = [
        'document_id',
        'user_id',
        'document_signer_id',
        'field_name',
        'sequence',
        'signer_name',
        'signer_email',
        'certificate_subject',
        'certificate_cn',
        'certificate_serial',
        'certificate_issuer',
        'certificate_fingerprint',
        'certificate_valid_from',
        'certificate_valid_to',
        'signed_at',
        'page',
        'x',
        'y',
        'width',
        'height',
        'previous_sha256',
        'signed_sha256',
        'reason',
        'location',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'page' => 'integer',
            'x' => 'float',
            'y' => 'float',
            'width' => 'float',
            'height' => 'float',
            'signed_at' => 'datetime',
            'certificate_valid_from' => 'datetime',
            'certificate_valid_to' => 'datetime',
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

    public function signer(): BelongsTo
    {
        return $this->belongsTo(DocumentSigner::class, 'document_signer_id');
    }
}
