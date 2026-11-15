import Link from "next/link";
import { Card, PageTitle } from "@/components/ui";

export const metadata = { title: "Not in your role" };

/** Where a page goes when the API refuses it for the member's role: the company is fine, the role is narrower. */
export default function NoAccessPage() {
  return (
    <>
      <PageTitle title="Not in your role" />
      <Card className="max-w-[620px]">
        <p className="text-sm">Your role in this company does not include that page. An owner or admin can change your role.</p>
        <Link href="/" className="btn mt-4">Back to home</Link>
      </Card>
    </>
  );
}
