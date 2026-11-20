<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Sales\Actions\QuoteWorkflow;
use App\Domain\Sales\Models\Document;
use App\Domain\Tenancy\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 2: an invoice or quote sent by WhatsApp. No WhatsApp account is needed: the API makes a link to the document
 * (an invoice link, 099, or a quote link, 065) and the message to go with it, and gives a WhatsApp link that opens the
 * chat with the message written (to the customer's number when it is given in international form, else to the chat the
 * sender picks). The sender presses send in WhatsApp. Each link made is audited, never its token.
 */
final class ShareController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit, private readonly QuoteWorkflow $workflow)
    {
    }

    public function invoice(Request $request, Document $invoice): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        abort_unless($invoice->type === 'invoice', 404);
        if (! in_array($invoice->status, ['posted', 'part_paid', 'paid'], true)) {
            throw ValidationException::withMessages(['invoice' => ['Only a posted invoice is shared: post it first.']]);
        }
        $token = bin2hex(random_bytes(32));
        $expires = now()->addDays(60);
        DB::select('SELECT invoice_link_issue(?, ?, ?)', [$invoice->getKey(), hash('sha256', $token), $expires->toIso8601String()]);
        $url = rtrim((string) config('security.frontend_url'), '/') . '/invoice?token=' . $token;
        $money = number_format((float) ($invoice->status === 'paid' ? $invoice->total : $invoice->amount_due), 2);
        $text = "{$this->company()}: invoice {$invoice->number}, " . ($invoice->status === 'paid' ? "{$invoice->currency} {$money}, paid. Thank you."
            : "{$invoice->currency} {$money} due" . ($invoice->due_date ? ' by ' . $invoice->due_date->format('j M Y') : '') . '.') . " See it and download the PDF: {$url}";

        return $this->shared($request, $invoice, 'invoice_shared', $url, $text, $expires->toIso8601String());
    }

    public function quote(Request $request, Document $quote): JsonResponse
    {
        $this->authorizeCompany('invoices.create');
        abort_unless($quote->type === 'quote', 404);
        if (! in_array($quote->status, ['draft', 'sent'], true)) {
            throw ValidationException::withMessages(['quote' => ['Only a quote waiting for an answer is shared.']]);
        }
        if ($quote->status === 'draft') {
            $quote = $this->workflow->send($quote, $request->user()->getKey());
        }
        $token = bin2hex(random_bytes(32));
        $company = $this->current->get();
        $until = $quote->due_date !== null ? \Illuminate\Support\Carbon::parse($quote->due_date->format('Y-m-d') . ' 23:59:59', $company->timezone ?: 'UTC') : now()->addDays(30);
        $expires = $until->max(now()->addDay())->min(now()->addDays(90));
        DB::select('SELECT quote_link_issue(?, ?, ?)', [$quote->getKey(), hash('sha256', $token), $expires->toIso8601String()]);   // replaces its unanswered links (065)
        $url = rtrim((string) config('security.frontend_url'), '/') . '/quote?token=' . $token;
        $text = "{$this->company()}: quote {$quote->number}, {$quote->currency} " . number_format((float) $quote->total, 2)
            . ($quote->due_date ? ', valid until ' . $quote->due_date->format('j M Y') : '') . ". See it and accept or decline it online: {$url}";

        return $this->shared($request, $quote, 'quote_shared', $url, $text, $expires->toIso8601String());
    }

    private function shared(Request $request, Document $doc, string $action, string $url, string $text, string $expires): JsonResponse
    {
        $phone = DB::table('contacts')->where(['company_id' => $this->current->id(), 'id' => $doc->contact_id])->value('phone');
        $to = self::international($phone);
        $this->audit->record($this->current->id(), $action, (string) $doc->type, $doc->getKey(), null,
            ['number' => $doc->number, 'via' => 'whatsapp', 'to' => $to, 'expires_at' => $expires], userId: $request->user()->getKey());

        return response()->json(['url' => $url, 'message' => $text, 'expires_at' => $expires, 'to' => $to,
            'whatsapp_url' => 'https://wa.me/' . ($to ?? '') . '?text=' . rawurlencode($text), 'status' => $doc->refresh()->status]);
    }

    /** A number in international form (+251 911 …) as WhatsApp's link takes it (digits only); else none, and the sender picks the chat. */
    public static function international(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if (! str_starts_with($phone, '+')) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }

    private function company(): string
    {
        $c = $this->current->get();

        return (string) ($c->trading_name ?: $c->legal_name);
    }
}
