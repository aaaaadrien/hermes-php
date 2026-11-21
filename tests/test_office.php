<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

use Hermes\Office\PdfWriter;
use Hermes\Office\DocxWriter;
use Hermes\Office\XlsxWriter;
use Hermes\Tools;

$md = "# Rapport météo\n\n## Synthèse\n\nIl fait beau.\n\n### Détails\n\n- Température : 22°C\n- Vent : 10 km/h\n\n| Ville | T° | Ciel |\n|-------|-----|------|\n| Paris | 22 | Beau |\n| Lyon  | 18 | Nuageux |\n";

// --- XLSX ---
$xlsx = XlsxWriter::markdown($md);
file_put_contents('/tmp/opencode/test.xlsx', $xlsx);
$re = Hermes\Upload::extract($xlsx, 'test.xlsx');
echo "XLSX bytes: " . strlen($xlsx) . "\n";
echo "XLSX round-trip:\n" . $re['contenu'] . "\n\n";

// --- DOCX ---
$docx = DocxWriter::markdown($md);
file_put_contents('/tmp/opencode/test.docx', $docx);
echo "DOCX bytes: " . strlen($docx) . "\n";
$zip = new ZipArchive();
$ok = $zip->open('/tmp/opencode/test.docx') === true;
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->statIndex($i)['name']; }
echo "DOCX zip entries: " . implode(', ', $names) . "\n";
$doc = $zip->getFromName('word/document.xml');
echo "DOCX has Heading1: " . (str_contains($doc, 'Heading1') ? 'oui' : 'non') . "\n";
echo "DOCX has bullet: " . (str_contains($doc, '•') ? 'oui' : 'non') . "\n\n";

// --- PDF ---
$pdf = PdfWriter::markdown($md);
file_put_contents('/tmp/opencode/test.pdf', $pdf);
echo "PDF bytes: " . strlen($pdf) . "\n";
echo "PDF magic: " . substr($pdf, 0, 8) . "\n";
echo "PDF trailer: " . (str_contains($pdf, 'startxref') ? 'oui' : 'non') . "\n";
// Verification with pdftotext if available
if (Tools::binaryAvailable('pdftotext')) {
    $txt = (string) shell_exec('pdftotext -enc UTF-8 /tmp/opencode/test.pdf - 2>/dev/null');
    echo "PDF pdftotext extrait:\n" . $txt . "\n";
}
echo "\nOK\n";
