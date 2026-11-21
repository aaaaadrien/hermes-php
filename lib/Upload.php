<?php
/**
 * File upload
 * ==================================
 * Analysis of the uploaded file and construction of the user message sent to the LLM.
 *
 * Types (like the Python version):
 *  - image: base64 encoding, OpenAI multimodal format;
 *  - audio: raw bytes preserved (transcription only if the LLM calls
 *            the outil_transcrire_audio tool);
 *  - text: extracted content injected into the message.
 *
 * Extraction:
 *  - PDF   : system binary pdftotext (poppler-utils);
 *  - CSV   : native parsing (fgetcsv);
 *  - XLSX  : PhpSpreadsheet; ODS: PhpSpreadsheet (Reader\Ods);
 *  - DOCX  : PhpWord; ODT: PhpWord (Reader\ODText, the reader name
 *            is required — IOFactory::load does not detect the extension);
 *  - PPTX/ODP: PhpPresentation (Reader\PowerPoint2007 / Reader\ODPresentation,
 *            auto-detected by IOFactory);
 *  - .xls / .ppt (legacy binaries): conversion message;
 *  - otherwise: raw UTF-8 read.
 */

declare(strict_types=1);

namespace Hermes;

class Upload
{
    public const TYPES_IMAGE = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp'];
    public const TEXT_TYPES = [
        '.txt', '.md', '.py', '.sh', '.conf', '.ini', '.log', '.yaml', '.yml',
        '.json', '.xml', '.html', '.css', '.js', '.ts', '.csv',
    ];
    public const TYPES_AUDIO = ['.wav', '.aiff', '.flac', '.ogg', '.mp3', '.m4a'];
    public const TYPES_VIDEO = ['.mp4', '.mkv', '.mov', '.avi'];
    public const TYPES_AUDIO_VIDEO = [
        '.wav', '.aiff', '.flac', '.ogg', '.mp3', '.m4a',
        '.mp4', '.mkv', '.mov', '.avi',
    ];

    /** Limit of characters injected into the context for text files. */
    public const CONTEXT_LIMIT = 65536;

    /**
     * Analyzes the uploaded content and returns:
     *   {
     *     "type":    "image" | "texte" | "audio",
     *     "nom":     str,
     *     "contenu": str|null,   # extracted text (null for audio/image)
     *     "b64":     str|null,   # base64 for images
     *     "mime":    str|null,   # MIME type for images/audio
     *     "donnees": str|null,   # raw binary content (audio/video only)
     *   }
     */
    public static function extract(string $content, string $name, string $mime = ''): array
    {
        $ext = strtolower('.' . pathinfo($name, PATHINFO_EXTENSION));

        // Images: base64 encoding for multimodal models
        if (in_array($mime, self::TYPES_IMAGE, true) || in_array($ext, ['.png', '.jpg', '.jpeg', '.gif', '.webp'], true)) {
            return [
                'type' => 'image', 'nom' => $name, 'contenu' => null,
                'b64' => base64_encode($content), 'mime' => $mime ?: 'application/octet-stream',
                'donnees' => null,
            ];
        }

        // Audio / video: raw bytes preserved
        if (in_array($ext, self::TYPES_AUDIO_VIDEO, true)
            || str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/')) {
            return [
                'type' => 'audio', 'nom' => $name, 'contenu' => null,
                'b64' => null, 'mime' => $mime ?: null, 'donnees' => $content,
            ];
        }

        // PDF
        if ($ext === '.pdf') {
            $text = self::extractPdf($content);
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }

        // CSV
        if ($ext === '.csv') {
            $text = self::extractCsv($content);
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }

        // Excel
        if (in_array($ext, ['.xlsx', '.xls'], true)) {
            $text = $ext === '.xlsx'
                ? self::extractXlsx($content)
                : "⚠️ Le format .xls (binaire legacy) n'est pas supporté par le lecteur maison. Convertissez en .xlsx.";
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }

        // ODS
        if ($ext === '.ods') {
            $text = self::extractOds($content);
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }

        // ODT
        if ($ext === '.odt') {
            $text = self::extractOdt($content);
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }

        // ODP
        if ($ext === '.odp') {
            $text = self::extractOdp($content);
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }

        // PPTX
        if ($ext === '.pptx') {
            $text = self::extractPptx($content);
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }

        // PPT (binaire legacy) : message explicite
        if ($ext === '.ppt') {
            $text = "⚠️ Le format .ppt (binaire legacy) n'est pas supporté par le lecteur. Convertissez en .pptx.";
            return ['type' => 'texte', 'nom' => $name, 'contenu' => $text, 'b64' => null, 'mime' => null, 'donnees' => null];
        }

        // DOCX
        if ($ext === '.docx') {
            $text = self::extractDocx($content);
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }

        // Plain text files (or fallback)
        $text = @iconv('UTF-8', 'UTF-8//IGNORE', $content);
        if ($text === false) {
            $text = '⚠️ Impossible de lire le fichier.';
        }
        return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
    }

    /**
     * Analyzes a file from its path without loading it all into memory.
     * Returns the same format as extract(), but uses streams.
     * For large files, this avoids memory exhaustion.
     */
    public static function extractFromFile(string $path, string $name, string $mime = ''): array
    {
        if (!is_file($path)) {
            return ['type' => 'texte', 'nom' => $name, 'contenu' => '⚠️ Fichier introuvable.', 'b64' => null, 'mime' => null, 'donnees' => null];
        }

        $ext = strtolower('.' . pathinfo($name, PATHINFO_EXTENSION));

        // Images: read + base64 (unavoidable for multimodal models)
        if (in_array($mime, self::TYPES_IMAGE, true) || in_array($ext, ['.png', '.jpg', '.jpeg', '.gif', '.webp'], true)) {
            $content = (string) file_get_contents($path);
            return [
                'type' => 'image', 'nom' => $name, 'contenu' => null,
                'b64' => base64_encode($content), 'mime' => $mime ?: 'application/octet-stream',
                'donnees' => null,
            ];
        }

        // Audio / video: the path is kept for deferred processing
        if (in_array($ext, self::TYPES_AUDIO_VIDEO, true)
            || str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/')) {
            return [
                'type' => 'audio', 'nom' => $name, 'contenu' => null,
                'b64' => null, 'mime' => $mime ?: null, 'donnees' => $path,
            ];
        }

        // PDF: pdftotext reads the file directly
        if ($ext === '.pdf') {
            if (!self::binaryAvailable('pdftotext')) {
                return ['type' => 'texte', 'nom' => $name, 'contenu' => "⚠️ pdftotext (poppler-utils) non installé. Impossible d'extraire le PDF.", 'b64' => null, 'mime' => null, 'donnees' => null];
            }
            $cmd = 'pdftotext -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null';
            $text = (string) shell_exec($cmd);
            if (trim($text) === '') {
                $text = '⚠️ Aucun texte extrait de ce document (fichier scanné ?).';
            }
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }

        // CSV: stream read
        if ($ext === '.csv') {
            $text = self::extractCsvFromFile($path);
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }

        // Excel (ZIP): the file is already on disk, the path is passed
        if (in_array($ext, ['.xlsx', '.xls'], true)) {
            $text = $ext === '.xlsx'
                ? self::extractXlsxFromFile($path)
                : "⚠️ Le format .xls (binaire legacy) n'est pas supporté par le lecteur maison. Convertissez en .xlsx.";
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }

        // ODS/ODT/ODP: stream read
        if ($ext === '.ods') {
            $text = self::extractOdsFromFile($path);
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }
        if ($ext === '.odt') {
            $text = self::extractOdtFromFile($path);
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }
        if ($ext === '.odp') {
            $text = self::extractOdpFromFile($path);
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }
        if ($ext === '.pptx') {
            $text = self::extractPptxFromFile($path);
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }
        if ($ext === '.ppt') {
            $text = "⚠️ Le format .ppt (binaire legacy) n'est pas supporté par le lecteur. Convertissez en .pptx.";
            return ['type' => 'texte', 'nom' => $name, 'contenu' => $text, 'b64' => null, 'mime' => null, 'donnees' => null];
        }
        if ($ext === '.docx') {
            $text = self::extractDocxFromFile($path);
            return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
        }

        // Plain text fallback: stream read
        $fp = fopen($path, 'r');
        if ($fp === false) {
            return ['type' => 'texte', 'nom' => $name, 'contenu' => '⚠️ Impossible de lire le fichier.', 'b64' => null, 'mime' => null, 'donnees' => null];
        }
        $text = '';
        while (!feof($fp)) {
            $row = fgets($fp, 65536);
            if ($row !== false) {
                $decode = @iconv('UTF-8', 'UTF-8//IGNORE', $row);
                $text .= $decode !== false ? $row : '';
            }
        }
        fclose($fp);
        return ['type' => 'texte', 'nom' => $name, 'contenu' => mb_substr($text, 0, self::CONTEXT_LIMIT, 'UTF-8'), 'b64' => null, 'mime' => null, 'donnees' => null];
    }

    /**
     * Builds the user message to send to the LLM according to the file type:
     *  - Image: OpenAI multimodal format (base64);
     *  - Audio: note indicating an attached file (the LLM must call
     *            "outil_transcrire_audio" if the dictated text is needed);
     *  - Text: injection into the text content.
     *
     * @return array<string,mixed>
     */
    public static function messageWithFile(array $fileInfo, string $prompt): array
    {
        if ($fileInfo['type'] === 'image') {
            return [
                'role' => 'user',
                'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$fileInfo['mime']};base64,{$fileInfo['b64']}"]],
                    ['type' => 'text', 'text' => $prompt],
                ],
            ];
        }
        if ($fileInfo['type'] === 'audio') {
            $injectedContent =
                "[Un fichier audio/vidéo est joint à ce message : `{$fileInfo['nom']}`. "
                . "Utilise l'outil de transcription (outil_transcrire_audio) pour en obtenir "
                . "le texte dicté si c'est nécessaire pour répondre à la question.]\n\n"
                . "Question : $prompt";
            return ['role' => 'user', 'content' => $injectedContent];
        }
        $injectedContent =
            "Voici le contenu du fichier `{$fileInfo['nom']}` :\n\n"
            . "```\n" . ($fileInfo['contenu'] ?? '') . "\n```\n\n"
            . "Question : $prompt";
        return ['role' => 'user', 'content' => $injectedContent];
    }

    // ------------------------------------------------------------------
    // Extractions
    // ------------------------------------------------------------------

    /** PDF via the system binary pdftotext (poppler-utils). */
    private static function extractPdf(string $content): string
    {
        if (!self::binaryAvailable('pdftotext')) {
            return "⚠️ pdftotext (poppler-utils) non installé. Impossible d'extraire le PDF.";
        }
        $tmp = tempnam('/var/tmp', 'hermes_pdf_');
        file_put_contents($tmp, $content);
        $cmd = 'pdftotext -enc UTF-8 ' . escapeshellarg($tmp) . ' - 2>/dev/null';
        $text = (string) shell_exec($cmd);
        @unlink($tmp);
        if (trim($text) === '') {
            return '⚠️ Aucune texte extrait de ce PDF (document scanné ?).';
        }
        return $text;
    }

    /** CSV: stream parsing (fgetcsv) then markdown table. */
    private static function extractCsvFromFile(string $path): string
    {
        try {
            $fp = fopen($path, 'r');
            if ($fp === false) {
                return '⚠️ Impossible d\'ouvrir le fichier CSV.';
            }
            // Delimiter detection on the first line
            $firstLine = fgets($fp, 65536) ?? '';
            $separator = str_contains($firstLine, "\t") ? "\t"
                : (substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',');
            rewind($fp);
            $rows = [];
            while (($row = fgetcsv($fp, 0, $separator, '"', '\\')) !== false) {
                $rows[] = $row;
            }
            fclose($fp);
            if ($rows === []) {
                return '⚠️ Le fichier CSV est vide.';
            }
            return self::markdownTable($rows);
        } catch (\Throwable $e) {
            return '⚠️ Erreur lors de la lecture CSV : ' . $e->getMessage();
        }
    }

    /** XLSX: zip on disk + in-house XML parsing. */
    private static function extractXlsxFromFile(string $path): string
    {
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                return '⚠️ Fichier XLSX corrompu ou illisible.';
            }

            $parts = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (is_array($stat) && !empty($stat['name'])) {
                    $parts[$stat['name']] = true;
                }
            }

            $sharedStrings = [];
            if (isset($parts['xl/sharedStrings.xml'])) {
                $xmlString = $zip->getFromName('xl/sharedStrings.xml');
                if ($xmlString !== false) {
                    $xml = simplexml_load_string($xmlString);
                    if ($xml !== false) {
                        foreach ($xml->si as $si) {
                            $sharedStrings[] = trim(self::textComplete($si));
                        }
                    }
                }
            }

            $sheet = 'xl/worksheets/sheet1.xml';
            if (!isset($parts[$sheet])) {
                $candidates = array_filter(array_keys($parts), fn ($p) => str_starts_with($p, 'xl/worksheets/sheet'));
                sort($candidates);
                if ($candidates === []) {
                    $zip->close();
                    return '⚠️ Aucune feuille trouvée dans le classeur XLSX.';
                }
                $sheet = $candidates[0];
            }
            $xmlString = $zip->getFromName($sheet);
            if ($xmlString === false) {
                $zip->close();
                return '⚠️ Impossible de lire la feuille XLSX.';
            }
            $xml = simplexml_load_string($xmlString);
            if ($xml === false) {
                $zip->close();
                return '⚠️ Impossible de lire la feuille XLSX.';
            }

            $rows = [];
            foreach ($xml->sheetData->row as $row) {
                $row = [];
                foreach ($row->c as $cell) {
                    $attributes = $cell->attributes();
                    $type = (string) ($attributes['t'] ?? 'n');
                    $value = (string) ($cell->v ?? '');
                    if ($type === 's' && $value !== '') {
                        $value = $sharedStrings[(int) $value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = (string) ($cell->is->t ?? '');
                    }
                    $row[] = $value;
                }
                $rows[] = $row;
            }
            $zip->close();
            if ($rows === []) {
                return '⚠️ La feuille XLSX semble vide.';
            }
            return self::markdownTable($rows);
        } catch (\Throwable $e) {
            return '⚠️ Erreur lors de la lecture XLSX : ' . $e->getMessage();
        }
    }

    /**
     * ODS from a file on disk: PhpSpreadsheet (Reader\Ods).
     * One markdown section per sheet (sheet name as title).
     */
    private static function extractOdsFromFile(string $path, bool $supprimer = false): string
    {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $parties = [];
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $rows = self::sheetRows($sheet);
                if ($rows !== []) {
                    $parties[] = self::finalRender($rows, $sheet->getTitle());
                }
            }
            return $parties !== [] ? implode("\n\n", $parties) : '⚠️ Le fichier ODS semble vide.';
        } catch (\Throwable $e) {
            return '⚠️ Erreur lors de la lecture ODS : ' . $e->getMessage();
        } finally {
            if ($supprimer) {
                @unlink($path);
            }
        }
    }

    /**
     * 2D array of text cells of a sheet (PhpSpreadsheet).
     * Empty rows and extreme empty columns are filtered.
     * The scan is bounded by getHighestDataColumn(): the ODS reader materializes
     * repeated cells (number-columns-repeated) and getHighestColumn()
     * would then return columns filled with empty values.
     */
    private static function sheetRows($sheet): array
    {
        $maxRow = $sheet->getHighestRow();
        $colonne = $sheet->getHighestDataColumn();
        if ($colonne === false || $colonne === null || $colonne === '') {
            $colonne = $sheet->getHighestColumn();
        }
        $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($colonne);

        $rows = [];
        for ($row = 1; $row <= $maxRow; $row++) {
            $ligne = [];
            for ($col = 1; $col <= $maxCol; $col++) {
                $addr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
                $valeur = $sheet->getCell($addr)->getValue();
                $ligne[] = $valeur !== null ? (string) $valeur : '';
            }
            $rows[] = $ligne;
        }

        // Entirely empty rows
        $rows = array_values(array_filter($rows, fn ($l) => count(array_filter($l, fn ($c) => trim((string) $c) !== '')) > 0));
        if ($rows === []) {
            return [];
        }

        // Extreme empty columns
        $nbCols = max(array_map('count', $rows));
        $premiere = 0;
        while ($premiere < $nbCols) {
            $vide = true;
            foreach ($rows as $l) {
                if (isset($l[$premiere]) && trim((string) $l[$premiere]) !== '') {
                    $vide = false;
                    break;
                }
            }
            if (!$vide) {
                break;
            }
            $premiere++;
        }
        $derniere = $nbCols - 1;
        while ($derniere >= $premiere) {
            $vide = true;
            foreach ($rows as $l) {
                if (isset($l[$derniere]) && trim((string) $l[$derniere]) !== '') {
                    $vide = false;
                    break;
                }
            }
            if (!$vide) {
                break;
            }
            $derniere--;
        }
        if ($premiere > $derniere) {
            return [];
        }
        return array_map(fn ($l) => array_slice(array_pad($l, $nbCols, ''), $premiere, $derniere - $premiere + 1), $rows);
    }

    /**
     * ODT from a file on disk: PhpWord.
     * NB: the “ODText” reader is required as the 2nd argument —
     * IOFactory::load() does not detect the extension and falls back to Word2007.
     */
    private static function extractOdtFromFile(string $path, bool $supprimer = false): string
    {
        try {
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($path, 'ODText');
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $text .= self::element_texte($element) . "\n";
                }
            }
            return trim($text) !== '' ? $text : '⚠️ Le document ODT semble vide.';
        } catch (\Throwable $e) {
            return '⚠️ Erreur lors de la lecture ODT : ' . $e->getMessage();
        } finally {
            if ($supprimer) {
                @unlink($path);
            }
        }
    }

    /**
     * ODP from a file on disk: PhpPresentation.
     * The ODP reader is auto-detected by IOFactory (canRead on the mimetype).
     */
    private static function extractOdpFromFile(string $path, bool $supprimer = false): string
    {
        return self::presentationTexte($path, $supprimer, 'ODP');
    }

    /**
     * Full text of an XML element, in document order, handling
     * mixed content (text + inline sub-elements like <text:span>). Goes
     * through the DOM, because a (string) cast of SimpleXMLElement only returns the
     * direct text nodes, not the descendants.
     */
    private static function textComplete(\SimpleXMLElement $element): string
    {
        $dom = dom_import_simplexml($element);
        if ($dom === false) {
            return '';
        }
        $root = $dom instanceof \DOMDocument ? $dom->documentElement : $dom;
        if ($root === null) {
            return '';
        }
        return self::textDom($root);
    }

    private static function textDom(\DOMNode $node): string
    {
        $output = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $output .= $child->nodeValue;
            } elseif ($child instanceof \DOMElement) {
                // ODF whitespace elements: translated to space/line
                $name = $child->localName ?? '';
                if ($name === 's') {
                    $output .= ' ';
                    continue;
                }
                if ($name === 'tab') {
                    $output .= "\t";
                    continue;
                }
                if ($name === 'line-break') {
                    $output .= "\n";
                    continue;
                }
                $output .= self::textDom($child);
            }
        }
        return $output;
    }

    // ------------------------------------------------------------------
    // Utilitaires
    // ------------------------------------------------------------------

    /** 2D array of cells → markdown table (pandas to_markdown style). */
    private static function markdownTable(array $rows, string $title = ''): string
    {
        // Filter entirely empty rows
        $rows = array_values(array_filter($rows, fn ($l) => is_array($l) && count(array_filter($l, fn ($cell) => trim((string) $cell) !== '')) > 0));
        if ($rows === []) {
            return '⚠️ Aucune donnée trouvée.';
        }

        // Detect a separator row (2 tables side by side) BEFORE any filtering
        $splitRow = null;
        foreach ($rows as $ri => $l) {
            $lineText = implode(' ', $l);
            if (stripos($lineText, 'recettes') !== false && stripos($lineText, 'dépenses') !== false) {
                $splitRow = $ri;
                break;
            }
        }
        if ($splitRow !== null) {
            // First part: from 0 to splitRow-1 (remove ANNEE/MOIS)
            $part1 = array_slice($rows, 0, $splitRow);
            $part1 = array_values(array_filter($part1, fn ($l) => !(count($l) >= 1 && stripos(trim($l[0] ?? ''), 'ANNEE') === 0)));
            $part1 = array_values(array_filter($part1, fn ($l) => !(count($l) >= 1 && stripos(trim($l[0] ?? ''), 'MOIS') === 0)));
            // Second part: from splitRow+1 (remove chronologique + ANNEE/MOIS)
            $part2 = array_slice($rows, $splitRow + 1);
            $part2 = array_values(array_filter($part2, fn ($l) => !(count($l) >= 1 && stripos(trim($l[0] ?? ''), 'ANNEE') === 0)));
            $part2 = array_values(array_filter($part2, fn ($l) => !(count($l) >= 1 && stripos(trim($l[0] ?? ''), 'MOIS') === 0)));
            $part2 = array_values(array_filter($part2, fn ($l) => !(count($l) >= 2 && stripos(implode(' ', $l), 'chronologique') !== false)));
            if ($part2 === []) {
                return self::finalRender($rows, $title);
            }
            // Fix the headers of part 2 (fournisseur instead of client)
            if (isset($part2[0]) && is_array($part2[0])) {
                for ($colIndex = 0; $colIndex < count($part2[0]); $colIndex++) {
                    if (trim($part2[0][$colIndex] ?? '') === 'Client') {
                        $part2[0][$colIndex] = 'Fournisseur';
                        break;
                    }
                }
            }
            // Split each part2 row by the largest empty zone (separator between 2 tables)
            $part2 = array_map([self::class, 'splitDoubleTableauLine'], $part2);
            $result1 = self::finalRender($part1, $title . ' (Recettes)');
            $result2 = self::finalRender($part2, $title . ' (Dépenses)');
            return $result1 . "\n\n" . $result2;
        }

        // No split: trim the empty columns (on this subset of rows)
        $numCols = 0;
        foreach ($rows as $l) {
            $numCols = max($numCols, count($l));
        }
        if ($numCols > 0) {
            $activeCols = [];
            for ($colIndex = 0; $colIndex < $numCols; $colIndex++) {
                $isActive = false;
                foreach ($rows as $l) {
                    if (isset($l[$colIndex]) && trim((string) $l[$colIndex]) !== '') {
                        $isActive = true;
                        break;
                    }
                }
                $activeCols[] = $isActive;
            }
            $firstActive = array_search(true, $activeCols, true);
            $lastActive = array_search(true, array_reverse($activeCols, true), true);
            if ($firstActive !== false && $lastActive !== false) {
                $lastActive = $numCols - 1 - $lastActive;
                if ($firstActive > 0 || $lastActive < $numCols - 1) {
                    $rows = array_map(fn ($l) => array_slice($l, $firstActive, $lastActive - $firstActive + 1), $rows);
                    $numCols = $lastActive - $firstActive + 1;
                }
            }
        }

        return self::finalRender($rows, $title);
    }

    /** End of the markdown rendering (title + table, escaped cells). */
    private static function finalRender(array $rows, string $title = ''): string
    {
        $numCols = 0;
        foreach ($rows as $l) {
            $numCols = max($numCols, count($l));
        }
        $formatter = fn ($cell) => str_replace(["\n", '|'], [' ', '\\|'], trim((string) $cell));
        $output = [];
        if ($title !== '') {
            $output[] = "**$title**";
        }
        foreach ($rows as $i => $l) {
            $l = array_slice(array_pad(array_values($l), $numCols, ''), 0, $numCols);
            $output[] = '| ' . implode(' | ', array_map($formatter, $l)) . ' |';
            if ($i === 0) {
                $output[] = '| ' . implode(' | ', array_fill(0, $numCols, '---')) . ' |';
            }
        }
        return implode("\n", $output);
    }

    /** Splits a double-column table row keeping only the right part (after the largest internal empty zone). */
    private static function splitDoubleTableauLine(array $row): array
    {
        // Ignore the empty cells at the beginning and end
        $firstActive = 0;
        while ($firstActive < count($row) && trim((string) $row[$firstActive]) === '') {
            $firstActive++;
        }
        $lastActive = count($row) - 1;
        while ($lastActive >= $firstActive && trim((string) $row[$lastActive]) === '') {
            $lastActive--;
        }
        // Find the longest empty sequence between $firstActive and $lastActive
        $maxGap = 0;
        $gapStart = -1;
        $currentGap = 0;
        $currentGapStart = -1;
        for ($i = $firstActive; $i <= $lastActive; $i++) {
            if (trim((string) $row[$i]) === '') {
                if ($currentGap === 0) {
                    $currentGapStart = $i;
                }
                $currentGap++;
            } else {
                if ($currentGap > $maxGap) {
                    $maxGap = $currentGap;
                    $gapStart = $currentGapStart;
                }
                $currentGap = 0;
                $currentGapStart = -1;
            }
        }
        if ($currentGap > $maxGap) {
            $maxGap = $currentGap;
            $gapStart = $currentGapStart;
        }
        // If a significant internal gap (> 0 cell) is found, keep the right part
        if ($maxGap >= 1 && $gapStart >= $firstActive) {
            return array_slice($row, $gapStart + 1);
        }
        return $row;
    }

    private static function binaryAvailable(string $name): bool
    {
        static $cache = [];
        if (!isset($cache[$name])) {
            $cache[$name] = (bool) @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $o, $rc);
        }
        return $cache[$name];
    }

    // ------------------------------------------------------------------
    // In-memory functions (called by extract())
    // ------------------------------------------------------------------

    /** CSV in memory: simple row/column split. */
    private static function extractCsv(string $content): string
    {
        $rows = explode("\n", $content);
        $result = [];
        foreach ($rows as $row) {
            $parts = str_getcsv(trim($row), ',', '"', '\\');
            if (count($parts) > 1 || (count($parts) === 1 && $parts[0] !== '')) {
                $result[] = implode("\t", $parts);
            }
        }
        return implode("\n", $result);
    }

    /** XLSX in memory: PhpSpreadsheet. */
    private static function extractXlsx(string $content): string
    {
        try {
            $tmp = tempnam('/var/tmp', 'hermes_xlsx_');
            file_put_contents($tmp, $content);
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
            $text = '';
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $maxRow = $sheet->getHighestRow();
                $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
                for ($row = 1; $row <= $maxRow; $row++) {
                    $cells = [];
                    for ($col = 1; $col <= $maxCol; $col++) {
                        $addr = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
                        $value = $sheet->getCell($addr)->getValue();
                        $cells[] = $value !== null ? (string) $value : '';
                    }
                    $text .= implode("\t", $cells) . "\n";
                }
                $text .= "\n";
            }
            @unlink($tmp);
            return trim($text) ?: '⚠️ Le fichier XLSX semble vide.';
        } catch (\Throwable $e) {
            return '⚠️ Erreur lecture XLSX : ' . $e->getMessage();
        }
    }

    /** ODS in memory: temporary file + PhpSpreadsheet. */
    private static function extractOds(string $content): string
    {
        $tmp = tempnam('/var/tmp', 'hermes_ods_');
        file_put_contents($tmp, $content);
        return self::extractOdsFromFile($tmp, true);
    }

    /** ODT in memory: temporary file + PhpWord (ODText reader). */
    private static function extractOdt(string $content): string
    {
        $tmp = tempnam('/var/tmp', 'hermes_odt_');
        file_put_contents($tmp, $content);
        return self::extractOdtFromFile($tmp, true);
    }

    /** ODP in memory: temporary file + PhpPresentation. */
    private static function extractOdp(string $content): string
    {
        $tmp = tempnam('/var/tmp', 'hermes_odp_');
        file_put_contents($tmp, $content);
        return self::extractOdpFromFile($tmp, true);
    }

    /** DOCX in memory: PhpOffice\PhpWord. */
    private static function extractDocx(string $content): string
    {
        try {
            $tmp = tempnam('/var/tmp', 'hermes_docx_');
            file_put_contents($tmp, $content);
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($tmp);
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $text .= self::element_texte($element) . "\n";
                }
            }
            @unlink($tmp);
            return trim($text) ?: '⚠️ Le document DOCX semble vide.';
        } catch (\Throwable $e) {
            return '⚠️ Erreur lecture DOCX : ' . $e->getMessage();
        }
    }

    /** DOCX from a file on disk. */
    private static function extractDocxFromFile(string $path): string
    {
        try {
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($path);
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $text .= self::element_texte($element) . "\n";
                }
            }
            return trim($text) ?: '⚠️ Le document DOCX semble vide.';
        } catch (\Throwable $e) {
            return '⚠️ Erreur lecture DOCX : ' . $e->getMessage();
        }
    }

    /** PPTX in memory: PhpOffice\PhpPresentation. */
    private static function extractPptx(string $content): string
    {
        $tmp = tempnam('/var/tmp', 'hermes_pptx_');
        file_put_contents($tmp, $content);
        return self::presentationTexte($tmp, true);
    }

    /** PPTX from a file on disk. */
    private static function extractPptxFromFile(string $path): string
    {
        return self::presentationTexte($path, false);
    }

    /**
     * Extracts the text of a presentation (PPTX or ODP), slide by
     * slide. Tables are rendered as tab-separated rows.
     */
    private static function presentationTexte(string $path, bool $supprimer, string $libelle = 'PPTX'): string
    {
        try {
            $presentation = \PhpOffice\PhpPresentation\IOFactory::load($path);
            $slides = $presentation->getAllSlides();
            if (count($slides) === 0) {
                return "⚠️ La présentation $libelle ne contient aucune diapositive.";
            }
            $parties = [];
            $num = 0;
            foreach ($slides as $slide) {
                $num++;
                $lignes = [];
                foreach ($slide->getShapeCollection() as $shape) {
                    if ($shape instanceof \PhpOffice\PhpPresentation\Shape\Table) {
                        foreach ($shape->getRows() as $row) {
                            $cells = [];
                            foreach ($row->getCells() as $cell) {
                                $cells[] = trim($cell->getPlainText());
                            }
                            $lignes[] = implode("\t", $cells);
                        }
                    } elseif (method_exists($shape, 'getPlainText')) {
                        $texte = trim($shape->getPlainText());
                        if ($texte !== '') {
                            $lignes[] = $texte;
                        }
                    }
                }
                if ($lignes !== []) {
                    $parties[] = "--- Diapositive $num ---\n" . implode("\n", $lignes);
                }
            }
            return $parties !== [] ? implode("\n\n", $parties) : "⚠️ La présentation $libelle semble vide.";
        } catch (\Throwable $e) {
            return "⚠️ Erreur lecture $libelle : " . $e->getMessage();
        } finally {
            if ($supprimer) {
                @unlink($path);
            }
        }
    }

    /** Extracts the text of a PhpWord element (paragraph, table, etc.). */
    private static function element_texte($element): string
    {
        if (method_exists($element, 'getText')) {
            return trim((string) $element->getText());
        }
        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $enfant) {
                $type = self::element_texte($enfant);
                if ($type !== '') {
                    $parts[] = $type;
                }
            }
            return implode(' ', $parts);
        }
        return '';
    }


}
