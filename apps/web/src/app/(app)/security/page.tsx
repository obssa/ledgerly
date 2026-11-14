import { Card, PageTitle, Pill } from "@/components/ui";
import { getSecurityStatus } from "@/lib/data";
import { AuthenticatorSetup } from "./AuthenticatorSetup";

export const metadata = { title: "Sign-in security" };

/**
 * The second factor (SECURITY.md S2). Owners and admins are sent here until they have set up an authenticator
 * app (src/lib/data.ts); anyone else can turn it on for themselves.
 */
export default async function SecurityPage() {
  const { mfaEnabled, demo } = await getSecurityStatus();

  return (
    <>
      <PageTitle eyebrow="Your account" title="Sign-in security" />
      <Card className="max-w-[820px]" label="Authenticator app">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-[19px] font-bold">Authenticator app</h2>
          <Pill tone={mfaEnabled ? "good" : "warn"}>{mfaEnabled ? "On" : "Not set up"}</Pill>
        </div>
        <p className="mb-5 mt-1.5 max-w-[640px] text-sm text-muted">
          A password can be guessed or stolen. With an authenticator app, signing in also needs a code from your
          phone. Owners and admins must have one before they can work in a company.
        </p>
        {demo ? (
          <p className="text-sm text-muted">Not available in the demo.</p>
        ) : mfaEnabled ? (
          <p className="text-sm">Signing in asks for a code from your authenticator app, or one of your recovery codes.</p>
        ) : (
          <AuthenticatorSetup />
        )}
      </Card>
    </>
  );
}
