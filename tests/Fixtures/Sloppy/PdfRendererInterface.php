<?php

namespace App\Rendering;

interface PdfRendererInterface
{
    public function render(string $html): string;
}
