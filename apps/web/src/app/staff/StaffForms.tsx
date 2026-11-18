"use client";

import { useActionState } from "react";
import { staffCompleteSetup, staffSignIn, staffStartSession } from "./actions";

const Alert = ({ text, good }: { text?: string; good?: boolean }) =>
  text ? <p role={good ? "status" : "alert"} className={`rounded-lg px-3 py-2 text-[13px] font-semibold ${good ? "bg-good-tint text-good" : "bg-bad-tint text-bad"}`}>{text}</p> : null;

export function StaffSignInForm() {
  const [state, action, pending] = useActionState(staffSignIn, undefined);
  return (
    <form action={action} className="grid gap-3">
      <Alert text={state?.error} />
      <div><label className="field-label" htmlFor="email">Email</label><input className="field" id="email" name="email" type="email" autoComplete="username" required /></div>
      <div><label className="field-label" htmlFor="password">Password</label><input className="field" id="password" name="password" type="password" autoComplete="current-password" required /></div>
      <div><label className="field-label" htmlFor="code">Authenticator code</label><input className="field num" id="code" name="code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} required /></div>
      <button type="submit" className="btn btn-primary justify-center" disabled={pending}>{pending ? "Signing in…" : "Sign in"}</button>
    </form>
  );
}

export function StaffSetupForm({ token, secret }: { token: string; secret: string }) {
  const [state, action, pending] = useActionState(staffCompleteSetup, undefined);
  if (state?.notice) return <Alert text={state.notice} good />;
  return (
    <form action={action} className="grid gap-3">
      <Alert text={state?.error} />
      <input type="hidden" name="token" value={token} />
      <input type="hidden" name="secret" value={secret} />
      <div><label className="field-label" htmlFor="password">A password (14 characters or more, upper and lower case, a number)</label>
        <input className="field" id="password" name="password" type="password" autoComplete="new-password" minLength={14} required /></div>
      <div><label className="field-label" htmlFor="code">The code your authenticator shows now</label>
        <input className="field num" id="code" name="code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} required /></div>
      <button type="submit" className="btn btn-primary justify-center" disabled={pending}>{pending ? "Setting up…" : "Set up the account"}</button>
    </form>
  );
}

export function StartSessionForm({ companyId, name }: { companyId: string; name: string }) {
  const [state, action, pending] = useActionState(staffStartSession, undefined);
  return (
    <form action={action} className="grid gap-2">
      <Alert text={state?.error} />
      <input type="hidden" name="company_id" value={companyId} />
      <label className="field-label" htmlFor={`reason-${companyId}`}>Why you need to look into {name}&apos;s books (kept in the staff log)</label>
      <input className="field" id={`reason-${companyId}`} name="reason" minLength={10} maxLength={300} required placeholder="Owner asked why the trial balance looks wrong" />
      <div className="flex items-end gap-2">
        <div><label className="field-label" htmlFor={`minutes-${companyId}`}>For (minutes)</label>
          <input className="field num w-[90px]" id={`minutes-${companyId}`} name="minutes" inputMode="numeric" defaultValue="60" /></div>
        <button type="submit" className="btn btn-primary" disabled={pending}>Open a read-only session</button>
      </div>
    </form>
  );
}
