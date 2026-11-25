"use client";

import { useActionState, useState } from "react";
import type { Member } from "@/lib/api";
import { groupGrantable, type Grantable } from "@/lib/team";
import { changeMember, inviteMember } from "./actions";

/** What a custom role may do: ticked one by one, grouped by area. Shown only while the role chosen is custom. */
function PermissionPicker({ grantable, chosen, id }: { grantable: Grantable[]; chosen: string[]; id: string }) {
  return (
    <fieldset className="basis-full rounded-lg border border-line p-3 text-left">
      <legend className="px-1 text-[13px] font-semibold">What they may do</legend>
      <p className="mb-2 text-[12px] text-muted">Seeing the company&apos;s accounts and taxes comes with every role. Managing people and downloading all data stay with owners and admins.</p>
      <div className="grid grid-cols-[repeat(auto-fit,minmax(230px,1fr))] gap-x-4 gap-y-3">
        {groupGrantable(grantable).map((g) => (
          <div key={g.area}>
            <p className="text-[12px] font-bold uppercase tracking-wide text-muted">{g.area}</p>
            {g.items.map((i) => (
              <label key={i.permission} className="mt-1 flex items-start gap-2 text-[13px]" htmlFor={`${id}-${i.permission}`}>
                <input type="checkbox" id={`${id}-${i.permission}`} name="permissions" value={i.permission} defaultChecked={chosen.includes(i.permission)} className="mt-0.5" />
                {i.label}
              </label>
            ))}
          </div>
        ))}
      </div>
    </fieldset>
  );
}

const Messages = ({ state }: { state?: { error?: string; notice?: string } }) => (
  <>
    {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
    {state?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
  </>
);

/** Invite someone by email with a role; someone without an Evenbooks account also gets their name on it. */
export function InviteForm({ roles, grantable }: { roles: string[]; grantable: Grantable[] }) {
  const [state, action, pending] = useActionState(inviteMember, undefined);
  const [role, setRole] = useState("bookkeeper");
  const bad = (n: string) => Boolean(state?.fields?.[n]);
  return (
    <form action={action} noValidate className="grid gap-3">
      <Messages state={state} />
      <div className="grid grid-cols-[repeat(auto-fit,minmax(190px,1fr))] gap-3">
        <div><label className="field-label" htmlFor="email">Email</label>
          <input className={`field ${bad("email") ? "!border-bad" : ""}`} id="email" name="email" type="email" autoComplete="off" /></div>
        <div><label className="field-label" htmlFor="name">Name</label>
          <input className={`field ${bad("name") ? "!border-bad" : ""}`} id="name" name="name" maxLength={120} /></div>
        <div><label className="field-label" htmlFor="role">Role</label>
          <select className={`field ${bad("role") ? "!border-bad" : ""}`} id="role" name="role" value={role} onChange={(e) => setRole(e.target.value)}>
            {roles.map((r) => <option key={r} value={r}>{r}</option>)}</select></div>
      </div>
      {role === "custom" && <PermissionPicker grantable={grantable} chosen={[]} id="invite" />}
      <div className="flex flex-wrap items-center gap-2">
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Inviting…" : "Send invitation"}</button>
        <span className="text-[13px] text-muted">They get an email with a link that works once, for 7 days. The name is needed only for someone new to Evenbooks.</span>
      </div>
    </form>
  );
}

/** One person's controls: change the role, send the invitation again, deactivate or reactivate. */
export function MemberControls({ member, roles, grantable }: { member: Member; roles: string[]; grantable: Grantable[] }) {
  const [state, action, pending] = useActionState(changeMember, undefined);
  const [role, setRole] = useState(member.role);
  const button = (value: string, label: string, tone = "") => (
    <button type="submit" name="action" value={value} disabled={pending} className={`text-[13px] font-semibold hover:underline ${tone}`}>{label}</button>
  );
  return (
    <form action={action} className="flex flex-wrap items-center justify-end gap-3">
      <input type="hidden" name="id" value={member.id} />
      {member.status !== "deactivated" && !member.me && (
        <>
          <label className="sr-only" htmlFor={`role-${member.id}`}>Role of {member.user.name}</label>
          <select className="field !h-9 !w-auto" id={`role-${member.id}`} name="role" value={role} onChange={(e) => setRole(e.target.value)}>
            {[...new Set([member.role, ...roles])].map((r) => <option key={r} value={r}>{r}</option>)}
          </select>
          {button("role", role === "custom" && member.role === "custom" ? "Save access" : "Change role", "text-brand")}
          {role === "custom" && <PermissionPicker grantable={grantable} chosen={member.permissions} id={`m-${member.id}`} />}
        </>
      )}
      {member.status === "invited" && button("resend", "Send again", "text-brand")}
      {member.status !== "deactivated" && !member.me && button("deactivate", member.status === "invited" ? "Withdraw" : "Deactivate", "text-bad")}
      {member.status === "deactivated" && button("reactivate", "Reactivate", "text-brand")}
      {(state?.error || state?.notice) && <span className="basis-full text-right"><Messages state={state} /></span>}
    </form>
  );
}
