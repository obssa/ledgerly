import { Card } from "@/components/ui";
import type { AttachmentKind } from "@/lib/api";
import { getAttachments } from "@/lib/data";
import { RemoveAttachment } from "./RemoveAttachment";

/** What happened to the last file sent, by the fixed flag the upload route opens the page with. */
const FLAG: Record<string, { tone: "good" | "bad"; text: string }> = {
  added: { tone: "good", text: "Attached." },
  none: { tone: "bad", text: "Choose the file to attach." },
  type: { tone: "bad", text: "Attach a PDF, PNG, JPEG or WebP file (a scan or a photo of the receipt)." },
  script: { tone: "bad", text: "That PDF carries scripts or embedded files and was not kept: print it to PDF again, or attach a photo." },
  large: { tone: "bad", text: "A file of 10 MB at most." },
  full: { tone: "bad", text: "A document keeps at most 20 attachments: remove one first." },
  busy: { tone: "bad", text: "Many files were attached in the last hour: try again later." },
  demo: { tone: "bad", text: "Not available in the demo: files are not stored for the shared demo accounts." },
  forbidden: { tone: "bad", text: "Your role cannot attach files here." },
};

const size = (n: number) => (n >= 1024 * 1024 ? `${(n / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`);

/**
 * SECURITY.md S7, Module 3: the files kept with an invoice, bill, expense, expense claim or journal entry (063, 086), each opened through the API after the
 * document's permission, and a plain form to attach one (PDF, PNG, JPEG or WebP, up to 10 MB).
 */
export async function AttachmentsPanel({ kind, documentId, flag, demo }: { kind: AttachmentKind; documentId: string; flag?: string; demo?: boolean }) {
  const list = await getAttachments(kind, documentId);
  if (list === null) return null;
  const notice = flag ? FLAG[flag] : undefined;

  return (
    <Card label="Attachments">
      <h2 className="text-[17px] font-bold">Attachments</h2>
      {notice && (
        <p role={notice.tone === "good" ? "status" : "alert"} className={`mt-2 rounded-lg px-3 py-2 text-[13px] font-semibold ${notice.tone === "good" ? "bg-good-tint text-good" : "bg-bad-tint text-bad"}`}>{notice.text}</p>
      )}
      {list.data.length === 0 ? <p className="mt-1.5 text-sm text-muted">No files yet.</p> : (
        <ul className="mt-2 grid gap-1.5 text-sm">
          {list.data.map((a) => (
            <li key={a.id} className="flex flex-wrap items-center justify-between gap-2">
              <a href={`/attachments/${encodeURIComponent(a.id)}`} className="font-semibold text-brand hover:underline">{a.file_name}</a>
              <span className="text-[12px] text-muted">{size(a.size_bytes)} · {a.added_by}</span>
              {list.can.add && !demo && <RemoveAttachment id={a.id} />}
            </li>
          ))}
        </ul>
      )}
      {list.can.add && !demo && (
        <form action="/attachments/upload" method="post" encType="multipart/form-data" className="mt-3 grid gap-2">
          <input type="hidden" name="kind" value={kind} />
          <input type="hidden" name="document_id" value={documentId} />
          <label className="field-label" htmlFor="attachment-file">Attach a file (PDF, PNG, JPEG or WebP, up to 10 MB)</label>
          <input className="field" id="attachment-file" name="file" type="file" accept="application/pdf,image/png,image/jpeg,image/webp" />
          <div><button type="submit" className="btn">Attach</button></div>
        </form>
      )}
    </Card>
  );
}
