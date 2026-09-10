<?php

namespace App\Rendering;

class PdfRenderer implements PdfRendererInterface
{
    public function render(string $html): string
    {
        return base64_encode($html);
    }
}
