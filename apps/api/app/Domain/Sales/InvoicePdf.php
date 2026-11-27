<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Models\Company;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;

/**
 * Module 2: a posted invoice or credit note as a PDF file, to download or to attach to its email. The page is built
 * here from the company's own records, every value escaped, and rendered with nothing fetched from anywhere: no remote
 * files, no scripts, no PHP, fonts from the library only. It shows what the printed page shows: who it is from (name,
 * TIN, VAT number, address), the customer, the lines and totals, what is still due and the tax authority's number.
 */
final class InvoicePdf
{
    /** 100: whether the logo is drawn (images need PHP's GD); null asks the host. Set only by tests of the page itself. */
    public static ?bool $drawImages = null;

    public function render(Company $company, Document $invoice): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultFont('Helvetica');
        $options->setChroot([sys_get_temp_dir()]);   // nothing outside is ever read
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($company, $invoice), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function html(Company $company, Document $invoice): string
    {
        $invoice->loadMissing(['lines', 'contact:id,name,tax_id', 'einvoiceSubmissions']);
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $money = fn ($v) => number_format((float) $v, 2);
        $title = $invoice->type === 'credit_note' ? 'Credit note' : 'Invoice';
        $address = implode(', ', array_filter([$company->address_line1 ?? null, $company->address_line2 ?? null, $company->city ?? null]));
        $contactLine = implode(' · ', array_filter([$company->phone ?? null, $company->email ?? null]));
        $clearance = $invoice->einvoiceSubmissions->firstWhere('status', 'cleared')?->clearance_number;
        // 109: the term it is on, printed beside the due date as QuickBooks does (a term turned off still reads).
        $termName = $invoice->payment_terms_id ? \App\Domain\Sales\Models\PaymentTerm::query()->whereKey($invoice->payment_terms_id)->value('name') : null;
        $original = $invoice->original_document_id ? DB::table('documents')->where(['company_id' => $company->getKey(), 'id' => $invoice->original_document_id])->value('number') : null;
        // The company's invoice template: its accent colour (validated as #RRGGBB when saved), how to pay, a footer line.
        $template = \App\Http\Controllers\CompanySettingsController::template($company->settings['invoice_template'] ?? []);
        $accent = $template['accent'] ?? '#111111';
        // 100: the logo, from the company's own row, as a data: URI (nothing is fetched); only where images can be drawn.
        $logo = (self::$drawImages ?? extension_loaded('gd')) ? app(\App\Domain\Tenancy\CompanyLogo::class)->dataUri((string) $company->getKey()) : null;

        $rows = '';
        foreach ($invoice->lines as $l) {
            $rows .= '<tr><td>' . $e($l->description) . '</td><td class="n">' . $e(rtrim(rtrim((string) $l->quantity, '0'), '.')) . '</td><td class="n">'
                . $money($l->unit_price) . '</td><td class="n">' . $money($l->line_tax) . '</td><td class="n">' . $money($l->line_subtotal) . '</td></tr>';
        }
        $due = $invoice->type === 'invoice' && in_array($invoice->status, ['posted', 'part_paid', 'paid'], true)
            ? '<tr><td>Still due</td><td class="n">' . $e($invoice->currency) . ' ' . $money($invoice->amount_due) . '</td></tr>' : '';

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:Helvetica,sans-serif;font-size:10.5pt;color:#111}h1{font-size:18pt;margin:0 0 4pt}.muted{color:#555}'
            . 'table{width:100%;border-collapse:collapse;margin-top:12pt}th,td{padding:4pt 3pt;border-bottom:0.5pt solid #ccc;text-align:left}'
            . 'th{font-size:9pt;color:#555}.n{text-align:right}.totals{width:45%;margin-left:55%}.totals td{border:none}.total td{font-weight:bold;border-top:1pt solid ' . $accent . '}'
            . 'h1{color:' . $accent . '}thead th{border-bottom:1pt solid ' . $accent . '}.footer{margin-top:18pt;font-size:9pt;color:#555;text-align:center}'
            . '</style></head><body>'
            . '<table style="margin:0"><tr><td style="border:none;vertical-align:top">'
            . ($logo ? '<img src="' . $e($logo) . '" style="max-height:48pt;max-width:160pt;margin-bottom:4pt"><br>' : '')
            . '<h1>' . $e((string) ($company->trading_name ?: $company->legal_name)) . '</h1>'
            . ($company->trading_name ? '<div>' . $e($company->legal_name) . '</div>' : '')
            . ($company->tin ? '<div>TIN ' . $e($company->tin) . ($company->vat_number ? ' · VAT ' . $e($company->vat_number) : '') . '</div>' : '')
            . ($address !== '' ? '<div class="muted">' . $e($address) . '</div>' : '')
            . ($contactLine !== '' ? '<div class="muted">' . $e($contactLine) . '</div>' : '')
            . '</td><td style="border:none;vertical-align:top;text-align:right">'
            . '<h1>' . $title . ' ' . $e($invoice->number) . '</h1>'
            . '<div>Invoice date ' . $e((string) \App\Support\DateFormat::format($invoice->issue_date, $company)) . '</div>'
            // 109: the term it is on, as QuickBooks prints it beside the due date.
            . ($termName !== null ? '<div>Terms ' . $e($termName) . '</div>' : '')
            . ($invoice->due_date ? '<div>Due date ' . $e((string) \App\Support\DateFormat::format($invoice->due_date, $company)) . '</div>' : '')
            . ($original ? '<div>Corrects invoice ' . $e($original) . '</div>' : '')
            . '</td></tr></table>'
            . '<p><span class="muted">To</span><br><strong>' . $e($invoice->contact?->name) . '</strong>'
            . ($invoice->contact?->tax_id ? '<br>TIN ' . $e($invoice->contact->tax_id) : '') . '</p>'
            . '<table><thead><tr><th>Description</th><th class="n">Qty</th><th class="n">Unit price</th><th class="n">Tax</th><th class="n">Amount</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<table class="totals"><tr><td>Subtotal</td><td class="n">' . $money($invoice->subtotal) . '</td></tr>'
            // 110: an invoice out of scope of tax says so instead of a tax line.
            . ($invoice->out_of_scope ? '<tr><td>Out of scope of tax</td><td class="n"></td></tr>' : '<tr><td>Tax</td><td class="n">' . $money($invoice->tax_total) . '</td></tr>')
            . '<tr class="total"><td>Total</td><td class="n">' . $e($invoice->currency) . ' ' . $money($invoice->total) . '</td></tr>' . $due . '</table>'
            . ($clearance ? '<p>Registered with the tax authority: ' . $e((string) $clearance) . '</p>' : '')
            . ($invoice->notes ? '<p class="muted">' . nl2br($e($invoice->notes)) . '</p>' : '')
            . ($template['payment_details'] && $invoice->type === 'invoice' ? '<p><strong>How to pay</strong><br>' . nl2br($e($template['payment_details'])) . '</p>' : '')
            . ($template['footer'] ? '<p class="footer">' . $e($template['footer']) . '</p>' : '')
            . '</body></html>';
    }
}
