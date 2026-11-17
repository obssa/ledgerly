import { DocumentAnalysisView } from "../DocumentAnalysisView";

export const metadata = { title: "Sales analysis" };

/** Module 10: sales by customer, item or month. */
export default async function Page({ searchParams }: PageProps<"/reports/sales">) {
  return <DocumentAnalysisView side="sales" q={await searchParams} />;
}
