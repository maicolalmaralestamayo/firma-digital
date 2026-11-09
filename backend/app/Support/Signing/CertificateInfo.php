<?php

namespace App\Support\Signing;

use Carbon\CarbonImmutable;

final readonly class CertificateInfo
{
    public function __construct(
        public string $subject,
        public ?string $commonName,
        public ?string $email,
        public string $serial,
        public string $issuer,
        public string $fingerprint,
        public ?CarbonImmutable $validFrom,
        public ?CarbonImmutable $validTo,
    ) {}

    public function isExpired(): bool
    {
        return $this->validTo !== null && $this->validTo->isPast();
    }

    public function isNotYetValid(): bool
    {
        return $this->validFrom !== null && $this->validFrom->isFuture();
    }

    /** Name to print on the stamp: certificate CN, else the full subject. */
    public function displayName(): string
    {
        return $this->commonName ?? $this->subject;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'common_name' => $this->commonName,
            'email' => $this->email,
            'serial' => $this->serial,
            'issuer' => $this->issuer,
            'fingerprint_sha256' => $this->fingerprint,
            'valid_from' => $this->validFrom?->toIso8601String(),
            'valid_to' => $this->validTo?->toIso8601String(),
            'expired' => $this->isExpired(),
        ];
    }
}
