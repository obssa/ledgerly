import Link from "next/link";
import { Card } from "@/components/ui";
import type { TaxDocumentRow, TaxRateRow, TaxWithholdingRegister, TaxWorksheet } from "@/lib/api";
import { formatAmount } from "@/lib/format";

/* A tax worksheet, live or as it was filed: the figures, rate by rate, document by document, and what no document explains. */

const DOC_PATH: Record<string, string> = { invoice: "invoices", credit_note: "invoices", sales_receipt: "sales-receipts", bill: "bills", vendor_credit: "bills", expense: "expenses" };
const DOC_LABEL: Record<string, string> = { invoice: "Invoice", credit_note: "Credit note", sales_receipt: "Cash sale", bill: "Bill", vendor_credit: "Supplier credit", expense: "Expense" };

function Rates({ rows, caption }: { rows: TaxRateRow[]; caption: string }) {
  return (
    <table className="tbl">
      <caption className="px-3 pt-2 text-left text-[14px] font-bold">{caption}</caption>
      <thead><tr><th scope="col">Rate</th><th scope="col" className="!text-right">Taxable</th><th scope="col" className="!text-right">Tax</th></tr></thead>
      <tbody>
        {rows.length === 0 && <tr><td colSpan={3} className="text-muted">None in the period.</td></tr>}
        {rows.map((r) => (
          <tr key={r.code}>
            <td>{r.name}{r.rate !== null && <span className="ml-1 num text-muted">{(Number(r.rate) * 100).toLocaleString("en", { maximumFractionDigits: 4 })}%</span>}</td>
            <td className="num text-right">{formatAmount(r.taxable)}</td><td className="num text-right">{formatAmount(r.tax)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function Documents({ rows, caption }: { rows: TaxDocumentRow[]; caption: string }) {
  return (
    <table className="tbl">
      <caption className="px-3 pt-2 text-left text-[14px] font-bold">{caption}</caption>
      <thead><tr><th scope="col">Date</th><th scope="col">Document</th><th scope="col">Customer or supplier</th><th scope="col">TIN</th><th scope="col" className="!text-right">Taxable</th><th scope="col" className="!text-right">Tax</th></tr></thead>
      <tbody>
        {rows.length === 0 && <tr><td colSpan={6} className="text-muted">None in the period.</td></tr>}
        {rows.map((d) => (
          <tr key={d.id}>
            <td className="num">{d.date}</td>
            <td><Link href={`/${DOC_PATH[d.type] ?? "invoices"}/${encodeURIComponent(d.id)}`} className="hover:underline">{DOC_LABEL[d.type] ?? d.type} {d.number ?? ""}</Link></td>
            <td>{d.contact}</td><td className="num">{d.tax_id ?? "—"}</td>
            <td className="num text-right">{formatAmount(d.taxable)}</td><td className="num text-right">{formatAmount(d.tax)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

function Register({ register, caption, path, who }: { register: TaxWithholdingRegister; caption: string; path: string; who: string }) {
  return (
    <Card className="mb-4 !p-2" label={caption}>
      <div className="overflow-x-auto">
        <table className="tbl">
          <caption className="px-3 pt-2 text-left text-[14px] font-bold">{caption}</caption>
          <thead><tr><th scope="col">Date</th><th scope="col">{who}</th><th scope="col">TIN</th><th scope="col">Certificate</th><th scope="col" className="!text-right">Gross</th><th scope="col" className="!text-right">Withheld</th></tr></thead>
          <tbody>
            {register.payments.length === 0 && <tr><td colSpan={6} className="text-muted">None in the period.</td></tr>}
            {register.payments.map((p) => (
              <tr key={p.id}>
                <td className="num">{p.date}</td><td><Link href={`/${path}/${encodeURIComponent(p.id)}`} className="hover:underline">{p.contact ?? "—"}</Link></td>
                <td className="num">{p.tax_id ?? "—"}</td><td>{p.certificate ?? "—"}</td>
                <td className="num text-right">{formatAmount(p.gross)}</td><td className="num text-right">{formatAmount(p.withheld)}</td>
              </tr>
            ))}
          </tbody>
          <tfoot><tr className="border-t-2 border-ink font-bold"><td colSpan={4}>Total</td><td className="num text-right">{formatAmount(register.gross)}</td><td className="num text-right">{formatAmount(register.tax)}</td></tr></tfoot>
        </table>
      </div>
    </Card>
  );
}

export function Worksheet({ sheet }: { sheet: TaxWorksheet }) {
  const vat = sheet.kind === "vat";
  const tot = sheet.kind === "tot";
  const name = vat ? "VAT" : tot ? "turnover tax" : "withholding";
  const unexplained = sheet.other.output !== "0.00" || sheet.other.input !== "0.00";
  return (
    <>
      <Card className="mb-4" label="Figures">
        <dl className="grid grid-cols-[repeat(auto-fit,minmax(170px,1fr))] gap-3 text-sm">
          <div><dt className="text-muted">{vat ? "Output VAT (sales)" : tot ? "Turnover tax (sales)" : "Withheld from suppliers"}</dt><dd className="num text-[18px] font-bold">{formatAmount(sheet.output_tax)}</dd></div>
          {vat && <div><dt className="text-muted">Input VAT (purchases)</dt><dd className="num text-[18px] font-bold">{formatAmount(sheet.input_tax)}</dd></div>}
          <div>
            <dt className="text-muted">{sheet.net.startsWith("-") ? "Credit carried forward" : "Due to the authority"}</dt>
            <dd className="num text-[18px] font-bold">{formatAmount(sheet.net.replace("-", ""))} <span className="text-[13px] font-normal text-muted">{sheet.currency}</span></dd>
          </div>
        </dl>
        <p className="mt-3 text-[13px] text-muted">
          What the {name} accounts moved from <span className="num">{sheet.from}</span> to <span className="num">{sheet.to}</span> (settlements of earlier returns left out).
        </p>
      </Card>
      {vat && sheet.sales && sheet.purchases && (
        <>
          <Card className="mb-4 !p-2" label="By rate">
            <div className="grid gap-4 overflow-x-auto md:grid-cols-2"><Rates rows={sheet.sales.rates} caption="Sales" /><Rates rows={sheet.purchases.rates} caption="Purchases" /></div>
          </Card>
          <Card className="mb-4 !p-2" label="Sales documents"><div className="overflow-x-auto"><Documents rows={sheet.sales.documents} caption="Sales documents" /></div></Card>
          <Card className="mb-4 !p-2" label="Purchase documents"><div className="overflow-x-auto"><Documents rows={sheet.purchases.documents} caption="Purchase documents" /></div></Card>
        </>
      )}
      {tot && sheet.sales && (
        <>
          <Card className="mb-4 !p-2" label="By rate"><div className="overflow-x-auto"><Rates rows={sheet.sales.rates.filter((r) => r.kind === "tot" || r.tax !== "0.00")} caption="Sales at a turnover tax rate" /></div></Card>
          <Card className="mb-4 !p-2" label="Sales documents"><div className="overflow-x-auto"><Documents rows={sheet.sales.documents.filter((d) => d.tax !== "0.00")} caption="Sales documents with turnover tax" /></div></Card>
        </>
      )}
      {!vat && !tot && sheet.withheld && <Register register={sheet.withheld} caption="Payments to suppliers with tax withheld" path="payments-made" who="Supplier" />}
      {!vat && !tot && sheet.withheld_by_customers && sheet.withheld_by_customers.payments.length > 0 && (
        <Register register={sheet.withheld_by_customers} caption="Tax customers withheld from payments to you (a credit against profit tax, not part of this return)" path="payments" who="Customer" />
      )}
      {unexplained && (
        <Card className="mb-4 !p-2" label="Not from a document">
          <h2 className="px-3 pt-2 text-[15px] font-bold">Not explained by a current document</h2>
          <p className="px-3 text-[13px] text-muted">Manual journals, voids and edits on the tax accounts. They are part of the figures above.</p>
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead><tr><th scope="col">Entry</th><th scope="col">Date</th><th scope="col">Memo</th><th scope="col" className="!text-right">{vat ? "Output" : "Withheld"}</th>{vat && <th scope="col" className="!text-right">Input</th>}</tr></thead>
              <tbody>
                {sheet.other.entries.map((e) => (
                  <tr key={e.id}>
                    <td className="num"><Link href={`/journal/${encodeURIComponent(e.id)}`} className="hover:underline">#{e.number}</Link></td>
                    <td className="num">{e.date}</td><td>{e.memo ?? e.source_type.replace(/_/g, " ")}</td>
                    <td className="num text-right">{formatAmount(e.output)}</td>{vat && <td className="num text-right">{formatAmount(e.input)}</td>}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </>
  );
}
