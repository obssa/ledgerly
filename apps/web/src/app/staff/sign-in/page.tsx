import { StaffSignInForm } from "../StaffForms";

export const metadata = { title: "Staff sign in" };

/** Staff sign in with their own account: password and an authenticator code, from an allowed address only. */
export default function StaffSignInPage() {
  return (
    <div className="mx-auto max-w-[380px]">
      <h1 className="mb-4 text-[22px] font-extrabold tracking-tight">Staff sign in</h1>
      <StaffSignInForm />
    </div>
  );
}
