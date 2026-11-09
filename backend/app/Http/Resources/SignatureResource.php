<?php

namespace App\Http\Resources;

use App\Http\JsonApi\JsonApiResource;
use App\Models\DocumentSignature;
use Illuminate\Database\Eloquent\Model;

/** @mixin DocumentSignature */
class SignatureResource extends JsonApiResource
{
    public function type(): string
    {
        return 'signatures';
    }

    public function id(Model $model): string
    {
        return (string) $model->id;
    }

    public function attributes(Model $model): array
    {
        return [
            'field_name' => $model->field_name,
            'sequence' => $model->sequence,
            'signer_name' => $model->signer_name,
            'signer_email' => $model->signer_email,
            'certificate' => [
                'subject' => $model->certificate_subject,
                'common_name' => $model->certificate_cn,
                'serial' => $model->certificate_serial,
                'issuer' => $model->certificate_issuer,
                'fingerprint_sha256' => $model->certificate_fingerprint,
                'valid_from' => $this->isoDate($model->certificate_valid_from),
                'valid_to' => $this->isoDate($model->certificate_valid_to),
            ],
            'signed_at' => $this->isoDate($model->signed_at),
            'placement' => [
                'page' => $model->page,
                'x' => $model->x,
                'y' => $model->y,
                'width' => $model->width,
                'height' => $model->height,
                'coordinate_system' => 'points from the top-left corner of the displayed page',
            ],
            'previous_sha256' => $model->previous_sha256,
            'signed_sha256' => $model->signed_sha256,
            'reason' => $model->reason,
            'location' => $model->location,
            'created_at' => $this->isoDate($model->created_at),
        ];
    }

    public function relationships(Model $model): array
    {
        return [
            'document' => $this->toOne('documents', $model->document?->uuid),
            'user' => $this->toOne('users', $model->user_id),
        ];
    }

    public function links(Model $model): array
    {
        return $model->document !== null
            ? ['document' => route('api.v1.documents.show', ['document' => $model->document->uuid])]
            : [];
    }
}
