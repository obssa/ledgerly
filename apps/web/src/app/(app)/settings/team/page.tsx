import Link from "next/link";
import { Card, PageTitle, Pill } from "@/components/ui";
import { getMembers } from "@/lib/data";
import { InviteForm, MemberControls } from "./TeamForms";

export const metadata = { title: "Team" };

const TONE = { active: "good", invited: "warn", deactivated: "neutral" } as const;

/** Module 11: the people of the company, their roles, and invitations (owners and admins). */
export default async function TeamPage() {
  const team = await getMembers();

  return (
    <>
      <PageTitle eyebrow={<><Link href="/settings" className="hover:underline">Settings</Link> / Team</>} title="Team" />
      {!team ? <p className="text-[14px] text-muted">Not available in the demo.</p> : (
        <>
          <Card className="mb-4" label="Invite someone">
            <InviteForm roles={team.roles} />
          </Card>
          <Card className="!p-2" label="People">
            <div className="overflow-x-auto">
              <table className="tbl">
                <thead><tr><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col"><span className="sr-only">Actions</span></th></tr></thead>
                <tbody>
                  {team.data.map((m) => (
                    <tr key={m.id} className="align-top">
                      <td><span className="font-semibold">{m.user.name}</span>{m.me && <span className="ml-1 text-[12px] text-muted">(you)</span>}
                        <span className="block text-[12px] text-muted">{m.user.email}</span></td>
                      <td className="text-[13px]">{m.role}</td>
                      <td><Pill tone={TONE[m.status]}>{m.status}</Pill>
                        {m.status === "invited" && m.invited_at && <span className="block text-[11px] text-muted">since {m.invited_at.slice(0, 10)}</span>}</td>
                      <td className="text-right"><MemberControls member={m} roles={team.roles} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
          <p className="mt-3 text-[13px] text-muted">
            Someone deactivated keeps everything they did in the books and the audit log, and can be reactivated. Only an owner grants the owner role, and a company always keeps one.
            Owners and admins sign in with an authenticator app.
          </p>
        </>
      )}
    </>
  );
}
