import Link from "@/components/Link";
import { Card, PageTitle } from "@/components/ui";
import { getTags } from "@/lib/data";
import { AddTag, TagRow } from "./TagForms";

export const metadata = { title: "Tags" };

/** Module 1 (094): the company's own tags, put on whole invoices, bills, expenses and journal entries. */
export default async function TagsPage() {
  const t = await getTags();
  return (
    <>
      <PageTitle title="Tags" actions={<Link href="/reports/by-tag" className="btn">Profit by tag</Link>} />
      {!t ? <p className="text-[14px] text-muted">Not available in the demo.</p> : (
        <Card className="max-w-[760px]" label="Tags">
          <p className="mb-3 text-[13px] text-muted">
            A tag goes on a whole transaction (an event, a campaign, a branch launch), several to a transaction, alongside the location, class and project on its lines.
            Put them on from the invoice, bill, expense or journal entry. A tag turned off stays where it is, but goes on nothing new.
          </p>
          {t.can.manage && <div className="mb-3"><AddTag /></div>}
          {t.data.length === 0 ? <p className="text-sm text-muted">No tags yet.</p> : t.can.manage ? (
            <ul>{t.data.map((tag) => <TagRow key={tag.id} tag={tag} />)}</ul>
          ) : (
            <ul className="text-sm">{t.data.map((tag) => <li key={tag.id} className="border-t border-line py-1.5">{tag.name} <span className="text-muted">· {tag.used}{tag.is_active ? "" : " · off"}</span></li>)}</ul>
          )}
        </Card>
      )}
    </>
  );
}
