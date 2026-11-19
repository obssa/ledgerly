<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Banking\StatementCsv;
use App\Domain\Banking\StatementFormats;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;   // the app: a refusal is a validation error, which needs its translator

/** Statement formats beyond CSV: OFX (SGML and XML), MT940 and camt.053 read into the same lines, with stable ids. */
final class StatementFormatsTest extends TestCase
{
    private function read(string $text, string $today = '2026-11-30'): array
    {
        return (new StatementFormats(new StatementCsv()))->parse($text, $today);
    }

    public function test_ofx_sgml_with_open_tags_and_the_banks_own_ids(): void
    {
        $ofx = "OFXHEADER:100\nDATA:OFXSGML\nVERSION:102\n\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>\n"
            . "<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261105120000[+3:EAT]<TRNAMT>-115.00<FITID>FT26311<NAME>Fuel station R-7781<MEMO>Card\n"
            . "<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20261109<TRNAMT>4.10<FITID>INT-1109<NAME>Interest\n"
            . "</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>";
        $r = $this->read($ofx);
        $this->assertSame('ofx', $r['format']);
        $this->assertSame([['2026-11-05', '-115.00', 'Fuel station R-7781 · Card', 'ofx:FT26311'], ['2026-11-09', '4.10', 'Interest', 'ofx:INT-1109']],
            array_map(fn ($l) => [$l['posted_on'], $l['amount'], $l['description'], $l['external_id']], $r['lines']));
    }

    public function test_ofx_xml_closed_tags(): void
    {
        $ofx = '<?xml version="1.0"?><?OFX OFXHEADER="200" VERSION="211"?><OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>'
            . '<STMTTRN><DTPOSTED>20261103</DTPOSTED><TRNAMT>-2.50</TRNAMT><FITID>SMS-1</FITID><NAME>SMS alert charge</NAME></STMTTRN>'
            . '</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>';
        $this->assertSame([['2026-11-03', '-2.50', 'SMS alert charge']], array_map(fn ($l) => [$l['posted_on'], $l['amount'], $l['description']], $this->read($ofx)['lines']));
    }

    public function test_mt940_lines_with_their_information_and_reversals(): void
    {
        $mt = ":20:STMT2611\n:25:1000123456789\n:28C:00011/001\n:60F:C261101ETB15000,00\n"
            . ":61:2611051105D115,00NTRFNONREF//FT26311\n:86:Fuel station R-7781\nCard purchase\n"
            . ":61:2611091109C4,10NINTNONREF//INT1109\n:86:Interest\n"
            . ":61:2611101110RD20,NMSCREF-9//REV-1\n:86:Reversal of a deposit\n:62F:C261130ETB14869,10\n-";
        $r = $this->read($mt);
        $this->assertSame('mt940', $r['format']);
        $this->assertSame([['2026-11-05', '-115.00', 'Fuel station R-7781 Card purchase'], ['2026-11-09', '4.10', 'Interest'], ['2026-11-10', '20.00', 'Reversal of a deposit']],
            array_map(fn ($l) => [$l['posted_on'], $l['amount'], $l['description']], $r['lines']));
        $this->assertSame('REF-9', $r['lines'][2]['reference']);
    }

    public function test_camt053_entries_and_no_entities(): void
    {
        $camt = '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt>'
            . '<Ntry><Amt Ccy="ETB">115.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><BookgDt><Dt>2026-11-05</Dt></BookgDt><AcctSvcrRef>FT26311</AcctSvcrRef>'
            . '<NtryDtls><TxDtls><Refs><EndToEndId>R-7781</EndToEndId></Refs><RltdPties><Cdtr><Nm>Fuel station</Nm></Cdtr></RltdPties>'
            . '<RmtInf><Ustrd>Card purchase</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>'
            . '<Ntry><Amt Ccy="ETB">4.10</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><DtTm>2026-11-09T10:00:00</DtTm></BookgDt><AddtlNtryInf>Interest</AddtlNtryInf></Ntry>'
            . '</Stmt></BkToCstmrStmt></Document>';
        $r = $this->read($camt);
        $this->assertSame('camt053', $r['format']);
        $this->assertSame([['2026-11-05', '-115.00', 'Fuel station · Card purchase', 'R-7781', 'camt053:FT26311'], ['2026-11-09', '4.10', 'Interest', null, $r['lines'][1]['external_id']]],
            array_map(fn ($l) => [$l['posted_on'], $l['amount'], $l['description'], $l['reference'], $l['external_id']], $r['lines']));
        $this->assertStringStartsWith('camt053:', $r['lines'][1]['external_id']);

        $this->expectException(ValidationException::class);
        $this->read('<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/passwd">]><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt><Ntry><Amt>1</Amt><AddtlNtryInf>&x;</AddtlNtryInf></Ntry></Stmt></BkToCstmrStmt></Document>');
    }

    public function test_a_line_after_today_is_refused_and_csv_still_reads_as_csv(): void
    {
        try {
            $this->read("OFXHEADER:100\n<OFX><BANKTRANLIST><STMTTRN><DTPOSTED>20261205<TRNAMT>-1.00<FITID>X</BANKTRANLIST></OFX>");
            $this->fail('A line after today was read.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('after today', implode(' ', $e->errors()['csv']));
        }
        $this->assertSame('csv', $this->read("Date,Description,Amount\n05/11/2026,Fuel,-115.00\n")['format']);
    }
}
