<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

class OpenApiController extends Controller
{
    public function specification(): Response
    {
        $path = base_path('openapi/openapi.yaml');

        abort_unless(is_readable($path), 404, 'La especificación OpenAPI no está disponible.');

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public function documentation(): Response
    {
        return response()->view('docs');
    }
}
