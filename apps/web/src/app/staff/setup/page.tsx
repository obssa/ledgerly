import { Suspense } from "react";
import { ApiError } from "@/lib/api";
import { staffApi } from "@/lib/staff";
import { StaffSetupForm } from "../StaffForms";

export const metadata = { title: "Set up your staff account" };

/** Opened from the operator's email: /staff/setup?token=… once, within 48 hours, from an allowed address. */
export default function StaffSetupPage({ searchParams }: PageProps<"/staff/setup">) {
  return (
    <div className="mx-auto max-w-[460px]">
      <h1 className="mb-4 text-[22px] font-extrabold tracking-tight">Set up your staff account</h1>
      <Suspense fallback={null}><Setup searchParams={searchParams} /></Suspense>
    </div>
  );
}

async function Setup({ searchParams }: Pick<PageProps<"/staff/setup">, "searchParams">) {
  const { token } = await searchParams;
  const broken = (text: string) => <p role="alert" className="rounded-lg bg-bad-tint px-3 py-2 text-[13px] font-semibold text-bad">{text}</p>;
  if (typeof token !== "string" || !/^[0-9a-f]{64}$/.test(token)) return broken("This link is not complete. Open the link from the email again.");
  let setup;
  try {
    setup = await staffApi.setup(token);
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) return broken("This setup link is not valid any more, or the console is not open from this address.");
    throw error;
  }
  return (
    <>
      <p className="mb-3 text-sm">For <strong>{setup.name}</strong> ({setup.email}). Add this key to your authenticator app, then choose a password and type the code it shows.</p>
      <p className="mb-1 text-[13px] text-muted">Key</p>
      <p className="num mb-1 break-all rounded-lg border border-line bg-[#f7f7f7] px-3 py-2 text-[15px] font-bold tracking-wider">{setup.secret}</p>
      <p className="mb-4 break-all text-[12px] text-muted">{setup.otpauth_uri}</p>
      <StaffSetupForm token={token} secret={setup.secret} />
    </>
  );
}
