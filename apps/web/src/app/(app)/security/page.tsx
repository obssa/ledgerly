import { Card, PageTitle, Pill } from "@/components/ui";
import { getDeviceSessions, getSecurityStatus } from "@/lib/data";
import { sessionTime } from "@/lib/sessions";
import { changePassword, endDeviceSession, signOutEverywhere } from "@/app/(auth)/actions";
import { AuthForm } from "@/app/(auth)/AuthForm";
import { AuthenticatorSetup } from "./AuthenticatorSetup";

export const metadata = { title: "Sign-in security" };

/**
 * The second factor (SECURITY.md S2). Owners and admins are sent here until they have set up an authenticator
 * app (src/lib/data.ts); anyone else can turn it on for themselves.
 */
export default async function SecurityPage() {
  const [{ mfaEnabled, demo }, sessions] = await Promise.all([getSecurityStatus(), getDeviceSessions()]);

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

      {sessions && (
        <Card className="mt-4 max-w-[820px]" label="Signed-in devices">
          <h2 className="text-[19px] font-bold">Signed-in devices</h2>
          <p className="mt-1.5 text-sm text-muted">
            Where you are signed in now. Don&apos;t recognise one? Sign it out, then change your password.
          </p>
          <div className="mt-4 overflow-x-auto">
            <table className="tbl">
              <thead>
                <tr>
                  <th scope="col">Device</th>
                  <th scope="col">Address</th>
                  <th scope="col">Signed in</th>
                  <th scope="col">Last active</th>
                  <th scope="col"><span className="sr-only">Sign out</span></th>
                </tr>
              </thead>
              <tbody>
                {sessions.map((s) => (
                  <tr key={s.id}>
                    <td>
                      {s.device}
                      {s.current && <span className="ml-2"><Pill tone="good">This device</Pill></span>}
                    </td>
                    <td className="num">{s.address ?? ""}</td>
                    <td className="num whitespace-nowrap">{sessionTime(s.signed_in_at)}</td>
                    <td className="num whitespace-nowrap">{sessionTime(s.last_active_at)}</td>
                    <td className="text-right">
                      {!s.current && (
                        <form action={endDeviceSession}>
                          <input type="hidden" name="id" value={s.id} />
                          <button type="submit" className="btn !py-1 text-[13px]">Sign out</button>
                        </form>
                      )}
                    </td>
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
