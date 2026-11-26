import Link from "@/components/Link";
import { Card, Pill } from "@/components/ui";
import type { TagKind } from "@/lib/api";
import { getTransactionTags } from "@/lib/data";
import { TagPicker } from "./TagForms";

/** 094: the tags on an invoice, bill, expense or journal entry, and choosing them. Nothing when the role cannot see them. */
export async function TagsPanel({ kind, id, demo }: { kind: TagKind; id: string; demo?: boolean }) {
  const t = await getTransactionTags(kind, id);
  if (t === null || (t.data.length === 0 && t.tags.length === 0)) return null;
  return (
    <Card label="Tags">
      <div className="mb-1.5 flex items-center justify-between gap-2">
        <h2 className="text-[17px] font-bold">Tags</h2>
        <Link href="/tags" className="text-[13px] text-brand hover:underline">Manage tags</Link>
      </div>
      {t.can.tag && !demo ? <TagPicker kind={kind} id={id} chosen={t.data} tags={t.tags} /> : (
        t.data.length === 0 ? <p className="text-sm text-muted">No tags.</p>
          : <div className="flex flex-wrap gap-1.5">{t.data.map((x) => <Pill key={x.id} tone="brand">{x.name}</Pill>)}</div>
      )}
    </Card>
  );
}
