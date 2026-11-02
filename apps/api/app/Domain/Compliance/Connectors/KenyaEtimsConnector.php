<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Connectors;

use App\Domain\Compliance\Contracts\EInvoiceConnector;
use App\Domain\Compliance\EInvoicePayload;
use App\Domain\Compliance\SubmissionResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * KRA eTIMS, system-to-system (OSCU / VSCU).
 *
 * TODO before go-live: implement the field mapping, signing and device initialisation exactly as the
 * current KRA eTIMS OSCU/VSCU technical specification describes, and pass KRA's certification tests in
 * their sandbox. Endpoints and field names are deliberately config-driven (config/einvoicing.php) and
 * NOT hard-coded here, because they must come from the official spec, not from memory.
 */
final class KenyaEtimsConnector implements EInvoiceConnector
{
    public function __construct(private readonly array $config)
    {
    }

    public function authority(): string
    {
        return 'KE_KRA';
    }

    public function submit(EInvoicePayload $payload, array $credentials): SubmissionResult
    {
        if (empty($this->config['base_url']) || empty($this->config['sales_endpoint'])) {
            return SubmissionResult::retryable('eTIMS connector is not configured (EINVOICE_KE_BASE_URL / sales endpoint).');
        }

        try {
            $response = Http::baseUrl($this->config['base_url'])
                ->timeout(20)
                ->withHeaders([
                    'tin' => $credentials['pin'] ?? '',
                    'bhfId' => $credentials['branch_id'] ?? '00',
                    'cmcKey' => $credentials['communication_key'] ?? '',
                ])
                ->post($this->config['sales_endpoint'], $this->map($payload));
        } catch (ConnectionException $e) {
            return SubmissionResult::retryable($e->getMessage());
        }

        if ($response->serverError()) {
            return SubmissionResult::retryable("KRA returned HTTP {$response->status()}", $response->json() ?? []);
        }

        $body = $response->json() ?? [];

        // TODO: read the result code / receipt fields named in the spec; this shape is a placeholder.
        return ($body['resultCd'] ?? null) === '000'
            ? SubmissionResult::cleared((string) ($body['data']['rcptNo'] ?? ''), $body['data']['qrUrl'] ?? null, $body['data']['rcptSign'] ?? null, $body)
            : SubmissionResult::rejected((string) ($body['resultMsg'] ?? 'Rejected by KRA'), $body);
    }

    public function status(string $reference, array $credentials): SubmissionResult
    {
        return SubmissionResult::pending(['reference' => $reference]);
    }

    /** TODO: complete per spec (item classification codes, tax type codes A-E, totals per tax type ...). */
    private function map(EInvoicePayload $p): array
    {
        return [
            'invcNo' => $p->number,
            'salesDt' => str_replace('-', '', $p->issueDate),
            'custTin' => $p->buyerTaxId,
            'custNm' => $p->buyerName,
            'totItemCnt' => count($p->lines),
            'totTaxblAmt' => $p->subtotal,
            'totTaxAmt' => $p->taxTotal,
            'totAmt' => $p->total,
            'itemList' => array_map(fn (array $l) => [
                'itemSeq' => $l['line_no'],
                'itemClsCd' => $l['authority_item_code'],
                'itemNm' => $l['description'],
                'qty' => $l['quantity'],
                'prc' => $l['unit_price'],
                'taxblAmt' => $l['subtotal'],
                'taxAmt' => $l['tax'],
                'taxTyCd' => $l['tax_code'],
            ], $p->lines),
        ];
    }
}
