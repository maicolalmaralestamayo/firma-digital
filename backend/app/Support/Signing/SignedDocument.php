<?php

namespace App\Support\Signing;

use Carbon\CarbonImmutable;

final readonly class SignedDocument
{
    public function __construct(
        public string $bytes,
        public string $sha256,
        public CertificateInfo $certificate,
        public CarbonImmutable $signedAt,
        public string $fieldName,
        public int $sequence,
    ) {}
}
