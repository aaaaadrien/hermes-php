<?php
/**
 * Document generator
 * ========================================
 * Uses PHPOffice (phpword, phpspreadsheet, phppresentation) and mpdf
 * to generate DOCX, ODT, XLSX, ODS, PPTX, ODP, and PDF files
 * from Markdown content.
 *
 * Supported formats:
 *   - txt / md : text (no external dependency)
 *   - pdf      : mpdf
 *   - docx     : phpoffice/phpword (Writer\Word2007)
 *   - odt      : phpoffice/phpword (Writer\ODText)
 *   - xlsx     : phpoffice/phpspreadsheet (Writer\Xlsx)
 *   - ods      : phpoffice/phpspreadsheet (Writer\Ods)
 *   - pptx     : phpoffice/phppresentation (Writer\PowerPoint2007)
 *   - odp      : phpoffice/phppresentation (Writer\ODPresentation)
 *
 * The docx/odt, xlsx/ods, and pptx/odp pairs share the same
 * content model: only the writer (OOXML or OpenDocument) changes.
 */

declare(strict_types=1);

namespace Hermes;

use PhpOffice\PhpWord\IOFactory as PhpWordIO;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpSpreadsheet\IOFactory as PhpSpreadsheetIO;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpPresentation\DocumentLayout;
use PhpOffice\PhpPresentation\IOFactory as PhpPresentationIO;
use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Style\Bullet;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Fill;
use Mpdf\Mpdf;

class DocumentGenerator
{
    /**
     * Generates a file in the specified format.
     * Returns the bytes of the file.
     */
    public static function generate(string $content, string $format): string
    {
        return match (strtolower(ltrim($format, '.'))) {
            'pdf'    => self::generatePdf($content),
            'docx'   => self::generateDocx($content),
            'odt'    => self::generateOdt($content),
            'xlsx'   => self::generateXlsx($content),
            'ods'    => self::generateOds($content),
            'pptx'   => self::generatePptx($content),
            'odp'    => self::generateOdp($content),
            default  => throw new \InvalidArgumentException("Format non supporté : $format"),
        };
    }


    private static function generatePdf(string $content): string
    {
        $html = self::markdownToHtml($content);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 15,
            'margin_bottom' => 15,
        ]);
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', 'S');
    }



    private static function generateDocx(string $content): string
    {
        return self::savePhpWord(self::buildPhpWord($content), 'Word2007', 'docx');
    }

    /** ODT: same document format as DOCX; OpenDocument Writer. */
    private static function generateOdt(string $content): string
    {
        return self::savePhpWord(self::buildPhpWord($content), 'ODText', 'odt');
    }

    /**
     * Builds the PhpWord document from the Markdown content
     * (shared by the DOCX and ODT exports).
     */
    private static function buildPhpWord(string $content): PhpWord
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection([
            'marginTop' => 720,
            'marginBottom' => 720,
            'marginLeft' => 720,
            'marginRight' => 720,
        ]);

        $lines = preg_split('/\r?\n/', $content);
        $inTable = false;
        $firstTableRow = true;

        foreach ($lines as $line) {
            $stripped = rtrim($line);

            if ($stripped === '') {
                if ($inTable) {
                    $inTable = false;
                    $firstTableRow = true;
                }
                $section->addText('');
                continue;
            }

            // Tabular data detection (tabs or pipes)
            $columns = self::analyzeLineText($stripped);
            if (count($columns) > 1) {
                if (!$inTable) {
                    $inTable = true;
                    $firstTableRow = true;
                    $table = $section->addTable([
                        'cellSpacing' => 0,
                        'borderSize' => 6,
                        'borderColor' => 'AAAAAA',
                    ]);
                    $table->addRow(); // PhpWord requires a row before adding cells
                    self::addDocxTableCells($table, $columns, true);
                    continue;
                }
                $table->addRow();
                self::addDocxTableCells($table, $columns, false);
                continue;
            } else {
                if ($inTable) {
                    $inTable = false;
                    $firstTableRow = true;
                }
            }

            // Style according to the markdown type
            $type = self::markdownType($stripped);
            self::addDocxParagraph($section, $stripped, $type);
        }

        return $phpWord;
    }

    /**
     * Saves the PhpWord document using the specified writer and returns
     * the file's bytes.
     */
    private static function savePhpWord(PhpWord $phpWord, string $writerName, string $ext): string
    {
        // Suppresses deprecation warnings internal to PhpWord (v1.4+)
        $niveau = error_reporting(error_reporting() & ~E_DEPRECATED);
        $tmp = sys_get_temp_dir() . '/hermes_' . $ext . '_' . uniqid() . '.' . $ext;
        PhpWordIO::createWriter($phpWord, $writerName)->save($tmp);
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        error_reporting($niveau);
        return $data;
    }

    /**
     * Adds the cells in a row of a DOCX table.
     */
    private static function addDocxTableCells($table, array $columns, bool $isHeader): void
    {
        foreach ($columns as $cell) {
            $table->addCell(1500)->addText(
                $cell['texte'],
                ['bold' => $isHeader, 'size' => 11, 'name' => 'Calibri']
            );
        }
    }

    /**
     * Adds a DOCX paragraph based on the Markdown type.
     */
    private static function addDocxParagraph($section, string $text, string $type): void
    {
        if ($type === 'titre1') {
            $section->addText(substr($text, 2), ['size' => 24, 'bold' => true, 'name' => 'Calibri']);
        } elseif ($type === 'titre2') {
            $section->addText(substr($text, 3), ['size' => 18, 'bold' => true, 'name' => 'Calibri']);
        } elseif ($type === 'titre3') {
            $section->addText(substr($text, 4), ['size' => 14, 'bold' => true, 'name' => 'Calibri']);
        } elseif ($type === 'liste') {
            $section->addText('• ' . substr($text, 2), ['size' => 11, 'name' => 'Calibri'], ['spaceBefore' => 60, 'spaceAfter' => 60]);
        } else {
            $section->addText($text, ['size' => 11, 'name' => 'Calibri'], ['spaceBefore' => 60, 'spaceAfter' => 60]);
        }
    }


    private static function generateXlsx(string $content): string
    {
        return self::saveSpreadsheet(self::buildSpreadsheet($content), 'Xlsx', 'xlsx');
    }

    /** ODS: same workbook template as XLSX, OpenDocument Writer. */
    private static function generateOds(string $content): string
    {
        return self::saveSpreadsheet(self::buildSpreadsheet($content), 'Ods', 'ods');
    }

    /**
     * Creates the PhpSpreadsheet workbook from the Markdown content
     * (shared by the XLSX and ODS exports).
     */
    private static function buildSpreadsheet(string $content): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Document');

        $maxCols = 1;
        $row = 1;
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $stripped = rtrim($line);

            // Ligne vide → saut de ligne
            if ($stripped === '') {
                $row++;
                continue;
            }

            // Tabular data detection: tabs or pipes
            $columns = self::analyzeLineText($stripped);
            $nbCols = count($columns);
            if ($nbCols > $maxCols) {
                $maxCols = $nbCols;
            }

            foreach ($columns as $colIdx => $valeur) {
                $colLet = self::numberToLetter($colIdx + 1);
                $cell = $colLet . $row;
                $sheet->setCellValue($cell, $valeur['texte']);

                // Styles according to the content type
                if ($valeur['type'] === 'titre1') {
                    $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(16);
                } elseif ($valeur['type'] === 'titre2') {
                    $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(14);
                } elseif ($valeur['type'] === 'titre3') {
                    $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(12);
                }
            }
            $row++;
        }

        // Automatic adjustment of column widths
        for ($c = 1; $c <= $maxCols; $c++) {
            $letter = self::numberToLetter($c);
            $sheet->getColumnDimension($letter)->setAutoSize(true);
            if ($sheet->getColumnDimension($letter)->getWidth() < 10) {
                $sheet->getColumnDimension($letter)->setWidth(10);
            }
        }

        return $spreadsheet;
    }

    /**
     * Saves the PhpSpreadsheet workbook using the specified writer and
     * returns the file's bytes.
     */
    private static function saveSpreadsheet(Spreadsheet $spreadsheet, string $writerName, string $ext): string
    {
        $tmp = sys_get_temp_dir() . '/hermes_' . $ext . '_' . uniqid() . '.' . $ext;
        PhpSpreadsheetIO::createWriter($spreadsheet, $writerName)->save($tmp);
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    /**
     * Generates a PPTX presentation from Markdown content.
     *
     * Markdown to slide mapping:
     *   - `# Title` (first)  → title slide (subtitle = the first
     *     normal line that follows);
     *   - `##` / `###`         → new slide with title;
     *   - `---`                → new slide without a title;
     *   - `- x` / `* x` / `• x` → bullet points, `1. x` → numbered items;
     *   - lines `| a | b |`   → table (separator lines are ignored);
     *   - plain text         → paragraphs.
     */
    private static function generatePptx(string $content): string
    {
        return self::savePresentation(self::buildPhpPresentation($content), 'PowerPoint2007', 'pptx');
    }

    /** ODP: same slide template as PPTX, OpenDocument Writer. */
    private static function generateOdp(string $content): string
    {
        return self::savePresentation(self::buildPhpPresentation($content), 'ODPresentation', 'odp');
    }

    /**
     * Generates the PhpPresentation presentation from the Markdown content
     * (shared by the PPTX and ODP exports).
     */
    private static function buildPhpPresentation(string $content): PhpPresentation
    {
        $slides = self::parsePptxSlides($content);

        $pres = new PhpPresentation();
        $pres->getLayout()->setDocumentLayout(DocumentLayout::LAYOUT_SCREEN_16X9);

        $premiere = true;
        foreach ($slides as $def) {
            $slide = $premiere ? $pres->getActiveSlide() : $pres->createSlide();
            $premiere = false;
            if ($def['estTitre']) {
                self::rendreSlideTitre($slide, $def);
            } else {
                self::rendreSlideContenu($slide, $def);
            }
        }

        return $pres;
    }

    /**
     * Saves the PhpPresentation presentation using the specified writer and
     * returns the file's bytes.
     */
    private static function savePresentation(PhpPresentation $pres, string $writerName, string $ext): string
    {
        $tmp = sys_get_temp_dir() . '/hermes_' . $ext . '_' . uniqid() . '.' . $ext;
        PhpPresentationIO::createWriter($pres, $writerName)->save($tmp);
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    /**
     * Removes inline Markdown formatting (bold, italics, code) from
     * text before inserting it into the presentation, PPTX does not support
     * Markdown tags.
     */
    private static function nettoyerMarkdownInline(string $texte): string
    {
        $texte = (string) preg_replace('/\*\*(.+?)\*\*/s', '$1', $texte);
        $texte = (string) preg_replace('/__(.+?)__/s', '$1', $texte);
        $texte = (string) preg_replace('/(?<![*\w])\*([^*\n]+)\*(?!\*)/s', '$1', $texte);
        $texte = (string) preg_replace('/`([^`\n]+)`/s', '$1', $texte);
        return $texte;
    }

    /**
     * Parses Markdown content and returns a list of slides:
     * list<array{titre:?string, estTitre:bool, items:list<array{type:string,texte:string}>, table:?list<list<string>>}>
     */
    private static function parsePptxSlides(string $content): array
    {
        $slides = [];
        $idx = -1;
        $titrePrincipalVu = false;
        $tableLignes = null;
        $tableIdx = -1;

        $fermerTable = function () use (&$slides, &$tableLignes, &$tableIdx) {
            if ($tableIdx >= 0) {
                $slides[$tableIdx]['table'] = $tableLignes;
            }
            $tableLignes = null;
            $tableIdx = -1;
        };

        $nouvelleSlide = function (string $titre, bool $estTitre) use (&$slides, &$idx, $fermerTable) {
            $fermerTable();
            $slides[] = ['titre' => $titre, 'estTitre' => $estTitre, 'items' => [], 'table' => null];
            $idx = count($slides) - 1;
        };

        foreach (preg_split('/\r?\n/', $content) as $line) {
            $stripped = rtrim($line);
            $partie = trim($stripped);
            if ($partie === '') {
                continue;
            }

            // Markdown table: | a | b | (separator lines |---| are ignored)
            if (str_starts_with($partie, '|')) {
                $parts = array_map('trim', explode('|', $partie));
                if ($parts !== [] && $parts[0] === '') {
                    array_shift($parts);
                }
                if ($parts !== [] && $parts[count($parts) - 1] === '') {
                    array_pop($parts);
                }
                $estSeparateur = $parts !== [] && count(array_filter(
                    $parts,
                    fn ($c) => $c !== '' && !preg_match('/^:?-{2,}:?$/', $c),
                )) === 0;
                if ($estSeparateur) {
                    continue;
                }
                if (count($parts) > 1) {
                    if ($tableLignes === null) {
                        if ($idx < 0) {
                            $slides[] = ['titre' => null, 'estTitre' => false, 'items' => [], 'table' => null];
                            $idx = count($slides) - 1;
                        }
                        $tableLignes = [];
                        $tableIdx = $idx;
                    }
                    $tableLignes[] = array_map([self::class, 'nettoyerMarkdownInline'], $parts);
                    continue;
                }
            }
            if ($tableLignes !== null) {
                $fermerTable();
            }

            // Titres
            $type = self::markdownType($stripped);
            if ($type === 'titre1' || $type === 'titre2' || $type === 'titre3') {
                $nb = substr_count(substr($stripped, 0, 4), '#');
                $titre = self::nettoyerMarkdownInline(trim(substr($stripped, $nb)));
                if ($type === 'titre1' && !$titrePrincipalVu) {
                    $titrePrincipalVu = true;
                    $nouvelleSlide($titre, true);
                } else {
                    $nouvelleSlide($titre, false);
                }
                continue;
            }

            // Horizontal line → new slide
            if (preg_match('/^-{3,}$|^\*{3,}$|^_{3,}$/', $partie)) {
                $nouvelleSlide('', false);
                continue;
            }

            // Content of the current slide
            if ($idx < 0) {
                $slides[] = ['titre' => null, 'estTitre' => false, 'items' => [], 'table' => null];
                $idx = count($slides) - 1;
            }
            if ($type === 'liste') {
                $slides[$idx]['items'][] = ['type' => 'puce', 'texte' => self::nettoyerMarkdownInline(substr($stripped, 2))];
            } elseif (preg_match('/^\s*\d+[.)]\s+/', $stripped)) {
                $slides[$idx]['items'][] = ['type' => 'num', 'texte' => self::nettoyerMarkdownInline(trim(preg_replace('/^\s*\d+[.)]\s+/', '', $stripped)))];
            } else {
                $slides[$idx]['items'][] = ['type' => 'texte', 'texte' => self::nettoyerMarkdownInline($stripped)];
            }
        }
        if ($tableLignes !== null) {
            $fermerTable();
        }

        // Removes completely empty slides (no title, content, or
        // table) — parsing artifacts (e.g. “---” before a “##”).
        $slides = array_values(array_filter(
            $slides,
            fn ($s) => $s['estTitre'] || $s['titre'] !== '' || $s['items'] !== [] || $s['table'] !== null,
        ));
        return $slides;
    }

    /**
     * Title slide: large centered title + optional subtitle.
     * Pixel dimensions (96 DPI) for a 16:9 screen (960 × 540);
     * the writer converts to EMU (1 px = 9525 EMU).
     */
    private static function rendreSlideTitre($slide, array $def): void
    {
        $items = $def['items'];
        $sousTitre = null;
        if ($items !== [] && $items[0]['type'] === 'texte') {
            $sousTitre = $items[0]['texte'];
            $items = array_slice($items, 1);
        }
        $titre = $def['titre'] !== null && $def['titre'] !== '' ? $def['titre'] : 'Présentation';

        self::ajouterTextBox($slide, 45, 160, 870, 120, [$titre], 34, true, Alignment::HORIZONTAL_CENTER);
        if ($sousTitre !== null) {
            self::ajouterTextBox($slide, 45, 300, 870, 65, [$sousTitre], 18, false, Alignment::HORIZONTAL_CENTER, 'FF595959');
        }
        if ($items !== []) {
            self::ajouterTextBox(
                $slide, 45, 385, 870, 130,
                array_map(fn ($i) => $i['texte'], $items),
                14, false, Alignment::HORIZONTAL_LEFT,
            );
        }
    }

    /**
     * Content slide: title, body (bulleted list / text), and any tables.
     * Pixel dimensions (96 DPI) for a 16:9 screen (960 × 540).
     */
    private static function rendreSlideContenu($slide, array $def): void
    {
        $titre = $def['titre'];
        $aTitre = $titre !== null && $titre !== '';
        $aTableau = $def['table'] !== null;
        $aTexte = $def['items'] !== [];

        if ($aTitre) {
            self::ajouterTextBox($slide, 45, 22, 870, 85, [$titre], 26, true, Alignment::HORIZONTAL_LEFT);
        }

        if ($aTexte) {
            $taille = $aTableau ? 14 : 16;
            $hauteur = $aTableau ? 120 : 390;
            self::ajouterTextBox($slide, 45, 125, 870, $hauteur, $def['items'], $taille, false, Alignment::HORIZONTAL_LEFT, null, true);
        }

        if ($aTableau) {
            $yTable = $aTexte ? 260 : 125;
            $hTable = $aTexte ? 255 : 390;
            self::ajouterTableau($slide, $def['table'], 45, $yTable, 870, $hTable);
        }
    }

    /**
     * Adds a text box to the slide.
     * @param list<string|array{type:string,texte:string}> $lignes
     */
    private static function ajouterTextBox(
        $slide,
        int $x,
        int $y,
        int $w,
        int $h,
        array $lignes,
        int $taille,
        bool $gras,
        string $alignement,
        ?string $couleur = null,
        bool $avecStylesLignes = false,
    ): void {
        $rt = $slide->createRichTextShape();
        $rt->setOffsetX($x);
        $rt->setOffsetY($y);
        $rt->setWidth($w);
        $rt->setHeight($h);

        foreach ($lignes as $ligne) {
            $type = $avecStylesLignes && is_array($ligne) ? $ligne['type'] : 'texte';
            $texte = is_array($ligne) ? $ligne['texte'] : (string) $ligne;

            $para = $rt->createParagraph();
            $para->setAlignment((new Alignment())->setHorizontal($alignement));
            if ($avecStylesLignes) {
                $bullet = new Bullet();
                if ($type === 'puce') {
                    $bullet->setBulletType(Bullet::TYPE_BULLET);
                    $bullet->setBulletChar('•');
                    $bullet->setBulletFont('Calibri');
                } elseif ($type === 'num') {
                    $bullet->setBulletType(Bullet::TYPE_NUMERIC);
                    $bullet->setBulletNumericStyle(Bullet::NUMERIC_DEFAULT);
                } else {
                    $bullet->setBulletType(Bullet::TYPE_NONE);
                }
                $para->setBulletStyle($bullet);
            }
            $run = $para->createTextRun($texte);
            $font = $run->getFont();
            $font->setName('Calibri');
            $font->setSize($taille);
            $font->setBold($gras);
            if ($couleur !== null) {
                $font->setColor(new Color($couleur));
            }
        }
    }

    /**
     * Adds a table to the slide (with the first row as the header).
     * @param list<list<string>> $lignes
     */
    private static function ajouterTableau($slide, array $lignes, int $x, int $y, int $w, int $h): void
    {
        if ($lignes === []) {
            return;
        }
        $nbCols = max(1, max(array_map('count', $lignes)));

        $largeurColonne = intdiv($w, $nbCols);
        // Comfortable line height; if the table exceeds the available
        // area, the lines are shrunk (minimum 24 px).
        $hauteurLigne = 40;
        if (count($lignes) * $hauteurLigne > $h) {
            $hauteurLigne = max(24, intdiv($h, count($lignes)));
        }

        $table = $slide->createTableShape($nbCols);
        $table->setOffsetX($x);
        $table->setOffsetY($y);
        $table->setWidth($w);
        $table->setHeight(count($lignes) * $hauteurLigne);

        foreach ($lignes as $numLigne => $ligne) {
            $row = $table->createRow();
            $row->setHeight($hauteurLigne);
            for ($col = 0; $col < $nbCols; $col++) {
                $cell = $row->getCell($col);
                $cell->setWidth($largeurColonne);
                $texte = $ligne[$col] ?? '';
                $para = $cell->createParagraph();
                $run = $para->createTextRun($texte);
                $font = $run->getFont();
                $font->setName('Calibri');
                $font->setSize(14);
                if ($numLigne === 0) {
                    $font->setBold(true);
                    $fill = new Fill();
                    $fill->setFillType(Fill::FILL_SOLID);
                    $fill->setStartColor(new Color('FFD9E2F3'));
                    $cell->setFill($fill);
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Parses a line and returns an array of cells [‘text’ => ..., ‘type’ => ...].
     * Detects delimiters: tab, pipe, or plain text in a single column.
     */
    private static function analyzeLineText(string $line): array
    {
        // Tabs → multi-column data
        if (str_contains($line, "\t")) {
            $parts = explode("\t", $line);
            $result = [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') {
                    $result[] = ['texte' => '', 'type' => 'normal'];
                    continue;
                }
                $result[] = ['texte' => $part, 'type' => self::markdownType($part)];
            }
            return $result;
        }

        // Pipes → multi-column data (except URLs)
        if (str_contains($line, '|') && !str_contains($line, '://')) {
            $parts = explode('|', $line);
            $result = [];
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') {
                    $result[] = ['texte' => '', 'type' => 'normal'];
                    continue;
                }
                $result[] = ['texte' => $part, 'type' => self::markdownType($part)];
            }
            return $result;
        }

        // Plain text → a single column
        return [['texte' => $line, 'type' => self::markdownType($line)]];
    }

    /**
     * Returns the Markdown type of a text: heading1, heading2, heading3, list, normal.
     */
    private static function markdownType(string $text): string
    {
        if (str_starts_with($text, '# ')) return 'titre1';
        if (str_starts_with($text, '## ')) return 'titre2';
        if (str_starts_with($text, '### ')) return 'titre3';
        if (str_starts_with($text, '- ') || str_starts_with($text, '* ') || str_starts_with($text, '• ')) return 'liste';
        return 'normal';
    }

    /**
     * Converts a column number (1-based) to an Excel letter (A, B, C, ...).
     */
    private static function numberToLetter(int $num): string
    {
        $letter = '';
        while ($num > 0) {
            $num--;
            $letter = chr(65 + ($num % 26)) . $letter;
            $num = intdiv($num, 26);
        }
        return $letter;
    }


    private static function markdownToHtml(string $md): string
    {
        $html = '<div style="font-family: Helvetica, Arial, sans-serif; font-size: 11pt; line-height: 1.5;">';
        $lines = preg_split('/\r?\n/', $md);
        $inTable = false;
        $firstTableRow = true;

        foreach ($lines as $line) {
            $stripped = rtrim($line);

            if ($stripped === '') {
                if ($inTable) {
                    $html .= '</table>';
                    $inTable = false;
                    $firstTableRow = true;
                }
                $html .= '<p>&nbsp;</p>';
                continue;
            }

            // Tabular data detection
            $columns = self::analyzeLineText($stripped);
            if (count($columns) > 1) {
                if (!$inTable) {
                    $inTable = true;
                    $firstTableRow = true;
                    $html .= '<table style="border-collapse: collapse; width: 100%; margin: 6pt 0;">';
                }
                $html .= '<tr>';
                foreach ($columns as $cell) {
                    if ($firstTableRow) {
                        $html .= '<th style="border: 1px solid #ccc; padding: 4px 8px; background: #f0f0f0; font-weight: bold;">'
                            . htmlspecialchars($cell['texte']) . '</th>';
                        $firstTableRow = false;
                    } else {
                        $html .= '<td style="border: 1px solid #ccc; padding: 4px 8px;">'
                            . htmlspecialchars($cell['texte']) . '</td>';
                    }
                }
                $html .= '</tr>';
                continue;
            } else {
                if ($inTable) {
                    $html .= '</table>';
                    $inTable = false;
                    $firstTableRow = true;
                }
            }

            // Style according to the markdown type
            $type = self::markdownType($stripped);
            if ($type === 'titre1') {
                $html .= '<h1 style="margin: 10pt 0 5pt 0; font-size: 16pt;">' . htmlspecialchars(substr($stripped, 2)) . '</h1>';
            } elseif ($type === 'titre2') {
                $html .= '<h2 style="margin: 8pt 0 4pt 0; font-size: 14pt;">' . htmlspecialchars(substr($stripped, 3)) . '</h2>';
            } elseif ($type === 'titre3') {
                $html .= '<h3 style="margin: 6pt 0 3pt 0; font-size: 12pt;">' . htmlspecialchars(substr($stripped, 4)) . '</h3>';
            } elseif ($type === 'liste') {
                $html .= '<p style="margin: 2pt 0; padding-left: 12pt;">• ' . htmlspecialchars(substr($stripped, 2)) . '</p>';
            } else {
                $html .= '<p style="margin: 3pt 0;">' . htmlspecialchars($stripped) . '</p>';
            }
        }

        if ($inTable) {
            $html .= '</table>';
        }

        $html .= '</div>';
        return $html;
    }
}
