"use client";

import { useActionState } from "react";
import type { Member } from "@/lib/api";
import { changeMember, inviteMember } from "./actions";

const Messages = ({ state }: { state?: { error?: string; notice?: string } }) => (
  <>
    {state?.error && <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{state.error}</p>}
    {state?.notice && <p role="status" className="rounded-lg bg-good-tint px-3 py-2 text-[13px] font-semibold text-good">{state.notice}</p>}
  </>
);

/** Invite someone by email with a role; someone without a Ledgerly account also gets their name on it. */
export function InviteForm({ roles }: { roles: string[] }) {
  const [state, action, pending] = useActionState(inviteMember, undefined);
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
          <select className={`field ${bad("role") ? "!border-bad" : ""}`} id="role" name="role" defaultValue="bookkeeper">
            {roles.map((r) => <option key={r} value={r}>{r}</option>)}</select></div>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <button type="submit" className="btn btn-primary" disabled={pending}>{pending ? "Inviting…" : "Send invitation"}</button>
        <span className="text-[13px] text-muted">They get an email with a link that works once, for 7 days. The name is needed only for someone new to Ledgerly.</span>
      </div>
    </form>
  );
}

/** One person's controls: change the role, send the invitation again, deactivate or reactivate. */
export function MemberControls({ member, roles }: { member: Member; roles: string[] }) {
  const [state, action, pending] = useActionState(changeMember, undefined);
  const button = (value: string, label: string, tone = "") => (
    <button type="submit" name="action" value={value} disabled={pending} className={`text-[13px] font-semibold hover:underline ${tone}`}>{label}</button>
  );
  return (
    <form action={action} className="flex flex-wrap items-center justify-end gap-3">
      <input type="hidden" name="id" value={member.id} />
      {member.status !== "deactivated" && !member.me && (
        <>
          <label className="sr-only" htmlFor={`role-${member.id}`}>Role of {member.user.name}</label>
          <select className="field !h-9 !w-auto" id={`role-${member.id}`} name="role" defaultValue={member.role}>
            {[...new Set([member.role, ...roles])].map((r) => <option key={r} value={r}>{r}</option>)}
          </select>
          {button("role", "Change role", "text-brand")}
        </>
      )}
      {member.status === "invited" && button("resend", "Send again", "text-brand")}
      {member.status !== "deactivated" && !member.me && button("deactivate", member.status === "invited" ? "Withdraw" : "Deactivate", "text-bad")}
      {member.status === "deactivated" && button("reactivate", "Reactivate", "text-brand")}
      {(state?.error || state?.notice) && <span className="basis-full text-right"><Messages state={state} /></span>}
    </form>
  );
}
