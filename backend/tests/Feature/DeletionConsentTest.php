<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentDeletionRequest;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DocumentFlow;
use Tests\TestCase;

class DeletionConsentTest extends TestCase
{
    use DocumentFlow, RefreshDatabase;

    /** @var FilesystemAdapter */
    private $disk;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake(config('firma.disk'));
        $this->disk = $disk;
    }

    /**
     * Creates a sealed document signed by both signers.
     *
     * @return array{owner: User, other: User, uuid: string}
     */
    private function signedDocument(): array
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $document = $this->createSharedDocument($owner, [$owner->email, $other->email]);
        $uuid = $document['id'];

        $this->signAs($owner, $uuid, 'Ana Perez')->assertCreated();
        $this->signAs($other, $uuid, 'Luis Gomez')->assertCreated();

        return ['owner' => $owner, 'other' => $other, 'uuid' => $uuid];
    }

    public function test_deletion_is_only_applied_when_every_signer_approves(): void
    {
        ['owner' => $owner, 'other' => $other, 'uuid' => $uuid] = $this->signedDocument();

        Sanctum::actingAs($owner);

        $request = $this->postJson("/api/v1/documents/{$uuid}/deletion-requests", [
            'reason' => 'Documento duplicado',
        ]);

        $request->assertCreated();
        $request->assertJsonPath('data.attributes.status', 'pending');
        $request->assertJsonPath('data.attributes.required_consents', 2);
        $request->assertJsonPath('data.attributes.approved_consents', 1);
        $request->assertJsonPath('data.attributes.pending_consents.0', $other->id);

        $requestId = (string) $request->json('data.id');

        // The signed document is still there.
        $this->getJson("/api/v1/documents/{$uuid}")->assertOk();

        Sanctum::actingAs($other);

        $approval = $this->postJson(
            "/api/v1/documents/{$uuid}/deletion-requests/{$requestId}/consents",
            ['decision' => 'approved'],
        );

        $approval->assertOk();
        $approval->assertJsonPath('meta.document_deleted', true);
        $approval->assertJsonPath('data.attributes.status', 'approved');

        $this->assertSoftDeleted('documents', ['uuid' => $uuid]);

        $record = DocumentDeletionRequest::query()->where('document_id', Document::withTrashed()->where('uuid', $uuid)->value('id'))->firstOrFail();
        $this->assertSame(DocumentDeletionRequest::STATUS_APPROVED, $record->status);
    }

    public function test_a_single_rejection_cancels_the_deletion(): void
    {
        ['owner' => $owner, 'other' => $other, 'uuid' => $uuid] = $this->signedDocument();

        Sanctum::actingAs($owner);

        $requestId = (string) $this->postJson("/api/v1/documents/{$uuid}/deletion-requests", [])
            ->assertCreated()
            ->json('data.id');

        Sanctum::actingAs($other);

        $this->postJson(
            "/api/v1/documents/{$uuid}/deletion-requests/{$requestId}/consents",
            ['decision' => 'rejected', 'comment' => 'Todavía lo necesito'],
        )
            ->assertOk()
            ->assertJsonPath('data.attributes.status', 'rejected')
            ->assertJsonPath('meta.document_deleted', false);

        $this->assertNotSoftDeleted('documents', ['uuid' => $uuid]);

        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/documents/{$uuid}")->assertOk();
    }

    public function test_only_signers_can_vote_on_the_deletion(): void
    {
        ['owner' => $owner, 'uuid' => $uuid] = $this->signedDocument();
        $outsider = User::factory()->create();

        Sanctum::actingAs($owner);
        $requestId = (string) $this->postJson("/api/v1/documents/{$uuid}/deletion-requests", [])
            ->assertCreated()
            ->json('data.id');

        Sanctum::actingAs($outsider);

        $this->postJson(
            "/api/v1/documents/{$uuid}/deletion-requests/{$requestId}/consents",
            ['decision' => 'approved'],
        )->assertStatus(403);
    }

    public function test_an_unsigned_document_uses_the_direct_delete_endpoint(): void
    {
        $owner = User::factory()->create();
        $document = $this->createSharedDocument($owner, [$owner->email]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/documents/{$document['id']}/deletion-requests", [])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'no_signatures_to_consent');
    }

    public function test_a_pending_request_is_not_duplicated(): void
    {
        ['owner' => $owner, 'uuid' => $uuid] = $this->signedDocument();

        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/documents/{$uuid}/deletion-requests", [])->assertCreated();

        $this->postJson("/api/v1/documents/{$uuid}/deletion-requests", [])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'deletion_already_pending');
    }
}
