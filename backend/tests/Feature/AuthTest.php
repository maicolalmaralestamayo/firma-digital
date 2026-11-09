<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SignatureFixtures;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var FilesystemAdapter $disk */
        $disk = Storage::fake(config('firma.disk'));
        $this->assertNotNull($disk);
    }

    public function test_it_registers_a_user_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana Pérez',
            'email' => 'Ana@Example.com',
            'password' => 'contraseña123',
            'password_confirmation' => 'contraseña123',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.type', 'users');
        $response->assertJsonPath('data.attributes.email', 'ana@example.com');
        $response->assertJsonPath('meta.token_type', 'Bearer');

        $token = (string) $response->json('meta.token');
        $this->assertNotSame('', $token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.attributes.name', 'Ana Pérez');
    }

    public function test_it_reports_validation_errors_with_a_json_api_pointer(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'corta',
            'password_confirmation' => 'corta',
        ]);

        $response->assertStatus(422);
        $this->assertStringStartsWith(
            'application/vnd.api+json',
            (string) $response->headers->get('Content-Type'),
        );

        $pointers = array_map(
            static fn (array $error): ?string => $error['source']['pointer'] ?? null,
            $response->json('errors'),
        );

        $this->assertContains('/data/attributes/email', $pointers);
    }

    public function test_it_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'ana@example.com',
            'password' => 'contraseña123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@example.com',
            'password' => 'incorrecta',
        ])
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'invalid_credentials');
    }

    public function test_it_logs_in_and_revokes_the_token(): void
    {
        User::factory()->create([
            'email' => 'ana@example.com',
            'password' => 'contraseña123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'ana@example.com',
            'password' => 'contraseña123',
        ]);

        $response->assertOk();
        $token = (string) $response->json('meta.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        // The guard caches the resolved user inside a single test process.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_pending_invitations_are_claimed_on_registration(): void
    {
        $owner = User::factory()->create();

        $this->actingAsSanctum($owner);

        $this->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->createWithContent('contrato.pdf', SignatureFixtures::pdf()),
            'title' => 'Contrato pendiente',
            'signers_required' => 1,
            'signers' => ['nuevo@example.com'],
        ], ['Accept' => 'application/vnd.api+json'])->assertCreated();

        $this->app['auth']->forgetGuards();

        $registration = $this->postJson('/api/v1/auth/register', [
            'name' => 'Nuevo Firmante',
            'email' => 'nuevo@example.com',
            'password' => 'contraseña123',
            'password_confirmation' => 'contraseña123',
        ]);

        $registration->assertCreated();

        $this->assertSame(1, \App\Models\DocumentSigner::whereNotNull('user_id')->count());

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$registration->json('meta.token'))
            ->getJson('/api/v1/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    private function actingAsSanctum(User $user): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($user);
    }
}
