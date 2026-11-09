<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpenApiTest extends TestCase
{
    public function test_the_openapi_document_is_served(): void
    {
        $response = $this->get('/api/v1/openapi.yaml');

        $response->assertOk();

        $content = (string) $response->getContent();

        $this->assertStringContainsString('openapi: 3.0.3', $content);
        $this->assertStringContainsString('/documents/{document}/signatures:', $content);
        $this->assertStringContainsString('application/vnd.api+json', $content);
    }

    public function test_the_documentation_viewer_is_served(): void
    {
        $this->get('/api/v1/docs')
            ->assertOk()
            ->assertSee('Firma Digital');
    }
}
