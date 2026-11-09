<?php

namespace Tests\Support;

use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\StandardFont;
use Dskripchenko\PhpPdf\Style\PaperSize;

final class SignatureFixtures
{
    /** @var array<string, string> */
    private static array $p12Cache = [];

    public static function pdf(int $pages = 2): string
    {
        $pdf = Document::new(PaperSize::A4);

        for ($index = 1; $index <= $pages; $index++) {
            $page = $pdf->addPage();
            $page->showText("Documento de prueba - pagina {$index}", 72, 760, StandardFont::HelveticaBold, 16);
            $page->showText('Contenido de ejemplo para validar la firma digital.', 72, 730, StandardFont::Helvetica, 11);
        }

        return $pdf->toBytes();
    }

    public static function png(): string
    {
        $image = imagecreatetruecolor(420, 140);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 20, 40, 110);
        imagerectangle($image, 2, 2, 417, 137, $ink);
        imagestring($image, 5, 120, 60, 'FIRMA DIGITAL', $ink);
        imagestring($image, 3, 160, 95, 'Prueba', $ink);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    public static function pkcs12(string $commonName = 'Ana Perez', string $password = 'secreto'): string
    {
        $cacheKey = $commonName.'|'.$password;

        if (isset(self::$p12Cache[$cacheKey])) {
            return self::$p12Cache[$cacheKey];
        }

        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $key = openssl_pkey_new($config);

        if ($key === false) {
            throw new \RuntimeException('No se pudo generar la clave de prueba');
        }

        $csr = openssl_csr_new([
            'commonName' => $commonName,
            'organizationName' => 'Firma Digital',
            'countryName' => 'ES',
        ], $key, $config);

        if ($csr === false) {
            throw new \RuntimeException('No se pudo generar la solicitud de certificado de prueba');
        }

        $certificate = openssl_csr_sign($csr, null, $key, 365, $config, 1);

        if ($certificate === false) {
            throw new \RuntimeException('No se pudo firmar el certificado de prueba');
        }

        $pkcs12 = '';

        if (! openssl_pkcs12_export($certificate, $pkcs12, $key, $password)) {
            throw new \RuntimeException('No se pudo exportar el PKCS#12 de prueba');
        }

        return self::$p12Cache[$cacheKey] = $pkcs12;
    }
}
