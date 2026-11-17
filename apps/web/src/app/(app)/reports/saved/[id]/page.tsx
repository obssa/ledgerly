import { notFound, redirect } from "next/navigation";
import { getSavedReport } from "@/lib/data";
import { savedReportUrl } from "@/lib/saved-reports";

/** The link in a scheduled report's email: opens the saved report for the dates its period covers today. */
export default async function OpenSavedReport({ params }: PageProps<"/reports/saved/[id]">) {
  const { id } = await params;
  if (!/^[0-9a-f-]{36}$/.test(id)) notFound();
  const saved = await getSavedReport(id);
  if (!saved) notFound();
  redirect(savedReportUrl(saved));
}
