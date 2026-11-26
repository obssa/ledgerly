import { AgeingReport } from "../AgeingDetail";

export const metadata = { title: "AR ageing" };

/** What customers owe by how late it is (summary or detail). Each customer's total is their balance on the receivable account. */
export default async function ArAgeingPage({ searchParams }: PageProps<"/reports/ar-ageing">) {
  return <AgeingReport kind="invoice" q={await searchParams} />;
}
