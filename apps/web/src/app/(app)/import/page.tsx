import Link from "@/components/Link";
import { Card, PageTitle, Segmented } from "@/components/ui";
import { IMPORT_KINDS, importKind, importRequires } from "@/lib/imports";
import { MIGRATION_SOURCES, migrationSource, type MigrationSource } from "@/lib/migration";
import { ImportForm } from "./ImportForm";

export const metadata = { title: "Import from a spreadsheet" };

/**
 * Module 1: customers, suppliers, items, the chart of accounts and the opening trial balance brought in from a spreadsheet
 * saved as CSV (Excel: File, Save as, CSV UTF-8).
 * The same rules apply as adding one by hand; a check shows every row to fix, and an import adds all the rows or none.
 */
export default async function ImportPage({ searchParams }: PageProps<"/import">) {
  const q = await searchParams;
  const kind = importKind(q.kind);
  const k = IMPORT_KINDS[kind];
  const from = migrationSource(q.from);
  const guide = from ? MIGRATION_SOURCES[from] : null;
  const link = (key: string) => `/import?kind=${key}${from ? `&from=${from}` : ""}`;
  return (
    <>
      <PageTitle eyebrow="Setup" title="Import from a spreadsheet" />
      <Segmented label="What to import" items={(Object.keys(IMPORT_KINDS) as (keyof typeof IMPORT_KINDS)[]).map((key) => (
        { key, href: link(key), label: IMPORT_KINDS[key].label, active: key === kind }
      ))} />
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
      <Card className="mt-4 max-w-[1000px]" label="Moving from another program">
        <h2 className="text-[15px] font-bold">Moving from another program?</h2>
        <Segmented label="Moving from" size="sm" className="mt-2 !mb-0" items={(Object.keys(MIGRATION_SOURCES) as MigrationSource[]).map((s) => (
          { key: s, href: `/import?kind=${kind}&from=${s}`, label: MIGRATION_SOURCES[s].name, active: s === from }
        ))} />
        {guide ? (
          <>
            <p className="mt-3 text-[13px] text-muted">{guide.note}</p>
            <ol className="mt-2 grid gap-1.5 text-[13px]">
              {guide.steps.map((s, i) => (
                <li key={s.kind} className={s.kind === kind ? "font-semibold" : ""}>
                  <span className="num">{i + 1}.</span> <Link href={link(s.kind)} className="text-brand hover:underline">{IMPORT_KINDS[s.kind].label}</Link>: {s.how}
                </li>
              ))}
            </ol>
            <p className="mt-2 text-[13px] text-muted">Each export comes in as it is: its own column names and account types are read. Check first: nothing is kept until every row is fine.</p>
          </>
        ) : <p className="mt-2 text-[13px] text-muted">Pick the program you are leaving: the reports to export from it and the order to bring them in.</p>}
      </Card>
    </>
  );
}
