import { AgeingReport } from "../AgeingDetail";

export const metadata = { title: "AP ageing" };

/** What the company owes suppliers by how late it is (summary or detail). Each total is their balance on accounts payable. */
export default async function ApAgeingPage({ searchParams }: PageProps<"/reports/ap-ageing">) {
  return <AgeingReport kind="bill" q={await searchParams} />;
}
