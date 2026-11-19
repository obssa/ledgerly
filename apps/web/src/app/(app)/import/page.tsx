import Link from "next/link";
import { Card, PageTitle } from "@/components/ui";
import { IMPORT_KINDS, importKind, importRequires } from "@/lib/imports";
import { ImportForm } from "./ImportForm";

export const metadata = { title: "Import from a spreadsheet" };

/**
 * Module 1: customers, suppliers, items, the chart of accounts and the opening trial balance brought in from a spreadsheet
 * saved as CSV (Excel: File, Save as, CSV UTF-8).
 * The same rules apply as adding one by hand; a check shows every row to fix, and an import adds all the rows or none.
 */
export default async function ImportPage({ searchParams }: PageProps<"/import">) {
  const kind = importKind((await searchParams).kind);
  const k = IMPORT_KINDS[kind];
  return (
    <>
      <PageTitle eyebrow="Setup" title="Import from a spreadsheet" />
      <nav className="mb-4 flex flex-wrap gap-2" aria-label="What to import">
        {(Object.keys(IMPORT_KINDS) as (keyof typeof IMPORT_KINDS)[]).map((key) => (
          <Link key={key} href={`/import?kind=${key}`} aria-current={key === kind ? "page" : undefined}
            className={`btn ${key === kind ? "btn-primary" : ""}`}>{IMPORT_KINDS[key].label}</Link>
        ))}
      </nav>
      <div className="flex flex-wrap items-start gap-4">
        <Card className="min-w-0 flex-[2_1_520px]" label={`Import ${k.label.toLowerCase()}`}>
          <h2 className="mb-3 text-[17px] font-bold">{k.label}</h2>
          <ImportForm key={kind} kind={kind} example={k.example} />
        </Card>
        <Card className="min-w-0 flex-[1_1_280px]" label="The file">
          <h2 className="text-[15px] font-bold">The file</h2>
          <p className="mt-1.5 text-[13px] text-muted">Choose the Excel workbook (its first sheet is read) or a CSV file. The first line names the columns. {importRequires(kind)} Columns read:</p>
          <ul className="mt-2 list-disc pl-5 text-[13px]">{k.columns.map((c) => <li key={c}>{c}</li>)}</ul>
          <p className="mt-2 text-[13px] text-muted">Up to 2,000 rows. Amounts may have thousands separators. Other columns are left out and named after a check.</p>
        </Card>
      </div>
    </>
  );
}
