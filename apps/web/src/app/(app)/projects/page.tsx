import Link from "@/components/Link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getProjects } from "@/lib/data";
import { formatAmount } from "@/lib/format";
import { PROJECT_STATUSES } from "@/lib/projects";

export const metadata = { title: "Projects" };

const STATUS = Object.fromEntries(PROJECT_STATUSES) as Record<string, string>;

/** Module 9a: projects with their income, costs and profit, read from the ledger lines tagged with them. */
export default async function ProjectsPage() {
  const list = await getProjects();

  return (
    <>
      <PageTitle title="Projects" actions={list?.can.manage ? <Link href="/projects/new" className="btn btn-primary">New project</Link> : undefined} />
      {!list ? <p className="text-[14px] text-muted">Not available in the demo, or for your role.</p> : (
        <Card className="!p-2" label="Projects">
          <div className="overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr>
                  <th scope="col">Project</th><th scope="col">Customer</th><th scope="col">Status</th>
                  <th scope="col" className="!text-right">Contract</th><th scope="col" className="!text-right">Income</th><th scope="col" className="!text-right">Costs</th><th scope="col" className="!text-right">Profit</th>
                </tr>
              </thead>
              <tbody>
                {list.data.length === 0 && <tr><td colSpan={7} className="text-muted">No projects yet. Tag invoice, bill and expense lines with one to see what it earns and costs.</td></tr>}
                {list.data.map((p) => (
                  <tr key={p.id}>
                    <td><Link href={`/projects/${encodeURIComponent(p.id)}`} className="font-semibold hover:underline"><span className="num">{p.code}</span> {p.name}</Link></td>
                    <td>{p.customer?.name ?? "—"}</td>
                    <td><Pill tone={p.status === "active" ? "good" : p.status === "on_hold" ? "warn" : "neutral"}>{STATUS[p.status] ?? p.status}</Pill></td>
                    <td className="num text-right">{p.contract_value ? formatAmount(p.contract_value) : "—"}</td>
                    <td className="num text-right">{formatAmount(p.income)}</td>
                    <td className="num text-right">{formatAmount(p.costs)}</td>
                    <td className={`num text-right font-semibold ${p.profit.startsWith("-") ? "text-bad" : ""}`}>{formatAmount(p.profit)}</td>
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
