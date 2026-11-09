<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Document;
use App\Models\DocumentDeletionConsent;
use App\Models\DocumentDeletionRequest;
use App\Models\DocumentEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A signed document can only be deleted once every user who has signed it so
 * far approves the deletion. A single rejection cancels the request.
 */
final class DeletionWorkflowService
{
    public function __construct(private readonly DocumentWorkflowService $documents) {}

    public function request(Document $document, User $user, ?string $reason = null): DocumentDeletionRequest
    {
        return DB::transaction(function () use ($document, $user, $reason): DocumentDeletionRequest {
            /** @var Document $locked */
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if (! $locked->signatures()->exists()) {
                throw DomainException::noSignaturesToConsent();
            }

            $pending = $locked->deletionRequests()
                ->where('status', DocumentDeletionRequest::STATUS_PENDING)
                ->first();

            if ($pending !== null) {
                throw DomainException::deletionAlreadyPending([
                    'deletion_request_id' => $pending->id,
                ]);
            }

            $request = $locked->deletionRequests()->create([
                'requested_by' => $user->id,
                'status' => DocumentDeletionRequest::STATUS_PENDING,
                'reason' => $reason,
            ]);

            DocumentEvent::record(
                $locked,
                $user,
                'deletion_requested',
                'Solicitud de eliminación creada. Se requiere el consentimiento de todos los firmantes.',
                ['deletion_request_id' => $request->id, 'reason' => $reason],
            );

            // The requester's own consent counts when they are a signer.
            if ($this->hasSigned($locked, $user)) {
                $this->recordConsent($request, $user, DocumentDeletionConsent::DECISION_APPROVED, null);
            }

            return $this->evaluate($locked, $request, $user);
        });
    }

    public function consent(
        Document $document,
        DocumentDeletionRequest $request,
        User $user,
        string $decision,
        ?string $comment = null,
    ): DocumentDeletionRequest {
        return DB::transaction(function () use ($document, $request, $user, $decision, $comment): DocumentDeletionRequest {
            /** @var Document $locked */
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            /** @var DocumentDeletionRequest $lockedRequest */
            $lockedRequest = DocumentDeletionRequest::query()
                ->where('document_id', $locked->id)
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRequest->isPending()) {
                throw DomainException::deletionNotPending();
            }

            if (! $this->hasSigned($locked, $user)) {
                throw DomainException::notAConsentingSigner();
            }

            if ($lockedRequest->consents()->where('user_id', $user->id)->exists()) {
                throw new DomainException(
                    409,
                    'consent_already_recorded',
                    'Ya registraste tu decisión',
                    'Cada firmante puede responder una sola vez a esta solicitud.',
                );
            }

            $this->recordConsent($lockedRequest, $user, $decision, $comment);

            DocumentEvent::record(
                $locked,
                $user,
                'deletion_consent_recorded',
                $decision === DocumentDeletionConsent::DECISION_APPROVED
                    ? 'Un firmante aprobó la eliminación del documento.'
                    : 'Un firmante rechazó la eliminación del documento.',
                ['deletion_request_id' => $lockedRequest->id, 'decision' => $decision],
            );

            return $this->evaluate($locked, $lockedRequest, $user);
        });
    }

    private function evaluate(Document $document, DocumentDeletionRequest $request, User $actor): DocumentDeletionRequest
    {
        $consents = $request->consents()->get();
        $required = $this->requiredConsentUserIds($document);

        $rejected = $consents->firstWhere('decision', DocumentDeletionConsent::DECISION_REJECTED);

        if ($rejected !== null) {
            $request->forceFill([
                'status' => DocumentDeletionRequest::STATUS_REJECTED,
                'resolved_at' => now(),
            ])->save();

            DocumentEvent::record(
                $document,
                $actor,
                'deletion_rejected',
                'La eliminación fue rechazada. El documento se conserva.',
                ['deletion_request_id' => $request->id],
            );

            return $request->fresh(['consents.user', 'requestedBy']);
        }

        $approved = $consents
            ->where('decision', DocumentDeletionConsent::DECISION_APPROVED)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $allApproved = $required !== [] && array_diff($required, $approved) === [];

        if (! $allApproved) {
            return $request->fresh(['consents.user', 'requestedBy']);
        }

        $request->forceFill([
            'status' => DocumentDeletionRequest::STATUS_APPROVED,
            'resolved_at' => now(),
        ])->save();

        DocumentEvent::record(
            $document,
            $actor,
            'document_deleted',
            'Todos los firmantes aprobaron la eliminación. Documento eliminado.',
            ['deletion_request_id' => $request->id],
        );

        $document->delete();

        $this->documents->deleteDirectory($document);

        return $request->fresh(['consents.user', 'requestedBy']);
    }

    /** @return list<int> */
    public function requiredConsentUserIds(Document $document): array
    {
        return $document->signatures()
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function hasSigned(Document $document, User $user): bool
    {
        return $document->signatures()->where('user_id', $user->id)->exists();
    }

    private function recordConsent(
        DocumentDeletionRequest $request,
        User $user,
        string $decision,
        ?string $comment,
    ): DocumentDeletionConsent {
        return $request->consents()->create([
            'user_id' => $user->id,
            'decision' => $decision,
            'comment' => $comment,
            'decided_at' => now(),
        ]);
    }
}
