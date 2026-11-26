import type { ReactNode } from "react";
import Link from "@/components/Link";
import { Card } from "@/components/ui";

type Doc = {
  id: string;
  status: string;
  can: { edit: boolean; void: boolean; credit?: boolean; correct?: boolean };
  correct_blocked?: string | null;
};

function Option({ title, children, action }: { title: string; children: ReactNode; action?: ReactNode }) {
  return (
    <li className="rounded-xl border border-line-soft p-3">
      <div className="text-[13.5px] font-semibold">{title}</div>
      <p className="mt-0.5 text-[12.5px] text-muted">{children}</p>
      {action && <div className="mt-2">{action}</div>}
    </li>
  );
}

/**
 * "Fix a mistake": the ways to put an invoice or bill right, for the state it is in, so nobody has to guess between
 * editing, correcting, a credit note and voiding. Every one of them keeps the history.
 */
export function FixMistake({ kind, doc }: { kind: "invoice" | "bill"; doc: Doc }) {
  const base = `/${kind === "bill" ? "bills" : "invoices"}/${encodeURIComponent(doc.id)}`;
  const noun = kind === "bill" ? "bill" : "invoice";
  const creditWord = kind === "bill" ? "supplier credit" : "credit note";
  const creditHref = kind === "bill" ? `${base}/credit` : `${base}/credit-note`;
  const draft = ["draft", "awaiting_approval"].includes(doc.status);
  const booked = ["posted", "part_paid", "paid"].includes(doc.status);
  if (doc.status === "void") return null;

  return (
    <Card label="Fix a mistake">
      <h2 className="text-[17px] font-bold">Fix a mistake</h2>
      <ul className="mt-2.5 grid gap-2">
        {draft && (
          <Option title="Edit the draft" action={doc.can.edit ? <Link href={`${base}/edit`} className="btn btn-primary">Edit the draft</Link> : undefined}>
            Nothing is in the books yet: change anything, as often as you need.{doc.can.edit ? "" : " Your role cannot edit it; ask whoever made it."}
          </Option>
        )}
        {doc.status === "posted" && (doc.can.correct ? (
          <Option title={`Edit this ${noun}`} action={<Link href={`${base}/edit`} className="btn btn-primary">Edit</Link>}>
            Change the lines, dates or amounts, say why, and save. The {noun} keeps its number; the history shows who changed what, and the version before.
          </Option>
        ) : doc.correct_blocked ? (
          <Option title={`This ${noun} cannot be edited as it is`}>{doc.correct_blocked}</Option>
        ) : (
          <Option title={`Edit this ${noun}`}>
            Only an Owner, Admin, Accountant or Bookkeeper, or a custom role with &quot;Edit posted invoices and bills&quot;, can edit a posted {noun}. Ask your admin.
          </Option>
        ))}
        {booked && doc.status !== "posted" && doc.correct_blocked && (
          <Option title={`This ${noun} cannot be edited as it is`}>{doc.correct_blocked}</Option>
        )}
        {booked && doc.can.credit && (
          <Option title={`Issue a ${creditWord}`} action={<Link href={creditHref} className="btn">{kind === "bill" ? "Record a supplier credit" : "Issue a credit note"}</Link>}>
            For part of it (goods returned, a discount agreed later), or when it has been paid. The {noun} stays as it was; the {creditWord} reduces what is owed.
          </Option>
        )}
        {(draft || doc.status === "posted") && doc.can.void && (
          <Option title={`Void the whole ${noun}`}>
            When it should never have existed and nothing is paid on it: use Void with a reason. It no longer counts in the books; its number is kept.
          </Option>
        )}
        {kind === "invoice" && ["posted", "part_paid"].includes(doc.status) && (
          <Option title="The customer will never pay">Write it off as a bad debt in the panel below, with a reason. It can be reversed if they pay after all.</Option>
        )}
      </ul>
    </Card>
  );
}
