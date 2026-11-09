<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentSignature;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registered-user workflow: creation with N signature slots, sharing with
 * signers, incremental multi-signature and automatic sealing by the last
 * signer.
 */
final class DocumentWorkflowService
{
    public function __construct(private readonly SigningService $signing) {}

    /**
     * @param  array{title: string, signers_required: int, signers: list<string>}  $data
     */
    public function create(User $owner, array $data, UploadedFile $pdf, ?string $reason = null): Document
    {
        $pdfBytes = (string) $pdf->get();
        $info = $this->signing->inspectPdf($pdfBytes);

        $signers = array_values(array_unique(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            $data['signers'],
        )));

        if (count($signers) !== (int) $data['signers_required']) {
            throw new DomainException(
                422,
                'signers_count_mismatch',
                'Cantidad de firmantes inconsistente',
                'La cantidad de firmantes declarada debe coincidir con la cantidad de correos invitados.',
                ['pointer' => '/data/attributes/signers'],
            );
        }

        $uuid = (string) Str::uuid();
        $path = $this->documentPath($uuid, 'original.pdf');
        $disk = Storage::disk((string) config('firma.disk'));

        if (! $disk->put($path, $pdfBytes)) {
            throw new DomainException(500, 'storage_failed', 'No se pudo guardar el documento', 'Revisa los permisos del disco de almacenamiento.');
        }

        try {
            return DB::transaction(function () use ($owner, $data, $signers, $uuid, $path, $info, $reason): Document {
                $document = Document::create([
                    'uuid' => $uuid,
                    'owner_id' => $owner->id,
                    'title' => $data['title'],
                    'status' => Document::STATUS_OPEN,
                    'signers_required' => (int) $data['signers_required'],
                    'page_count' => $info->pageCount,
                    'original_path' => $path,
                    'current_path' => $path,
                    'original_sha256' => $info->sha256,
                    'current_sha256' => $info->sha256,
                    'signature_count' => 0,
                ]);

                foreach ($signers as $index => $email) {
                    $invitedUser = User::query()
                        ->whereRaw('lower(email) = ?', [$email])
                        ->first();

                    $document->signers()->create([
                        'user_id' => $invitedUser?->id,
                        'email' => $email,
                        'position' => $index + 1,
                        'invited_at' => now(),
                    ]);
                }

                DocumentEvent::record(
                    $document,
                    $owner,
                    'document_created',
                    sprintf('Documento creado con %d firmante(s) requerido(s).', $document->signers_required),
                    [
                        'sha256' => $info->sha256,
                        'page_count' => $info->pageCount,
                        'signers' => $signers,
                        'reason' => $reason,
                    ],
                );

                return $document->load(['owner', 'signers.user', 'signatures']);
            });
        } catch (Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }
    }

    /**
     * Applies one signer's signature. The last signature seals the document.
     *
     * @param  array{page: int|string, x: float|int|string, y: float|int|string, width: float|int|string, height: float|int|string}  $placement
     * @param  array{reason?: ?string, location?: ?string, signer_name?: ?string}  $meta
     */
    public function sign(
        Document $document,
        User $user,
        string $pkcs12Bytes,
        string $password,
        string $imageBytes,
        array $placement,
        array $meta = [],
    ): DocumentSignature {
        $disk = Storage::disk((string) config('firma.disk'));

        return DB::transaction(function () use ($document, $user, $pkcs12Bytes, $password, $imageBytes, $placement, $meta, $disk): DocumentSignature {
            /** @var Document $locked */
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($locked->isSealed()) {
                throw DomainException::documentSealed();
            }

            $signerSlot = $locked->signers()->where('user_id', $user->id)->lockForUpdate()->first();

            if ($signerSlot === null) {
                throw DomainException::notASigner();
            }

            if ($signerSlot->isSigned()) {
                throw DomainException::alreadySigned();
            }

            if ($locked->signature_count >= $locked->signers_required) {
                throw DomainException::documentSealed();
            }

            $pdfBytes = $disk->get($locked->current_path);

            if (! is_string($pdfBytes) || $pdfBytes === '') {
                throw new DomainException(500, 'document_file_missing', 'No se encontró el archivo', 'El PDF del documento no está disponible en el almacenamiento.');
            }

            $sequence = $locked->signature_count + 1;
            $signerName = trim((string) ($meta['signer_name'] ?? '')) ?: $user->name;
            $reason = $meta['reason'] ?? null;
            $location = $meta['location'] ?? null;

            $signed = $this->signing->sign(
                pdfBytes: $pdfBytes,
                pkcs12Bytes: $pkcs12Bytes,
                password: $password,
                imageBytes: $imageBytes,
                placement: $placement,
                signerName: $signerName,
                reason: $reason,
                location: $location,
                fieldName: 'FirmaDigital'.$sequence,
                sequence: $sequence,
                signedAt: CarbonImmutable::now(),
            );

            $newPath = $this->documentPath($locked->uuid, 'v'.$sequence.'.pdf');

            if (! $disk->put($newPath, $signed->bytes)) {
                throw new DomainException(500, 'storage_failed', 'No se pudo guardar la firma', 'Revisa los permisos del disco de almacenamiento.');
            }

            $signature = DocumentSignature::create([
                'document_id' => $locked->id,
                'user_id' => $user->id,
                'document_signer_id' => $signerSlot->id,
                'field_name' => $signed->fieldName,
                'sequence' => $signed->sequence,
                'signer_name' => $signerName,
                'signer_email' => $user->email,
                'certificate_subject' => $signed->certificate->subject,
                'certificate_cn' => $signed->certificate->commonName,
                'certificate_serial' => $signed->certificate->serial,
                'certificate_issuer' => $signed->certificate->issuer,
                'certificate_fingerprint' => $signed->certificate->fingerprint,
                'certificate_valid_from' => $signed->certificate->validFrom,
                'certificate_valid_to' => $signed->certificate->validTo,
                'signed_at' => $signed->signedAt,
                'page' => (int) $placement['page'],
                'x' => (float) $placement['x'],
                'y' => (float) $placement['y'],
                'width' => (float) $placement['width'],
                'height' => (float) $placement['height'],
                'previous_sha256' => $locked->current_sha256,
                'signed_sha256' => $signed->sha256,
                'reason' => $reason,
                'location' => $location,
            ]);

            $signerSlot->forceFill(['signed_at' => $signed->signedAt])->save();

            $locked->forceFill([
                'current_path' => $newPath,
                'current_sha256' => $signed->sha256,
                'signature_count' => $sequence,
            ]);

            $isLast = $sequence >= $locked->signers_required;

            if ($isLast) {
                $locked->forceFill([
                    'status' => Document::STATUS_SEALED,
                    'sealed_at' => $signed->signedAt,
                    'sealed_by' => $user->id,
                ]);
            }

            $locked->save();

            DocumentEvent::record(
                $locked,
                $user,
                'signature_added',
                sprintf('%s firmó el documento (%d/%d).', $signerName, $sequence, $locked->signers_required),
                [
                    'sequence' => $sequence,
                    'field_name' => $signed->fieldName,
                    'page' => (int) $placement['page'],
                    'signed_sha256' => $signed->sha256,
                    'certificate_serial' => $signed->certificate->serial,
                ],
            );

            if ($isLast) {
                DocumentEvent::record(
                    $locked,
                    $user,
                    'document_sealed',
                    'El último firmante cerró el documento. Ya no admite más firmas.',
                );
            }

            return $signature->load(['user', 'document']);
        });
    }

    /**
     * Deletes a document that still has no signatures. Signed documents must
     * go through the consent workflow.
     */
    public function deleteUnsigned(Document $document, User $user): void
    {
        if ($document->signature_count > 0 || $document->signatures()->exists()) {
            throw DomainException::documentHasSignatures();
        }

        $disk = Storage::disk((string) config('firma.disk'));

        DB::transaction(function () use ($document, $user): void {
            DocumentEvent::record($document, $user, 'document_deleted', 'Documento eliminado por su creador.');

            $document->delete();
        });

        $this->deleteDirectory($document);
    }

    /** Removes the stored files of a deleted document. */
    public function deleteDirectory(Document $document): void
    {
        $disk = Storage::disk((string) config('firma.disk'));

        try {
            $disk->deleteDirectory($this->documentDirectory($document->uuid));
        } catch (Throwable $exception) {
            Log::warning('Could not delete document files', [
                'document' => $document->uuid,
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    public function documentPath(string $uuid, string $filename): string
    {
        return $this->documentDirectory($uuid).'/'.$filename;
    }

    private function documentDirectory(string $uuid): string
    {
        return trim((string) config('firma.documents_prefix', 'documents'), '/').'/'.$uuid;
    }
}
