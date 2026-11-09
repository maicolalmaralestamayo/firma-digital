<?php

namespace App\Support\Signing;

final readonly class PdfInfo
{
    /**
     * @param  list<array{page: int, width: float, height: float, media_width: float, media_height: float, rotation: int}>  $pages
     *         `width`/`height` are the displayed size in points (rotation applied);
     *         `media_width`/`media_height` are the raw MediaBox dimensions.
     */
    public function __construct(
        public int $pageCount,
        public string $sha256,
        public array $pages,
    ) {}

    /**
     * @return array{page: int, width: float, height: float, media_width: float, media_height: float, rotation: int}
     */
    public function page(int $number): array
    {
        return $this->pages[$number - 1];
    }
}
