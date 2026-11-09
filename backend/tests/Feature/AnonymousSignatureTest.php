<?php

namespace Tests\Feature;

use App\Services\SigningService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SignatureFixtures;
use Tests\TestCase;

class AnonymousSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const JSON_API = 'application/vnd.api+json';

    protected function setUp(): void
    {
        parent::setUp();

        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake(config('firma.disk'));
        $this->assertNotNull($disk);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'pdf' => UploadedFile::fake()->createWithContent('documento.pdf', SignatureFixtures::pdf()),
            'certificate' => UploadedFile::fake()->createWithContent('firma.p12', SignatureFixtures::pkcs12()),
            'password' => 'secreto',
            'image' => UploadedFile::fake()->createWithContent('firma.png', SignatureFixtures::png()),
            'page' => 1,
            'x' => 60,
            'y' => 640,
            'width' => 240,
            'height' => 80,
            'reason' => 'Aprobación de prueba',
        ], $overrides);
    }

    public function test_it_signs_a_pdf_and_returns_the_binary(): void
    {
        $response = $this->withHeader('Accept', self::JSON_API)->post('/api/v1/signatures', $this->payload());

        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $response->assertHeader('X-Signature-Sha256');

        $report = app(SigningService::class)->verify((string) $response->getContent());

        $this->assertTrue($report['integrity_ok']);
        $this->assertCount(1, $report['signatures']);
        $this->assertSame('FirmaDigital1', $report['signatures'][0]['field_name']);
        $this->assertTrue($report['signatures'][0]['intact']);
        $this->assertTrue($report['signatures'][0]['cryptographically_valid']);

        // The anonymous flow must not persist anything.
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_signatures', 0);
    }

    public function test_it_rejects_a_wrong_certificate_password(): void
    {
        $response = $this->withHeader('Accept', self::JSON_API)
            ->post('/api/v1/signatures', $this->payload(['password' => 'incorrecta']));

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.code', 'invalid_certificate');
        $this->assertStringStartsWith(
            self::JSON_API,
            (string) $response->headers->get('Content-Type'),
        );
    }

    public function test_it_rejects_a_file_that_is_not_a_pdf(): void
    {
        $response = $this->withHeader('Accept', self::JSON_API)->post('/api/v1/signatures', $this->payload([
            'pdf' => UploadedFile::fake()->createWithContent('falso.pdf', 'esto no es un pdf'),
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.code', 'invalid_pdf');
    }

    public function test_it_rejects_a_stamp_outside_the_page(): void
    {
        $response = $this->withHeader('Accept', self::JSON_API)->post('/api/v1/signatures', $this->payload([
            'x' => 2000,
            'width' => 500,
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('errors.0.code', 'invalid_placement');
    }

    public function test_it_requires_a_supported_accept_header(): void
    {
        $this->withHeader('Accept', 'text/html')
            ->post('/api/v1/signatures', $this->payload())
            ->assertStatus(406)
            ->assertJsonPath('errors.0.code', 'not_acceptable');
    }

    public function test_it_inspects_a_certificate(): void
    {
        $response = $this->post('/api/v1/certificates/inspect', [
            'certificate' => UploadedFile::fake()->createWithContent(
                'firma.p12',
                SignatureFixtures::pkcs12('Maria Gomez'),
            ),
            'password' => 'secreto',
        ], ['Accept' => self::JSON_API]);

        $response->assertOk();
        $response->assertJsonPath('data.type', 'certificates');
        $response->assertJsonPath('data.attributes.common_name', 'Maria Gomez');
        $this->assertNotEmpty($response->json('data.attributes.serial'));
        $this->assertNotEmpty($response->json('data.attributes.fingerprint_sha256'));
    }

    public function test_it_reports_an_invalid_certificate_password_when_inspecting(): void
    {
        $this->post('/api/v1/certificates/inspect', [
            'certificate' => UploadedFile::fake()->createWithContent(
                'firma.p12',
                SignatureFixtures::pkcs12(),
            ),
            'password' => 'otra',
        ], ['Accept' => self::JSON_API])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'invalid_certificate');
    }
}
