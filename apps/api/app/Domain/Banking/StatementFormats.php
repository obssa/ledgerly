<?php

declare(strict_types=1);

namespace App\Domain\Banking;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;

/**
 * Reads a statement in whichever form the bank gave it: OFX or QFX (the bank's "Money" or "Quicken" download, SGML or
 * XML), SWIFT MT940, ISO 20022 camt.053 (XML), or CSV (StatementCsv). The form is told by the text itself, not the file
 * name. Every reader returns the same lines, each with a stable id: the bank's own transaction id when it gives one
 * (FITID, the account servicer's reference), otherwise one built from what the bank said, so importing the same
 * statement twice adds nothing the second time. Nothing is stored here and the file is never kept.
 *
 * XML is read without the network and without entities, so a file cannot make the server fetch or expand anything.
 */
final class StatementFormats
{
    public function __construct(private readonly StatementCsv $csv)
    {
    }

    /** @return 'ofx'|'mt940'|'camt053'|'csv' */
    public static function formatOf(string $text): string
    {
        $head = substr(ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text), 0, 4000);
        return match (true) {
            str_contains($head, 'OFXHEADER') || stripos($head, '<OFX>') !== false => 'ofx',
            (bool) preg_match('/urn:iso:std:iso:20022:tech:xsd:camt\.053/', $head) || (stripos($head, '<BkToCstmrStmt') !== false) => 'camt053',
            (bool) preg_match('/(^|\R):20:/', $head) && (bool) preg_match('/(^|\R):61:/', $text) => 'mt940',
            default => 'csv',
        };
    }

    /**
     * @return array{format: string, columns: array<string, string>, lines: list<array{row: int, posted_on: string, description: string, amount: string, reference: ?string, balance_after: ?string, external_id: string}>}
     */
    public function parse(string $text, string $today): array
    {
        $format = self::formatOf($text);
        if ($format === 'csv') {
            return ['format' => 'csv', ...$this->csv->parse($text, $today)];
        }
        $raw = match ($format) { 'ofx' => $this->ofx($text), 'mt940' => $this->mt940($text), default => $this->camt053($text) };

        $errors = [];
        $lines = [];
        $seen = [];
        foreach ($raw as $n => $r) {
            $row = $n + 1;
            if ($r['date'] === null) {
                $errors[] = "Entry {$row}: its date cannot be read.";
                continue;
            }
            if ($r['date'] > $today) {
                $errors[] = "Entry {$row}: dated {$r['date']}, after today.";
                continue;
            }
            try {
                $amount = BigDecimal::of($r['amount'])->toScale(2);
            } catch (MathException) {
                $errors[] = "Entry {$row}: the amount is not a number with at most 2 decimals.";
                continue;
            }
            if ($amount->isZero()) {
                continue;
            }
            $description = trim((string) preg_replace('/\s+/u', ' ', $r['description'] ?? '')) ?: ($r['reference'] ?? 'Statement line');
            $id = $r['id'] !== null && trim($r['id']) !== '' ? trim($r['id']) : null;
            $key = $id !== null ? "{$format}:" . mb_substr($id, 0, 100)
                : "{$format}:" . substr(hash('sha256', implode('|', [$r['date'], (string) $amount, $description, $r['reference'] ?? ''])), 0, 40);
            $seen[$key] = ($seen[$key] ?? 0) + 1;   // the same line twice in one statement is two lines
            $lines[] = [
                'row' => $row, 'posted_on' => $r['date'], 'description' => mb_substr($description, 0, 500), 'amount' => (string) $amount,
                'reference' => $r['reference'] !== null && trim($r['reference']) !== '' ? mb_substr(trim($r['reference']), 0, 100) : null,
                'balance_after' => null, 'external_id' => $key . ($seen[$key] > 1 ? '#' . $seen[$key] : ''),
            ];
            if (count($lines) > StatementCsv::MAX_LINES) {
                throw ValidationException::withMessages(['csv' => ['A statement can have at most ' . StatementCsv::MAX_LINES . ' lines: split it by month.']]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['csv' => array_slice($errors, 0, 20)]);
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['csv' => ['The statement has no entries with an amount.']]);
        }

        return ['format' => $format, 'columns' => [], 'lines' => $lines];
    }

    /**
     * OFX 1 (SGML: tags often left open) or OFX 2 (XML): each <STMTTRN> with DTPOSTED, TRNAMT (signed), FITID, NAME, MEMO.
     *
     * @return list<array{date: ?string, amount: string, description: ?string, reference: ?string, id: ?string}>
     */
    private function ofx(string $text): array
    {
        $out = [];
        preg_match_all('/<STMTTRN>(.*?)(<\/STMTTRN>|(?=<STMTTRN>)|(?=<\/BANKTRANLIST>))/is', $text, $blocks);
        foreach ($blocks[1] as $b) {
            $tag = function (string $name) use ($b): ?string {
                return preg_match('/<' . $name . '>([^<\r\n]*)/i', $b, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8') : null;
            };
            $name = $tag('NAME');
            $memo = $tag('MEMO');
            $out[] = [
                'date' => self::compactDate((string) $tag('DTPOSTED')),
                'amount' => str_replace(',', '.', (string) $tag('TRNAMT')),
                'description' => trim(implode(' · ', array_filter([$name, $memo !== $name ? $memo : null]))),
                'reference' => $tag('CHECKNUM') ?? $tag('REFNUM'),
                'id' => $tag('FITID'),
            ];
        }
        if ($out === []) {
            throw ValidationException::withMessages(['csv' => ['The OFX file has no transactions (STMTTRN).']]);
        }

        return $out;
    }

    /**
     * SWIFT MT940: each :61: statement line (value date YYMMDD, an optional entry date MMDD, C or D (RC, RD a reversal),
     * the amount with a decimal comma, the transaction type and the references), and the :86: information that follows.
     *
     * @return list<array{date: ?string, amount: string, description: ?string, reference: ?string, id: ?string}>
     */
    private function mt940(string $text): array
    {
        $out = [];
        $lines = preg_split('/\R/', $text) ?: [];
        $current = null;
        $info = null;
        $flush = function () use (&$out, &$current, &$info) {
            if ($current !== null) {
                $current['description'] = trim((string) $info) !== '' ? trim((string) $info) : $current['description'];
                $out[] = $current;
            }
            $current = null;
            $info = null;
        };
        foreach ($lines as $line) {
            if (preg_match('/^:61:(\d{6})(\d{4})?(R?[CD])([A-Z])?(\d+,\d{0,2})([A-Z]\w{3})?([^\/]*)(\/\/(.*))?$/', $line, $m)) {
                $flush();
                [$yy, $mm, $dd] = [substr($m[1], 0, 2), substr($m[1], 2, 2), substr($m[1], 4, 2)];
                $date = sprintf('20%s-%s-%s', $yy, $mm, $dd);
                $sign = in_array($m[3], ['D', 'RC'], true) ? '-' : '';   // RC: a credit reversed, money out
                $amount = $sign . rtrim(str_replace(',', '.', $m[5]), '.');
                $customer = trim($m[7] ?? '');
                $bank = trim($m[9] ?? '');
                $current = ['date' => checkdate((int) $mm, (int) $dd, 2000 + (int) $yy) ? $date : null, 'amount' => $amount,
                    'description' => $customer !== '' && $customer !== 'NONREF' ? $customer : null,
                    'reference' => $customer !== '' && $customer !== 'NONREF' ? $customer : null, 'id' => $bank !== '' ? $bank . '|' . $m[1] . '|' . $amount : null];
                continue;
            }
            if (str_starts_with($line, ':86:') && $current !== null) {
                $info = substr($line, 4);
                continue;
            }
            if ($info !== null && $current !== null && ! str_starts_with($line, ':')) {
                $info .= ' ' . trim($line);   // :86: goes on over several lines
                continue;
            }
            if (str_starts_with($line, ':') || str_starts_with($line, '-')) {
                $flush();
            }
        }
        $flush();
        if ($out === []) {
            throw ValidationException::withMessages(['csv' => ['The MT940 file has no statement lines (:61:).']]);
        }

        return $out;
    }

    /**
     * ISO 20022 camt.053: each <Ntry> with its amount, CRDT or DBIT, booking date, the servicer's reference and what was
     * said about it. Read without the network and without entities.
     *
     * @return list<array{date: ?string, amount: string, description: ?string, reference: ?string, id: ?string}>
     */
    private function camt053(string $text): array
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $text)) {
            throw ValidationException::withMessages(['csv' => ['The camt.053 file declares a document type: a bank statement does not, so it is not read.']]);
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($text, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $ok) {
            throw ValidationException::withMessages(['csv' => ['The camt.053 file is not well-formed XML.']]);
        }
        $x = new DOMXPath($doc);
        $value = fn (string $path, DOMElement $at): ?string => ($n = $x->query($path, $at)?->item(0)) !== null ? trim($n->textContent) : null;
        $out = [];
        foreach ($x->query('//*[local-name()="Ntry"]') ?: [] as $entry) {
            if (! $entry instanceof DOMElement) {
                continue;
            }
            $amount = (string) $value('./*[local-name()="Amt"]', $entry);
            $debit = strtoupper((string) $value('./*[local-name()="CdtDbtInd"]', $entry)) === 'DBIT';
            $date = $value('./*[local-name()="BookgDt"]/*[local-name()="Dt"]', $entry) ?? $value('./*[local-name()="BookgDt"]/*[local-name()="DtTm"]', $entry)
                ?? $value('./*[local-name()="ValDt"]/*[local-name()="Dt"]', $entry);
            $said = $value('.//*[local-name()="RmtInf"]/*[local-name()="Ustrd"]', $entry) ?? $value('./*[local-name()="AddtlNtryInf"]', $entry)
                ?? $value('.//*[local-name()="AddtlTxInf"]', $entry);
            $party = $value('.//*[local-name()="RltdPties"]//*[local-name()="Nm"]', $entry);
            $out[] = [
                'date' => $date !== null && preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $m) ? $m[1] : null,
                'amount' => ($debit ? '-' : '') . $amount,
                'description' => trim(implode(' · ', array_filter([$party, $said]))) ?: null,
                'reference' => $value('.//*[local-name()="EndToEndId"]', $entry) ?? $value('./*[local-name()="NtryRef"]', $entry),
                'id' => $value('./*[local-name()="AcctSvcrRef"]', $entry) ?? $value('.//*[local-name()="AcctSvcrRef"]', $entry),
            ];
        }
        if ($out === []) {
            throw ValidationException::withMessages(['csv' => ['The camt.053 file has no entries (Ntry).']]);
        }

        return $out;
    }

    /** "20261105", "20261105120000[+3:EAT]" -> "2026-11-05" */
    private static function compactDate(string $value): ?string
    {
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})/', trim($value), $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }
}
