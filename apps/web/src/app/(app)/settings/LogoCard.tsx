import { Card } from "@/components/ui";

const FLAGS: Record<string, { tone: "good" | "bad"; text: string }> = {
  added: { tone: "good", text: "Logo saved. It is on your invoices and quotes now." },
  removed: { tone: "good", text: "Logo removed." },
  none: { tone: "bad", text: "Choose the logo file first." },
  type: { tone: "bad", text: "A logo is a PNG or JPEG image." },
  large: { tone: "bad", text: "A logo of 256 KB at most: save it smaller and try again." },
  forbidden: { tone: "bad", text: "Only an owner or admin changes the logo." },
  demo: { tone: "bad", text: "The demo keeps its own look: logos are not changed here." },
};

/**
 * Module 1 (100): the company's logo on its invoices and quotes (the page, its print view, what the customer opens from
 * a link, and the PDF where the server can draw images). Plain forms, so the file goes as it is.
 */
export function LogoCard({ hasLogo, manage, flag, demo }: { hasLogo: boolean; manage: boolean; flag?: string; demo?: boolean }) {
  const shown = flag ? FLAGS[flag] : undefined;
  return (
    <Card label="Logo">
      <h2 className="text-[19px] font-bold">Logo</h2>
      <p className="mt-1.5 text-sm text-muted">On your invoices and quotes, and on what customers open from a link. A PNG or JPEG of up to 256 KB; a wide one fits best.</p>
      {shown && <p role={shown.tone === "bad" ? "alert" : "status"} className={`mt-3 rounded-lg px-3 py-2 text-[13px] font-semibold ${shown.tone === "bad" ? "bg-bad-tint text-bad" : "bg-good-tint text-good"}`}>{shown.text}</p>}
      {/* eslint-disable-next-line @next/next/no-img-element -- the company's own image, served by this site */}
      {hasLogo ? <img src="/company-logo" alt="The company's logo" className="mt-3 max-h-[64px] max-w-[220px] object-contain" /> : <p className="mt-3 text-sm">No logo yet.</p>}
      {manage && !demo && (
        <div className="mt-3 grid gap-2">
          <form action="/company-logo" method="post" encType="multipart/form-data" className="grid gap-2">
            <label className="field-label" htmlFor="logo-file">{hasLogo ? "A new logo" : "Your logo"}</label>
            <input className="field" id="logo-file" name="file" type="file" accept="image/png,image/jpeg" />
            <div><button type="submit" className="btn">Save the logo</button></div>
          </form>
          {hasLogo && <form action="/company-logo/remove" method="post"><button type="submit" className="btn">Remove it</button></form>}
        </div>
      )}
    </Card>
  );
}
