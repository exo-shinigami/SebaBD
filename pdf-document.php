<?php
/**
 * SebaBD — document → PDF.
 *
 * The paperwork pages (invoice.php, quotation.php, purchase-order.php,
 * order-detail.php) print through partials/document.php. This file renders the
 * same three pieces of paperwork — plus the customer's order summary — as a
 * real PDF file, so "Download" gives a document that can be filed or emailed
 * rather than a browser print dialog.
 *
 * Rendering is done with FPDF (vendor/fpdf, bundled), whose core fonts are
 * WinAnsi: text is transliterated on the way in, and the taka sign becomes
 * "Tk " because the built-in fonts have no ৳ glyph.
 *
 * A caller builds one normalised array and hands it over — see
 * document_pdf_build() in download.php for how each document is filled in:
 *
 *   kind           'Invoice'                  the big word top right
 *   number_label   'Invoice no.'   number     '5003'
 *   date_label     'Invoice date'  date       '12 Jan 2026' (pre-formatted)
 *   status         'Paid'                     small badge beside the number
 *   party_label    'Billed to'     party_lines [] address lines
 *   meta_rows      [['label'=>.., 'value'=>..]]      label/value lines
 *   columns        [['label'=>'Qty','weight'=>2,'align'=>'R']]
 *   rows           [['cells'=>[...] ]]        one entry per line item
 *   sum_row        ['label'=>'Subtotal','value'=>'Tk 12,000.00'] or null
 *   total_label    'Invoice total'   total     'Tk 12,000.00'
 *   amount_words   'Taka twelve thousand only.'
 *   balances       [['label'=>'Amount paid','value'=>'Tk 5,000.00']]
 *   tables         [['title'=>.., 'columns'=>[..], 'rows'=>[[..]]]]
 *   notes          small print under the totals
 *   signatures     ['For SebaBD', 'Supplier acknowledgement'] or null
 *   title          PDF title (also the first line of the file's metadata)
 *   filename       suggested download name, e.g. sebabd-invoice-5003.pdf
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/fpdf/fpdf.php';

const PDF_MARGIN       = 15.0;   // left/right/top margin, mm
const PDF_BOTTOM       = 24.0;   // space kept for the footer, mm
const PDF_CELL_PAD     = 1.8;    // horizontal padding inside a table cell, mm
const PDF_LINE_HEIGHT  = 4.1;    // line height for 8.5pt body text, mm

/**
 * A string the bundled fonts can actually draw: UTF-8 in, WinAnsi out.
 *
 * The taka sign, em dashes and typographic quotes have no glyph in the core
 * fonts, so they are spelled out or folded to their ASCII equivalents before
 * the transliteration step instead of arriving as "?" on the page.
 */
function pdf_text(string $text): string
{
    // "৳ 12,000.00" (money()) becomes "Tk 12,000.00", not "Tk  12,000.00": the
    // sign and its separating space are replaced together.
    $text = str_replace(
        ['৳ ', '৳', "\u{2014}", "\u{2013}", "\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}", '…'],
        ['Tk ', 'Tk', '-', '-', '"', '"', "'", "'", '...'],
        $text
    );

    $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

    if ($converted === false) {
        // Last resort: drop anything the encoding cannot represent.
        $converted = @iconv('UTF-8', 'Windows-1252//IGNORE', $text);
    }

    return $converted === false ? preg_replace('/[^\x20-\x7E]/', ' ', $text) : $converted;
}

/**
 * Break text into lines that fit a width, measured with the font that is
 * selected when this is called.
 *
 * @return string[] at least one line (possibly empty)
 */
function pdf_wrap(FPDF $pdf, float $width, string $text): array
{
    $lines = [];

    // An explicit newline in the source text is a line break on the page: a
    // table cell can carry a product name and its sub-details that way.
    foreach (preg_split('/\R/u', (string) $text) ?: [''] as $paragraph) {
        $paragraph = trim((string) preg_replace('/\s+/u', ' ', $paragraph));
        if ($paragraph === '') {
            $lines[] = '';
            continue;
        }

        $line = '';
        foreach (explode(' ', $paragraph) as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($line === '' || $pdf->GetStringWidth($candidate) <= $width) {
                $line = $candidate;
                continue;
            }
            $lines[] = $line;
            $line    = $word;
        }
        $lines[] = $line;
    }

    return $lines ?: [''];
}

/**
 * The document PDF itself: A4 portrait, letterhead on every page, page numbers
 * in the footer so a filed printout can be reassembled.
 */
class SebaPdf extends FPDF
{
    /** @var array The payload, kept for the footer. */
    public $docData = [];
    /** @var string|null Text drawn along the bottom of every page. */
    public $footerNote = null;

    public function Footer(): void
    {
        $this->SetY(-16);
        $this->SetFont('helvetica', '', 7.5);
        $this->SetTextColor(120, 128, 136);

        $left = (string) ($this->footerNote ?? '');
        $this->Cell(120, 4, pdf_text($left), 0, 0, 'L');

        $this->Cell(0, 4, pdf_text('Page ' . $this->PageNo() . ' of {nb}'), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }
}

/**
 * Draw the items table header band and return the vertical position below it.
 *
 * @param array $columns [['label'=>..,'weight'=>..,'align'=>..]]
 * @param float[] $widths resolved column widths in mm
 */
function pdf_table_head(SebaPdf $pdf, array $columns, array $widths): float
{
    $pdf->SetFont('helvetica', 'B', 8.5);
    $pdf->SetFillColor(27, 36, 44);
    $pdf->SetTextColor(255, 255, 255);

    $y = $pdf->GetY();
    $x = PDF_MARGIN;
    foreach ($columns as $i => $column) {
        $align = ($column['align'] ?? 'L') === 'R' ? 'R' : 'L';
        $pdf->SetXY($x, $y);
        $pdf->Cell($widths[$i], 7, pdf_text((string) $column['label']), 0, 0, $align, true);
        $x += $widths[$i];
    }

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($y + 7);

    return $pdf->GetY();
}

/**
 * Resolve column widths from weights so every table spans the text width.
 *
 * @return float[]
 */
function pdf_column_widths(array $columns, float $total): array
{
    $weights = [];
    $sum     = 0.0;
    foreach ($columns as $column) {
        $weight    = max(0.4, (float) ($column['weight'] ?? 1));
        $weights[] = $weight;
        $sum      += $weight;
    }

    $widths = [];
    foreach ($weights as $weight) {
        $widths[] = round($total * $weight / $sum, 2);
    }

    return $widths;
}

/**
 * Draw one table: an optional title, a header band, the rows (wrapping long
 * cells and repeating the header on a new page when needed) and an optional
 * light summary row.
 *
 * @param array $rows  list of ['cells' => string[], 'strong' => int[]]
 */
function pdf_table(SebaPdf $pdf, array $columns, array $rows, ?array $sumRow = null, string $title = ''): void
{
    $pageWidth  = $pdf->GetPageWidth();
    $tableWidth = $pageWidth - 2 * PDF_MARGIN;
    $widths     = pdf_column_widths($columns, $tableWidth);
    $bottom     = $pdf->GetPageHeight() - PDF_BOTTOM;

    if ($title !== '') {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY(PDF_MARGIN, $pdf->GetY() + 1);
        $pdf->Cell($tableWidth, 6, pdf_text($title), 0, 1, 'L');
    }

    pdf_table_head($pdf, $columns, $widths);

    foreach ($rows as $row) {
        $cells = (array) ($row['cells'] ?? []);
        $b     = !empty($row['cellBold']);

        // Measure first: how tall is this row once every cell is wrapped?
        $pdf->SetFont('helvetica', '', 8.5);
        $cellLines = [];
        $maxLines  = 1;
        foreach ($columns as $i => $column) {
            $lines          = pdf_wrap($pdf, $widths[$i] - 2 * PDF_CELL_PAD, (string) ($cells[$i] ?? ''));
            $cellLines[$i]  = $lines;
            $maxLines       = max($maxLines, count($lines));
        }
        $rowHeight = $maxLines * PDF_LINE_HEIGHT + 2.6;

        if ($pdf->GetY() + $rowHeight > $bottom) {
            $pdf->AddPage();
            pdf_table_head($pdf, $columns, $widths);
        }

        $y = $pdf->GetY();
        $x = PDF_MARGIN;

        foreach ($columns as $i => $column) {
            $align = ($column['align'] ?? 'L') === 'R' ? 'R' : 'L';
            $pdf->SetFont('helvetica', $b ? 'B' : '', 8.5);
            $ly = $y + 1.3;
            foreach ($cellLines[$i] as $line) {
                $pdf->SetXY($x, $ly);
                $pdf->Cell($widths[$i], PDF_LINE_HEIGHT, pdf_text($line), 0, 0, $align);
                $ly += PDF_LINE_HEIGHT;
            }
            $x += $widths[$i];
        }

        $pdf->SetDrawColor(226, 232, 236);
        $pdf->Line(PDF_MARGIN, $y + $rowHeight, $pageWidth - PDF_MARGIN, $y + $rowHeight);
        $pdf->SetY($y + $rowHeight);
    }

    if ($sumRow !== null) {
        $pdf->SetFillColor(246, 248, 250);
        $pdf->SetFont('helvetica', 'B', 8.5);
        $x = PDF_MARGIN;
        foreach ($columns as $i => $column) {
            $align = ($column['align'] ?? 'L') === 'R' ? 'R' : 'L';
            $pdf->SetXY($x, $pdf->GetY());
            $pdf->Cell($widths[$i], 7, pdf_text((string) ($i === 0 ? ($sumRow['label'] ?? '') : ($i === count($columns) - 1 ? ($sumRow['value'] ?? '') : ''))), 0, 0, $align, true);
            $x += $widths[$i];
        }
        $pdf->SetY($pdf->GetY() + 7);
    }
}

/**
 * A label/value block, two pairs per row, wrapping the values.
 */
function pdf_meta_rows(SebaPdf $pdf, array $rows, float $labelWidth = 34.0): void
{
    $width = $pdf->GetPageWidth() - 2 * PDF_MARGIN;

    foreach ($rows as $row) {
        if (($row['label'] ?? '') === '' && ($row['value'] ?? '') === '') {
            continue;
        }
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetTextColor(102, 112, 125);
        $pdf->SetX(PDF_MARGIN);
        $pdf->Cell($labelWidth, 4.6, pdf_text((string) $row['label']), 0, 0, 'L');

        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(28, 36, 48);
        $lines = pdf_wrap($pdf, $width - $labelWidth, (string) $row['value']);
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $pdf->SetX(PDF_MARGIN + $labelWidth);
            }
            $pdf->Cell($width - $labelWidth, 4.6, pdf_text($line), 0, 1, 'L');
        }
    }

    $pdf->SetTextColor(0, 0, 0);
}

/**
 * Render one paperwork payload and send it to the browser as a download.
 */
function document_pdf_output(array $doc): void
{
    $company = company_details();

    $pdf = new SebaPdf('P', 'mm', 'A4');
    $pdf->docData    = $doc;
    $pdf->footerNote = $company['name'] . ' · ' . $company['web'] . ' · ' . $company['phone']
        . ' · generated ' . date('d M Y H:i');
    $pdf->SetTitle(pdf_text((string) ($doc['title'] ?? 'Document')));
    $pdf->SetAuthor(pdf_text($company['name']));
    $pdf->SetCreator(pdf_text($company['name'] . ' e-commerce document service'));
    $pdf->SetMargins(PDF_MARGIN, PDF_MARGIN, PDF_MARGIN);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $pageWidth  = $pdf->GetPageWidth();
    $tableWidth = $pageWidth - 2 * PDF_MARGIN;

    /* ------------------------------------------------------------ letterhead */
    $pdf->SetFont('helvetica', 'B', 15);
    $pdf->SetXY(PDF_MARGIN, PDF_MARGIN);
    $pdf->Cell($tableWidth * 0.6, 7, pdf_text($company['name']), 0, 1, 'L');

    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetTextColor(102, 112, 125);
    foreach ([
        $company['legal'],
        $company['address'],
        $company['phone'] . ' · ' . $company['email'],
        $company['web'] . ' · ' . $company['bin'],
    ] as $line) {
        $pdf->SetX(PDF_MARGIN);
        $pdf->Cell($tableWidth * 0.6, 4.2, pdf_text($line), 0, 1, 'L');
    }
    $pdf->SetTextColor(0, 0, 0);

    // Document kind and its identifiers, right aligned in the letterhead band.
    $identTop = PDF_MARGIN;
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->SetXY($pageWidth - PDF_MARGIN - $tableWidth * 0.37, $identTop);
    $pdf->Cell($tableWidth * 0.37, 8, pdf_text((string) ($doc['kind'] ?? 'Document')), 0, 1, 'R');

    $pdf->SetFont('helvetica', '', 9);
    $identRows = [
        [$doc['number_label'] ?? 'Reference', '#' . (string) ($doc['number'] ?? '')],
        [$doc['date_label'] ?? 'Date', (string) ($doc['date'] ?? '')],
    ];
    if (trim((string) ($doc['status'] ?? '')) !== '') {
        $identRows[] = ['Status', (string) $doc['status']];
    }
    foreach ($identRows as [$label, $value]) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(102, 112, 125);
        $pdf->SetX($pageWidth - PDF_MARGIN - $tableWidth * 0.37);
        $pdf->Cell($tableWidth * 0.22, 4.6, pdf_text($label), 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(28, 36, 48);
        $pdf->Cell($tableWidth * 0.15, 4.6, pdf_text($value), 0, 1, 'R');
    }
    $pdf->SetTextColor(0, 0, 0);

    $y = max($pdf->GetY(), $identTop + 30) + 2;
    $pdf->SetDrawColor(28, 36, 48);
    $pdf->Line(PDF_MARGIN, $y, $pageWidth - PDF_MARGIN, $y);
    $pdf->SetY($y + 4);

    /* ----------------------------------------------------------- party block */
    $partyLines = array_values(array_filter((array) ($doc['party_lines'] ?? []), fn($line) => trim((string) $line) !== ''));
    if ($partyLines) {
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetTextColor(102, 112, 125);
        $pdf->SetX(PDF_MARGIN);
        $pdf->Cell($tableWidth, 4.4, pdf_text(mb_strtoupper((string) ($doc['party_label'] ?? 'Party'))), 0, 1, 'L');

        $pdf->SetTextColor(28, 36, 48);
        $pdf->SetFont('helvetica', '', 9);
        foreach ($partyLines as $line) {
            foreach (pdf_wrap($pdf, $tableWidth * 0.6, (string) $line) as $wrapped) {
                $pdf->SetX(PDF_MARGIN);
                $pdf->Cell($tableWidth * 0.6, 4.6, pdf_text($wrapped), 0, 1, 'L');
            }
        }
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetY($pdf->GetY() + 2);
    }

    /* ------------------------------------------------------------ meta rows */
    pdf_meta_rows($pdf, (array) ($doc['meta_rows'] ?? []));
    $pdf->SetY($pdf->GetY() + 3);

    /* --------------------------------------------------------- items table */
    $rows = [];
    foreach ((array) ($doc['rows'] ?? []) as $row) {
        $rows[] = ['cells' => (array) $row];
    }
    pdf_table($pdf, (array) $doc['columns'], $rows, $doc['sum_row'] ?? null);

    /* -------------------------------------------------------------- totals */
    $pdf->SetY($pdf->GetY() + 3);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(102, 112, 125);
    $pdf->SetX(PDF_MARGIN);
    $pdf->Cell($tableWidth * 0.6, 6, pdf_text((string) ($doc['total_label'] ?? 'Total')), 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->SetTextColor(28, 36, 48);
    $pdf->Cell($tableWidth * 0.4, 6, pdf_text((string) ($doc['total'] ?? '')), 0, 1, 'R');

    if (trim((string) ($doc['amount_words'] ?? '')) !== '') {
        $pdf->SetFont('helvetica', 'I', 8.5);
        $pdf->SetTextColor(102, 112, 125);
        foreach (pdf_wrap($pdf, $tableWidth, (string) $doc['amount_words']) as $line) {
            $pdf->SetX(PDF_MARGIN);
            $pdf->Cell($tableWidth, 4.4, pdf_text($line), 0, 1, 'R');
        }
    }
    $pdf->SetTextColor(0, 0, 0);

    /* ---------------------------------------------- balances / extra tables */
    $balances = (array) ($doc['balances'] ?? []);
    if ($balances) {
        $pdf->SetY($pdf->GetY() + 3);
        $columnWidth = $tableWidth / max(1, count($balances));
        $y = $pdf->GetY();
        foreach ($balances as $index => $balance) {
            $x = PDF_MARGIN + $index * $columnWidth;
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetTextColor(102, 112, 125);
            $pdf->SetXY($x, $y);
            $pdf->Cell($columnWidth, 4.4, pdf_text((string) $balance['label']), 0, 2, 'L');
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetTextColor(28, 36, 48);
            $pdf->SetX($x);
            $pdf->Cell($columnWidth, 5.4, pdf_text((string) $balance['value']), 0, 2, 'L');
        }
        $pdf->SetTextColor(0, 0, 0);
    }

    foreach ((array) ($doc['tables'] ?? []) as $table) {
        $pdf->SetY($pdf->GetY() + 4);
        $rows = [];
        foreach ((array) ($table['rows'] ?? []) as $row) {
            $rows[] = ['cells' => (array) $row];
        }
        pdf_table($pdf, (array) $table['columns'], $rows, null, (string) ($table['title'] ?? ''));
    }

    /* ---------------------------------------------------------- notes / signs */
    $notes = trim((string) ($doc['notes'] ?? ''));
    if ($notes !== '') {
        $pdf->SetY($pdf->GetY() + 4);
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(102, 112, 125);
        foreach (pdf_wrap($pdf, $tableWidth, $notes) as $line) {
            $pdf->SetX(PDF_MARGIN);
            $pdf->Cell($tableWidth, 4.4, pdf_text($line), 0, 1, 'L');
        }
        $pdf->SetTextColor(0, 0, 0);
    }

    $signatures = array_values(array_filter((array) ($doc['signatures'] ?? []), fn($line) => trim((string) $line) !== ''));
    if ($signatures) {
        // Keep the signature band on one page: start a fresh one if it would
        // otherwise be split across the page break.
        if ($pdf->GetY() + 26 > $pdf->GetPageHeight() - PDF_BOTTOM) {
            $pdf->AddPage();
        }
        $pdf->SetY($pdf->GetY() + 14);
        $y = $pdf->GetY();
        $slots = count($signatures);
        $slotWidth = $tableWidth / $slots;
        foreach ($signatures as $index => $label) {
            $x = PDF_MARGIN + $index * $slotWidth;
            $pdf->SetDrawColor(28, 36, 48);
            $pdf->Line($x, $y, $x + $slotWidth - 20, $y);
            $pdf->SetFont('helvetica', '', 8.5);
            $pdf->SetXY($x, $y + 1);
            $pdf->Cell($slotWidth - 20, 4.6, pdf_text((string) $label), 0, 0, 'L');
        }
    }

    // Page count placeholder FPDF understands ("{nb}"), then the download.
    $pdf->AliasNbPages('{nb}');

    $filename = (string) ($doc['filename'] ?? 'sebabd-document.pdf');

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    header('Cache-Control: private, no-store');

    $pdf->Output('D', $filename);
}
