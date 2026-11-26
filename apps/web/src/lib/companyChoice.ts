/**
 * Which company a signed-in person works in when none is chosen (just signed in, or the choice was lost), and where
 * they go next. The first company still in use; an archived one only when there is nothing else (it can still be
 * read and exported); company setup only for a person with no company at all.
 */
export function chooseCompany(companies: { id: string; status?: string | null }[]): { companyId?: string; to: string } {
  const pick = companies.find((c) => c.status !== "archived") ?? companies[0];
  return pick ? { companyId: pick.id, to: "/" } : { to: "/onboarding" };
}

