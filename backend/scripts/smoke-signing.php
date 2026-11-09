<?php

/**
 * End-to-end signing smoke test (development utility).
 *
 *   php scripts/smoke-signing.php
 *
 * Builds a two-page PDF, a self-signed .p12 and a PNG stamp, signs the PDF
 * twice (incremental signatures) and prints the verification report.
 */

use App\Services\SigningService;
use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Style\PaperSize;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$outputDirectory = sys_get_temp_dir().'/firma-smoke';
@mkdir($outputDirectory, 0775, true);

/* ------------------------------------------------------------------ PDF */
$pdf = Document::new(PaperSize::A4);
$first = $pdf->addPage();
$first->showText('CONTRATO DE PRUEBA', 72, 780, StandardFont::HelveticaBold, 18);
$first->showText('Documento de ejemplo para validar la firma digital.', 72, 750, StandardFont::Helvetica, 11);
$second = $pdf->addPage();
$second->showText('Anexo I', 72, 780, StandardFont::HelveticaBold, 14);
$second->showText('Condiciones adicionales del contrato.', 72, 750, StandardFont::Helvetica, 11);
$pdfBytes = $pdf->toBytes();

/* -------------------------------------------------------------- Stamp PNG */
$image = imagecreatetruecolor(480, 170);
imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
$ink = imagecolorallocate($image, 20, 40, 110);
imagerectangle($image, 4, 4, 475, 165, $ink);
imagestring($image, 5, 130, 55, 'FIRMA DE PRUEBA', $ink);
imagestring($image, 3, 150, 95, 'Ana Perez', $ink);
ob_start();
imagepng($image);
$pngBytes = (string) ob_get_clean();

/* ------------------------------------------------------- Self-signed P12 */
$config = [
    'digest_alg' => 'sha256',
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'config' => null,
];

$makeCertificate = static function (string $commonName) use ($config): array {
    $key = openssl_pkey_new($config);
    $csr = openssl_csr_new([
        'commonName' => $commonName,
        'organizationName' => 'Firma Digital',
        'countryName' => 'ES',
    ], $key, $config);
    $certificate = openssl_csr_sign($csr, null, $key, 365, $config, 1);
    $p12 = '';
    openssl_pkcs12_export($certificate, $p12, $key, 'secreto');

    return [$p12, $commonName];
};

$signing = $app->make(SigningService::class);

$placement = [
    'page' => 1,
    'x' => 60.0,
    'y' => 620.0,
    'width' => 260.0,
    'height' => 80.0,
];

[$p12, $name] = $makeCertificate('Ana Perez');

$firstSignature = $signing->sign(
    pdfBytes: $pdfBytes,
    pkcs12Bytes: $p12,
    password: 'secreto',
    imageBytes: $pngBytes,
    placement: $placement,
    signerName: $name,
    reason: 'Aprobación del contrato',
    location: 'Madrid',
    fieldName: 'FirmaDigital1',
    sequence: 1,
);

file_put_contents($outputDirectory.'/signed-once.pdf', $firstSignature->bytes);

[$p12Second, $secondName] = $makeCertificate('Luis Gomez');

$secondSignature = $signing->sign(
    pdfBytes: $firstSignature->bytes,
    pkcs12Bytes: $p12Second,
    password: 'secreto',
    imageBytes: $pngBytes,
    placement: ['page' => 2, 'x' => 60.0, 'y' => 400.0, 'width' => 260.0, 'height' => 80.0],
    signerName: $secondName,
    reason: 'Revisión del anexo',
    location: 'Barcelona',
    fieldName: 'FirmaDigital2',
    sequence: 2,
);

file_put_contents($outputDirectory.'/signed-twice.pdf', $secondSignature->bytes);

$report = $signing->verify($secondSignature->bytes);

echo "Archivos generados en {$outputDirectory}\n";
echo 'Firma 1: '.$firstSignature->sha256.' (campo '.$firstSignature->fieldName.')\n';
echo 'Firma 2: '.$secondSignature->sha256.' (campo '.$secondSignature->fieldName.")\n";
echo 'Integridad global: '.($report['integrity_ok'] ? 'OK' : 'FALLÓ')."\n";

foreach ($report['signatures'] as $signature) {
    echo sprintf(
        " - %s | firmante: %s | intacta: %s | criptografía: %s | errores: %s\n",
        $signature['field_name'],
        $signature['signer'] ?? 'n/d',
        $signature['intact'] ? 'sí' : 'no',
        $signature['cryptographically_valid'] ? 'sí' : 'no',
        implode('; ', $signature['errors']) ?: '-',
    );
}
