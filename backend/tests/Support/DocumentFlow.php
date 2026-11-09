<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

trait DocumentFlow
{
    /**
     * @param  list<string>  $emails
     * @return array<string, mixed>
     */
    protected function createSharedDocument(User $owner, array $emails, string $title = 'Contrato de servicios'): array
    {
        Sanctum::actingAs($owner);

        $response = $this->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->createWithContent('contrato.pdf', SignatureFixtures::pdf()),
            'title' => $title,
            'signers_required' => count($emails),
            'signers' => $emails,
            'reason' => 'Contrato de prueba',
        ], ['Accept' => 'application/vnd.api+json']);

        $response->assertCreated();

        return $response->json('data');
    }

    /** @return array<string, mixed> */
    protected function signaturePayload(string $commonName = 'Ana Perez'): array
    {
        return [
            'certificate' => UploadedFile::fake()->createWithContent(
                'firma.p12',
                SignatureFixtures::pkcs12($commonName),
            ),
            'password' => 'secreto',
            'image' => UploadedFile::fake()->createWithContent('firma.png', SignatureFixtures::png()),
            'page' => 1,
            'x' => 60,
            'y' => 600,
            'width' => 240,
            'height' => 80,
            'reason' => 'Aprobación',
        ];
    }

    protected function signAs(User $user, string $uuid, string $commonName = 'Ana Perez'): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->post(
            "/api/v1/documents/{$uuid}/signatures",
            $this->signaturePayload($commonName),
            ['Accept' => 'application/vnd.api+json'],
        );
    }
}
