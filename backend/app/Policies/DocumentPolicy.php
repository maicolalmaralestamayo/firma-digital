<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /** Owner and invited signers can see the document. */
    public function view(User $user, Document $document): bool
    {
        return $document->hasParticipant($user);
    }

    /**
     * Only invited signers reach the workflow; sealing and double-signing are
     * answered by the service with a 409 so the client gets a precise reason.
     */
    public function sign(User $user, Document $document): bool
    {
        return $document->signerFor($user) !== null;
    }

    /** Participants can preview the PDF; the final version is readable once sealed. */
    public function download(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    /** Only the creator deletes; signed documents go through the consent flow. */
    public function delete(User $user, Document $document): bool
    {
        return $document->owner_id === $user->id;
    }

    /** Any participant may start the consent-based deletion workflow. */
    public function requestDeletion(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    /** Only users who already signed take part in the deletion consent. */
    public function consent(User $user, Document $document): bool
    {
        return $document->signatures()->where('user_id', $user->id)->exists();
    }
}
