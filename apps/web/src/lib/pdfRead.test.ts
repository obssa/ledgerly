import { describe, expect, it, vi } from "vitest";

vi.mock("server-only", () => ({}));
const { pdfStatementFrom, pdfText, rowsOf, statementTable, PdfStatementError } = await import("./pdfRead");

type Piece = [page: number, x: number, y: number, text: string];
/** Pieces of text as a PDF gives them, each 5 points wide per character. */
const pieces = (list: Piece[]) => list.map(([page, x, y, text]) => ({ page, x, y, w: text.length * 5, text }));

/** A one-font PDF written by hand: each page a list of [x, y, text] (Helvetica 9 pt). */
function pdf(pages: [number, number, string][][]): Uint8Array {
  const objs: string[] = ["<< /Type /Catalog /Pages 2 0 R >>", ""];
  const kids: string[] = [];
  const font = 3;
  objs.push("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>");
  for (const lines of pages) {
    const content = lines.map(([x, y, t]) => `BT /F1 9 Tf ${x} ${y} Td (${t}) Tj ET`).join("\n");
    objs.push(`<< /Length ${content.length} >>\nstream\n${content}\nendstream`);
    const contentId = objs.length;
    objs.push(`<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents ${contentId} 0 R /Resources << /Font << /F1 ${font} 0 R >> >> >>`);
    kids.push(`${objs.length} 0 R`);
  }
  objs[1] = `<< /Type /Pages /Kids [${kids.join(" ")}] /Count ${pages.length} >>`;
  let out = "%PDF-1.4\n";
  const offsets: number[] = [];
  objs.forEach((o, i) => { offsets.push(out.length); out += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const xref = out.length;
  out += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n${offsets.map((o) => `${String(o).padStart(10, "0")} 00000 n \n`).join("")}`;
  out += `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
  return new TextEncoder().encode(out);
}

const HEADER: [number, number, string][] = [[40, 700, "Date"], [110, 700, "Description"], [330, 700, "Debit"], [420, 700, "Credit"], [510, 700, "Balance"]];

describe("reading the statement table out of a PDF's text", () => {
  it("puts each figure under its column and joins a wrapped description to its line", () => {
    const table = statementTable(pieces([
      [1, 40, 760, "Commercial Bank - Statement of account"], [1, 40, 745, "Account 1000123456789"],
      [1, 40, 700, "Date"], [1, 110, 700, "Description"], [1, 330, 700, "Debit"], [1, 420, 700, "Credit"], [1, 510, 700, "Balance"],
      [1, 40, 680, "01/11/2026"], [1, 110, 680, "Opening balance"], [1, 510, 680, "15,000.00"],
      [1, 40, 665, "03/11/2026"], [1, 110, 665, "Transfer to Selam Traders"], [1, 335, 665, "2,500.00"], [1, 510, 665, "12,500.00"],
      [1, 110, 655, "REF FT26307ABC"],
      [1, 40, 640, "05/11/2026"], [1, 110, 640, "Deposit cash"], [1, 425, 640, "4,000.00"], [1, 510, 640, "16,500.00"],
      [1, 250, 400, "Page 1 of 1"],
    ]));
    expect(table).toEqual([
      ["Date", "Description", "Debit", "Credit", "Balance"],
      ["01/11/2026", "Opening balance", "", "", "15,000.00"],
      ["03/11/2026", "Transfer to Selam Traders REF FT26307ABC", "2,500.00", "", "12,500.00"],
      ["05/11/2026", "Deposit cash", "", "4,000.00", "16,500.00"],
    ]);
  });

  it("reads on over pages, skipping the header, letterhead and footer each page repeats", () => {
    // Each page: letterhead, a summary box with the statement's date and balances, the header, its lines, a footer.
    const page = (p: number, y0: number, lines: Piece[]): Piece[] => [
      [p, 40, 760, "Statement of account"], [p, 40, 730, "Statement date"], [p, 160, 730, "Closing balance"], [p, 40, 718, "2026-11-20"], [p, 160, 718, "10,879.50"],
      ...HEADER.map(([x, y, t]) => [p, x, y, t] as Piece), ...lines, [p, 250, y0, "Page " + p],
    ];
    const table = statementTable(pieces([
      ...page(1, 300, [[1, 40, 680, "2026-11-03"], [1, 110, 680, "Card POS ZEMEN MALL"], [1, 335, 680, "120.50"], [1, 510, 680, "1,879.50"]]),
      ...page(2, 300, [[2, 40, 680, "2026-11-04"], [2, 110, 680, "Salary November"], [2, 425, 680, "9,000.00"], [2, 510, 680, "10,879.50"]]),
    ]));
    expect(table.slice(1)).toEqual([
      ["2026-11-03", "Card POS ZEMEN MALL", "120.50", "", "1,879.50"],
      ["2026-11-04", "Salary November", "", "9,000.00", "10,879.50"],
    ]);
  });

  it("splits a line the PDF set as one piece with its columns padded by spaces", () => {
    const rows = rowsOf([{ page: 1, x: 40, y: 680, w: 300, text: "05 Nov 2026    Telebirr top-up    250.00" }]);
    expect(rows[0].cells.map((c) => c.text)).toEqual(["05 Nov 2026", "Telebirr top-up", "250.00"]);
  });

  it("does not take a total or a lone closing balance for part of the last line", () => {
    const table = statementTable(pieces([
      ...HEADER.map(([x, y, t]) => [1, x, y, t] as Piece),
      [1, 40, 680, "05/11/2026"], [1, 110, 680, "Service charge"], [1, 335, 680, "15.00"], [1, 510, 680, "985.00"],
      [1, 110, 667, "Total"], [1, 335, 667, "15.00"],
    ]));
    expect(table.slice(1)).toEqual([["05/11/2026", "Service charge", "15.00", "", "985.00"]]);
  });

  it("refuses a PDF without a table of transactions", () => {
    expect(() => statementTable(pieces([[1, 40, 700, "Dear customer, your statement is attached."]]))).toThrow(PdfStatementError);
    expect(() => statementTable(pieces(HEADER.map(([x, y, t]) => [1, x, y, t] as Piece)))).toThrow(/no dated lines/);
  });
});

describe("a real PDF file", () => {
  it("is read, text and places, page by page", async () => {
    const items = await pdfText(pdf([[...HEADER, [40, 680, "03/11/2026"], [110, 680, "Transfer to Selam Traders"], [330, 680, "2,500.00"], [510, 680, "12,500.00"]]]));
    expect(statementTable(items)).toEqual([
      ["Date", "Description", "Debit", "Credit", "Balance"],
      ["03/11/2026", "Transfer to Selam Traders", "2,500.00", "", "12,500.00"],
    ]);
  });

  it("from the form: a PDF becomes CSV; anything else is left to the other readers; a PDF without text is refused", async () => {
    const form = (bytes: Uint8Array, name: string) => {
      const f = new FormData();
      f.set("file", new File([bytes as Uint8Array<ArrayBuffer>], name));
      return f;
    };
    const statement = pdf([[...HEADER, [40, 680, "05/11/2026"], [110, 680, "Deposit, cash"], [420, 680, "4,000.00"], [510, 680, "16,500.00"]]]);
    expect(await pdfStatementFrom(form(statement, "november.pdf"))).toEqual({
      csv: 'Date,Description,Debit,Credit,Balance\n05/11/2026,"Deposit, cash",,"4,000.00","16,500.00"', fileName: "november.pdf",
    });
    expect(await pdfStatementFrom(form(new TextEncoder().encode("Date,Amount\n2026-11-05,10"), "a.csv"))).toBeNull();
    expect(await pdfStatementFrom(form(pdf([[]]), "scan.pdf"))).toEqual({ error: expect.stringMatching(/no text in it/) });
    expect(await pdfStatementFrom(form(new TextEncoder().encode("%PDF-1.4\nnot really"), "broken.pdf"))).toEqual({ error: expect.stringMatching(/cannot be read/) });
  });
});
