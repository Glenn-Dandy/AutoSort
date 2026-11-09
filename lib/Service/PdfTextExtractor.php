<?php

declare(strict_types=1);

namespace OCA\AutoSort\Service;

use OCP\Files\File;
use OCP\IBinaryFinder;
use OCP\ITempManager;
use RuntimeException;

class PdfTextExtractor {
    // A damaged or crafted PDF must neither block the job nor fill the memory
    private const TIMEOUT_SECONDS = 60;
    private const MAX_TEXT_BYTES = 10485760;
    private const SIGKILL = 9;

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

            // Every page ends with a form feed, which trim() would not treat as empty on a PDF without text
            return mb_scrub(str_replace("\f", "\n", $this->readText($binary, $tempFile)), 'UTF-8');
        } finally {
            @unlink($tempFile);
        }
    }

    private function readText(string $binary, string $path): string {
        $process = proc_open([$binary, '-enc', 'UTF-8', $path, '-'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('pdftotext could not be started');
        }

        $deadline = time() + self::TIMEOUT_SECONDS;
        $text = '';
        $failure = null;
        stream_set_blocking($pipes[1], false);
        while ($failure === null && !feof($pipes[1])) {
            $read = [$pipes[1]];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 1) > 0) {
                $text .= (string)fread($pipes[1], 65536);
            }
            if (strlen($text) > self::MAX_TEXT_BYTES) {
                $failure = 'The PDF contains too much text';
            } elseif (time() > $deadline) {
                $failure = 'pdftotext took too long';
            }
        }
        fclose($pipes[1]);

        if ($failure !== null) {
            proc_terminate($process, self::SIGKILL);
        }
        $exitCode = proc_close($process);
        if ($failure !== null) {
            throw new RuntimeException($failure);
        }
        if ($exitCode !== 0) {
            throw new RuntimeException('pdftotext failed with exit code ' . $exitCode);
        }
        return $text;
    }
}
