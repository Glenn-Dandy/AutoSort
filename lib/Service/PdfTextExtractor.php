<?php

declare(strict_types=1);

namespace OCA\AutoSort\Service;

use OCP\Files\File;
use OCP\IBinaryFinder;
use OCP\ITempManager;
use RuntimeException;

class PdfTextExtractor {
    public function __construct(private IBinaryFinder $binaryFinder, private ITempManager $tempManager) {
    }

    public function isAvailable(): bool {
        return $this->binaryFinder->findBinaryPath('pdftotext') !== false;
    }

    public function extract(File $file): string {
        $binary = $this->binaryFinder->findBinaryPath('pdftotext');
        if ($binary === false) {
            throw new RuntimeException('pdftotext is not installed');
        }

        $tempFile = $this->tempManager->getTemporaryFile('.pdf');
        if ($tempFile === false) {
            throw new RuntimeException('Could not create a temporary file');
        }

        try {
            $source = $file->fopen('rb');
            $target = fopen($tempFile, 'wb');
            if ($source === false || $target === false) {
                throw new RuntimeException('Could not read the PDF');
            }
            stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);

            exec(escapeshellarg($binary) . ' -enc UTF-8 ' . escapeshellarg($tempFile) . ' - 2>/dev/null', $output, $exitCode);
            if ($exitCode !== 0) {
                throw new RuntimeException('pdftotext failed with exit code ' . $exitCode);
            }

            return mb_scrub(implode("\n", $output), 'UTF-8');
        } finally {
            @unlink($tempFile);
        }
    }
}
