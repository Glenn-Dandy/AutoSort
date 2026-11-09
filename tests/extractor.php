<?php
// Pass --slow to include the 60 second timeout
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../lib/Service/PdfTextExtractor.php';

use OCA\AutoSort\Service\PdfTextExtractor;

$dir = sys_get_temp_dir() . '/autosort-test-' . getmypid();
mkdir($dir);
$temps = [];

function program(string $name, string $body): string {
    global $dir;
    file_put_contents("$dir/$name", "#!/bin/sh\n$body\n");
    chmod("$dir/$name", 0755);
    return "$dir/$name";
}

function extractor(string $binary): PdfTextExtractor {
    $finder = new class($binary) implements OCP\IBinaryFinder {
        public function __construct(private string $binary) {
        }

        public function findBinaryPath(string $program) {
            return $this->binary;
        }
    };
    $temp = new class implements OCP\ITempManager {
        public function getTemporaryFile(string $suffix = '') {
            global $temps;
            return $temps[] = tempnam(sys_get_temp_dir(), 'autosort');
        }
    };
    return new PdfTextExtractor($finder, $temp);
}

function pdf(string $content = 'PDFDATA'): OCP\Files\File {
    return new class($content) implements OCP\Files\File {
        public function __construct(private string $content) {
        }

        public function fopen(string $mode) {
            $stream = fopen('php://memory', 'w+b');
            fwrite($stream, $this->content);
            rewind($stream);
            return $stream;
        }
    };
}

function errorOf(PdfTextExtractor $extractor, ?OCP\Files\File $file = null): ?string {
    try {
        $extractor->extract($file ?? pdf());
        return null;
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
}

function isRunning(string $program): bool {
    foreach (glob('/proc/[0-9]*/cmdline') as $path) {
        if (str_starts_with((string)@file_get_contents($path), $program . "\0")) {
            return true;
        }
    }
    return false;
}

function minimalPdf(string $page): string {
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($page) . " >>\nstream\n$page\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $out = "%PDF-1.4\n";
    foreach ($objects as $index => $object) {
        $out .= ($index + 1) . " 0 obj\n$object\nendobj\n";
    }
    return $out . "trailer\n<< /Size 6 /Root 1 0 R >>\n%%EOF\n";
}

$text = extractor(program('ok', "echo \"\$1 \$2 \$4\" > $dir/args; cat \"\$3\" > $dir/input; printf 'Hallo 123\\n\\f'"))->extract(pdf());
check('text', trim($text), 'Hallo 123');
check('form feed removed', str_contains($text, "\f"), false);
check('arguments', trim(file_get_contents("$dir/args")), '-enc UTF-8 -');
check('pdf handed over', file_get_contents("$dir/input"), 'PDFDATA');

check('no text layer counts as empty', trim(extractor(program('blank', "printf '\\f\\f\\f'"))->extract(pdf())), '');
check('invalid utf-8 scrubbed', mb_check_encoding(extractor(program('bytes', "printf 'a\\377b'"))->extract(pdf()), 'UTF-8'), true);
check('exit code', errorOf(extractor(program('bad', 'exit 3'))), 'pdftotext failed with exit code 3');
check('below the limit', strlen(extractor(program('large', "head -c 10000000 /dev/zero | tr '\\000' a"))->extract(pdf())), 10000000);

check('too much text', errorOf(extractor(program('endless', 'exec yes autosort-test'))), 'The PDF contains too much text');
usleep(200000);
check('endless program stopped', isRunning('yes'), false);

if (in_array('--slow', $argv, true)) {
    check('timeout', errorOf(extractor(program('slow', 'exec sleep 300'))), 'pdftotext took too long');
    usleep(200000);
    check('hanging program stopped', isRunning('sleep'), false);
}

$real = '/usr/bin/pdftotext';
if (is_executable($real)) {
    check('real pdf', str_contains(extractor($real)->extract(pdf(minimalPdf('BT /F1 14 Tf 72 720 Td (Rechnung vom 14.10.2026) Tj ET'))), 'Rechnung vom 14.10.2026'), true);
    check('real pdf without text', trim(extractor($real)->extract(pdf(minimalPdf('0 0 m')))), '');
    check('real damaged file', errorOf(extractor($real), pdf('not a pdf')), 'pdftotext failed with exit code 1');
} else {
    echo "skip real pdftotext is not installed\n";
}

check('temporary files removed', array_filter($temps, 'file_exists'), []);

array_map('unlink', glob("$dir/*"));
rmdir($dir);
finish();
