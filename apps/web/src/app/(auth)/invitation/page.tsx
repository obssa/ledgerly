import { Suspense } from "react";
import Link from "next/link";
import { ApiError, ledgerly } from "@/lib/api";
import { acceptInvitation } from "../actions";
import { AuthForm } from "../AuthForm";

export const metadata = { title: "Accept the invitation" };

/** Opened from the emailed link: /invitation?token=… The link works once, for 7 days. */
export default function InvitationPage({ searchParams }: PageProps<"/invitation">) {
  return (
    <>
      <h1 className="text-[24px] font-extrabold tracking-tight">Accept the invitation</h1>
      <Suspense fallback={null}>
        <Invitation searchParams={searchParams} />
      </Suspense>
      <p className="mt-5 text-center text-sm text-muted">
        <Link href="/sign-in" className="font-bold text-brand">Go to sign in</Link>
      </p>
    </>
  );
}

const broken = (text: string) => <p role="alert" className="mt-5 rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{text}</p>;

async function Invitation({ searchParams }: Pick<PageProps<"/invitation">, "searchParams">) {
  const { token } = await searchParams;
  if (typeof token !== "string" || !/^[A-Za-z0-9]{64}$/.test(token)) return broken("This link is not complete. Open the link from the email again.");
  let invitation;
  try {
    invitation = await ledgerly.invitation(token);
  } catch (error) {
    if (error instanceof ApiError && (error.status === 404 || error.status === 422)) {
      return broken("This invitation link is not valid any more: it was used, withdrawn or has expired. Ask for a new one.");
    }
    throw error;
  }
  return (
    <>
      <p className="mt-1.5 text-sm text-muted">
        You are invited to <strong className="text-ink">{invitation.company_name}</strong> as {invitation.role}, with the email {invitation.email}.
        {invitation.new_account ? " Choose your password to finish your account." : " Accept, then sign in with your Ledgerly account as usual."}
      </p>
      <AuthForm
        action={acceptInvitation}
        submit="Accept and join"
        hidden={{ token }}
        fields={invitation.new_account ? [
          { name: "name", label: "Your name", type: "text", autoComplete: "name", hint: `As your colleagues see it. Now: ${invitation.name}` },
          { name: "password", label: "Password", type: "password", autoComplete: "new-password", hint: "At least 10 characters. Passwords found in known data leaks are refused." },
        ] : []}
      />
    </>
  );
}
