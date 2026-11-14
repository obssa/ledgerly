import { Card, PageTitle, Pill } from "@/components/ui";
import { getSecurityStatus } from "@/lib/data";
import { changePassword, signOutEverywhere } from "@/app/(auth)/actions";
import { AuthForm } from "@/app/(auth)/AuthForm";
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

      {!demo && (
        <div className="mt-4 flex max-w-[820px] flex-wrap gap-4">
          <Card className="min-w-0 flex-[1_1_340px]" label="Password">
            <h2 className="text-[19px] font-bold">Password</h2>
            <p className="mt-1.5 text-sm text-muted">Changing it signs you out on every other device.</p>
            <AuthForm
              action={changePassword}
              submit="Change password"
              fields={[
                { name: "current_password", label: "Current password", type: "password", autoComplete: "current-password" },
                { name: "password", label: "New password", type: "password", autoComplete: "new-password", hint: "At least 10 characters." },
              ]}
            />
          </Card>
          <Card className="min-w-0 flex-[1_1_300px]" label="Sessions">
            <h2 className="text-[19px] font-bold">Sessions</h2>
            <p className="mt-1.5 text-sm text-muted">
              Lost a phone or used a shared computer? Sign out everywhere, this device included, and sign in again.
            </p>
            <form action={signOutEverywhere}>
              <button type="submit" className="btn mt-4">Sign out everywhere</button>
            </form>
          </Card>
        </div>
      )}
    </>
  );
}
