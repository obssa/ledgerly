import { DocumentAnalysisView } from "../DocumentAnalysisView";

export const metadata = { title: "Purchases analysis" };

/** Module 10: purchases by supplier, item or month. */
export default async function Page({ searchParams }: PageProps<"/reports/purchases">) {
  return <DocumentAnalysisView side="purchases" q={await searchParams} />;
}
