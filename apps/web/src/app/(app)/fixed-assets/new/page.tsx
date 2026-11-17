import Link from "next/link";
import { Card, PageTitle } from "@/components/ui";
import { getAssetOptions } from "@/lib/data";
import { AssetForm } from "../AssetForms";

export const metadata = { title: "Register an asset" };

/** Module 9c: put an asset on the register. Its cost is already in the ledger, from its bill or the opening balances. */
export default async function NewAssetPage() {
  const options = await getAssetOptions();

  return (
    <>
      <PageTitle eyebrow={<><Link href="/fixed-assets" className="hover:underline">Fixed assets</Link> / New</>} title="Register an asset" />
      {!options ? <p className="text-[14px] text-muted">Not available in the demo.</p> : (
        <Card label="Asset"><AssetForm options={options} /></Card>
      )}
    </>
  );
}
