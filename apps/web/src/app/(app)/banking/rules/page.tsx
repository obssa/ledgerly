import { notFound } from "next/navigation";
import { Card, PageTitle } from "@/components/ui";
import { getBankRules } from "@/lib/data";
import { categorisable } from "@/lib/statements";
import { BankRuleForm, BankRuleRow } from "./RuleForms";

export const metadata = { title: "Bank rules" };

/** Module 5: bank rules (076), tried in order: the first that fits a line to review is its suggestion. */
export default async function BankRulesPage({ searchParams }: PageProps<"/banking/rules">) {
  const q = await searchParams;
  const data = await getBankRules();
  if (!data) notFound();
  const money = data.accounts.filter((a) => a.is_active && ["bank", "cash", "mobile_money"].includes(a.subtype ?? ""));
  const targets = categorisable(data.accounts, "");
  const one = (v: string | string[] | undefined) => (typeof v === "string" ? v : undefined);

  return (
    <>
      <PageTitle title="Bank rules" />
      <p className="mb-4 max-w-[760px] text-sm text-muted">
        A rule says how a kind of statement line is categorised: the monthly bank charge, the rent, the mobile-money fee. Lines to review that a rule fits show it,
        and &ldquo;Apply the rules&rdquo; on a statement categorises them all. Rules are tried by priority, the lowest first.
      </p>
      <Card className="!p-2" label="Rules">
        {data.rules.length === 0 ? <p className="p-3 text-sm text-muted">No rules yet.</p> : (
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr><th scope="col">Rule</th><th scope="col">When the description has</th><th scope="col">On</th><th scope="col">Amount</th>
                  <th scope="col">Goes to</th><th scope="col" className="!text-right">Priority</th><th scope="col"><span className="sr-only">Actions</span></th></tr>
              </thead>
              <tbody>
                {data.rules.map((r) => <BankRuleRow key={r.id} rule={r} manage={data.can.manage} />)}
              </tbody>
            </table>
          </div>
        )}
      </Card>
      {data.can.manage && (
        <Card className="mt-4 max-w-[900px]" label="New rule">
          <h2 className="mb-2 text-[15px] font-bold">New rule</h2>
          <BankRuleForm money={money.map((a) => ({ id: a.id, label: `${a.code} ${a.name}` }))} targets={targets.map((a) => ({ id: a.id, label: `${a.code} ${a.name}` }))}
            contains={one(q.contains)} account={one(q.account)} direction={one(q.direction)} />
        </Card>
      )}
    </>
  );
}
