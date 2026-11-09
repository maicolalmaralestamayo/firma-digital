<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * `?disposition=inline` (default) lets the browser preview and print the PDF;
 * `?disposition=attachment` downloads it. Only participants have access.
 */
class DocumentFileController extends Controller
{
    public function __invoke(Request $request, Document $document): Response
    {
        $this->authorize('download', $document);

        $disk = Storage::disk((string) config('firma.disk'));

        if (! $disk->exists($document->current_path)) {
            abort(404, 'El archivo del documento no está disponible.');
        }

        $bytes = (string) $disk->get($document->current_path);

        $disposition = $request->query('disposition') === 'attachment' ? 'attachment' : 'inline';
        $baseName = Str::slug($document->title) ?: 'documento';
        $filename = $baseName.($document->isSealed() ? '-firmado' : '-borrador').'.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
            'X-Document-Sha256' => $document->current_sha256,
            'X-Document-Pages' => (string) $document->page_count,
        ]);
    }
}
