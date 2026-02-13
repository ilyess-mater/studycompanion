<?php

declare(strict_types=1);

namespace App\Service;

use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Smalot\PdfParser\Parser;

class LessonTextExtractor
{
    public function __construct(private readonly Parser $pdfParser)
    {
    }

    public function extract(string $absolutePath): string
    {
        $extension = strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'txt' => $this->extractTxt($absolutePath),
            'pdf' => $this->extractPdf($absolutePath),
            'docx' => $this->extractDocx($absolutePath),
            'pptx' => $this->extractPptx($absolutePath),
            default => throw new \InvalidArgumentException(sprintf('Unsupported file extension: %s', $extension)),
        };
    }

    private function extractTxt(string $absolutePath): string
    {
        $content = file_get_contents($absolutePath);

        return $content === false ? '' : trim($content);
    }

    private function extractPdf(string $absolutePath): string
    {
        $pdf = $this->pdfParser->parseFile($absolutePath);

        return trim($pdf->getText());
    }

    private function extractDocx(string $absolutePath): string
    {
        $phpWord = WordIOFactory::load($absolutePath);
        $lines = [];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text = trim((string) $element->getText());
                    if ($text !== '') {
                        $lines[] = $text;
                    }
                    continue;
                }

                if ($element instanceof TextRun) {
                    $runText = [];
                    foreach ($element->getElements() as $child) {
                        if (method_exists($child, 'getText')) {
                            $segment = trim((string) $child->getText());
                            if ($segment !== '') {
                                $runText[] = $segment;
                            }
                        }
                    }

                    if ($runText !== []) {
                        $lines[] = implode(' ', $runText);
                    }
                }
            }
        }

        return trim(implode("\n", $lines));
    }

    private function extractPptx(string $absolutePath): string
    {
        $presentation = PresentationIOFactory::load($absolutePath);
        $lines = [];

        foreach ($presentation->getAllSlides() as $slide) {
            foreach ($slide->getShapeCollection() as $shape) {
                if ($shape instanceof RichText) {
                    $text = trim($shape->getPlainText());
                    if ($text !== '') {
                        $lines[] = $text;
                    }
                }
            }
        }

        return trim(implode("\n", $lines));
    }
}
