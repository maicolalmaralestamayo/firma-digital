<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\User;
use App\Services\SigningService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\DocumentFlow;
use Tests\Support\SignatureFixtures;
use Tests\TestCase;

class DocumentWorkflowTest extends TestCase
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

    public function test_the_creator_shares_the_document_with_the_requested_signers(): void
    {
        $owner = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $document = $this->createSharedDocument($owner, [$first->email, $second->email]);

        $this->assertSame('open', $document['attributes']['status']);
        $this->assertSame(2, $document['attributes']['signers_required']);
        $this->assertSame(2, $document['attributes']['page_count']);
        $this->assertSame(0, $document['attributes']['signature_count']);
        $this->assertDatabaseCount('document_signers', 2);
        $this->assertDatabaseCount('document_events', 1);

        Sanctum::actingAs($first);

        $this->getJson('/api/v1/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attributes.my_role', 'signer')
            ->assertJsonPath('data.0.attributes.permissions.can_sign', true);
    }

    public function test_it_requires_the_invited_signers_to_match_the_requested_count(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);

        $this->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->createWithContent('contrato.pdf', SignatureFixtures::pdf()),
            'title' => 'Contrato',
            'signers_required' => 2,
            'signers' => ['solo@example.com'],
        ], ['Accept' => 'application/vnd.api+json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'signers_count_mismatch');
    }

    public function test_signatures_are_incremental_and_the_last_signer_seals_the_document(): void
    {
        $owner = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $document = $this->createSharedDocument($owner, [$first->email, $second->email]);
        $uuid = $document['id'];

        $firstResponse = $this->signAs($first, $uuid, 'Ana Perez');

        $firstResponse->assertCreated();
        $firstResponse->assertJsonPath('meta.sealed', false);
        $firstResponse->assertJsonPath('meta.signature_count', 1);
        $firstResponse->assertJsonPath('meta.remaining_signatures', 1);
        $firstResponse->assertJsonPath('data.attributes.sequence', 1);

        $signature = DocumentSignature::query()->firstOrFail();
        $stored = $this->disk->get($signature->document->current_path);
        $report = app(SigningService::class)->verify($stored);

        $this->assertTrue($report['integrity_ok']);
        $this->assertCount(1, $report['signatures']);

        $secondResponse = $this->signAs($second, $uuid, 'Luis Gomez');

        $secondResponse->assertCreated();
        $secondResponse->assertJsonPath('meta.sealed', true);
        $secondResponse->assertJsonPath('meta.remaining_signatures', 0);

        $fresh = Document::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(Document::STATUS_SEALED, $fresh->status);
        $this->assertSame($second->id, $fresh->sealed_by);
        $this->assertSame(2, $fresh->signature_count);

        $final = $this->disk->get($fresh->current_path);
        $finalReport = app(SigningService::class)->verify($final);

        $this->assertTrue($finalReport['integrity_ok']);
        $this->assertCount(2, $finalReport['signatures']);
        $this->assertSame('FirmaDigital1', $finalReport['signatures'][0]['field_name']);
        $this->assertSame('FirmaDigital2', $finalReport['signatures'][1]['field_name']);

        // A sealed document refuses further signatures.
        $this->signAs($first, $uuid)
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'document_sealed');
    }

    public function test_only_invited_signers_can_sign(): void
    {
        $owner = User::factory()->create();
        $outsider = User::factory()->create();

        $document = $this->createSharedDocument($owner, [$owner->email]);

        $this->signAs($outsider, $document['id'])
            ->assertStatus(403)
            ->assertJsonPath('errors.0.code', 'not_a_signer');
    }

    public function test_a_signer_cannot_sign_twice(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();

        $document = $this->createSharedDocument($owner, [$signer->email, $owner->email]);

        $this->signAs($signer, $document['id'])->assertCreated();

        $this->signAs($signer, $document['id'])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'already_signed');
    }

    public function test_participants_can_download_and_sealed_documents_are_ready_to_print(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();

        $document = $this->createSharedDocument($owner, [$signer->email]);
        $uuid = $document['id'];

        $this->signAs($signer, $uuid)->assertCreated();

        Sanctum::actingAs($owner);

        $inline = $this->get("/api/v1/documents/{$uuid}/file");
        $inline->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $inline->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $inline->headers->get('Content-Disposition'));

        $download = $this->get("/api/v1/documents/{$uuid}/file?disposition=attachment");
        $download->assertOk();
        $this->assertStringContainsString(
            'attachment',
            (string) $download->headers->get('Content-Disposition'),
        );

        // Outsiders cannot read the file.
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);
        $this->get("/api/v1/documents/{$uuid}/file")->assertStatus(403);
    }

    public function test_an_unsigned_document_can_be_deleted_by_its_creator(): void
    {
        $owner = User::factory()->create();
        $document = $this->createSharedDocument($owner, [$owner->email]);

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/documents/{$document['id']}")->assertNoContent();

        $this->assertSoftDeleted('documents', ['uuid' => $document['id']]);
    }

    public function test_a_signed_document_cannot_be_deleted_without_consent(): void
    {
        $owner = User::factory()->create();
        $document = $this->createSharedDocument($owner, [$owner->email]);

        $this->signAs($owner, $document['id'])->assertCreated();

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/v1/documents/{$document['id']}")
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'document_has_signatures');
    }

    public function test_the_document_detail_exposes_signers_signatures_and_verification(): void
    {
        $owner = User::factory()->create();
        $signer = User::factory()->create();

        $document = $this->createSharedDocument($owner, [$signer->email]);
        $uuid = $document['id'];

        $this->signAs($signer, $uuid, 'Ana Perez')->assertCreated();

        Sanctum::actingAs($owner);

        $detail = $this->getJson("/api/v1/documents/{$uuid}?include=owner,signers,signatures,deletion_requests");
        $detail->assertOk();
        $detail->assertJsonPath('data.attributes.signature_count', 1);

        $types = array_column($detail->json('included'), 'type');
        $this->assertContains('users', $types);
        $this->assertContains('document-signers', $types);
        $this->assertContains('signatures', $types);

        $verification = $this->getJson("/api/v1/documents/{$uuid}/verification");
        $verification->assertOk();
        $verification->assertJsonPath('data.attributes.integrity_ok', true);
        $verification->assertJsonPath('data.attributes.signatures.0.field_name', 'FirmaDigital1');
        $verification->assertJsonPath('data.attributes.signatures.0.intact', true);
        $verification->assertJsonPath('data.attributes.signatures.0.cryptographically_valid', true);

        $events = $this->getJson("/api/v1/documents/{$uuid}/events");
        $events->assertOk();
        $this->assertGreaterThanOrEqual(2, count($events->json('data')));
    }

    public function test_documents_require_authentication(): void
    {
        $this->getJson('/api/v1/documents')
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'unauthenticated');
    }
}
