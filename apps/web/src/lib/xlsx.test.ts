import { inflateRawSync } from "node:zlib";
import { describe, expect, it, vi } from "vitest";

vi.mock("server-only", () => ({}));
const { columnName, crc32, sheetXml, toXlsx } = await import("./xlsx");

/** Reads a ZIP back (the central directory), so the test checks the file a spreadsheet would open. */
function unzip(file: Uint8Array): Record<string, string> {
  const b = Buffer.from(file);
  const end = b.lastIndexOf(Buffer.from([0x50, 0x4b, 0x05, 0x06]));
  const count = b.readUInt16LE(end + 10);
  let at = b.readUInt32LE(end + 16);
  const out: Record<string, string> = {};
  for (let i = 0; i < count; i++) {
    expect(b.readUInt32LE(at)).toBe(0x02014b50);
    const size = b.readUInt32LE(at + 20);
    const nameLength = b.readUInt16LE(at + 28);
    const local = b.readUInt32LE(at + 42);
    const name = b.subarray(at + 46, at + 46 + nameLength).toString("utf8");
    const start = local + 30 + b.readUInt16LE(local + 26) + b.readUInt16LE(local + 28);
    const data = inflateRawSync(b.subarray(start, start + size));
    expect(crc32(data)).toBe(b.readUInt32LE(at + 16));
    out[name] = data.toString("utf8");
    at += 46 + nameLength;
  }
  return out;
}

describe("xlsx", () => {
  it("names columns as Excel does", () => {
    expect([0, 25, 26, 27, 701, 702].map(columnName)).toEqual(["A", "Z", "AA", "AB", "ZZ", "AAA"]);
  });

  it("knows the standard CRC-32", () => {
    expect(crc32(Buffer.from("123456789"))).toBe(0xcbf43926);
  });

  it("writes a workbook with one sheet: headings bold, amounts as numbers, text never a formula", () => {
    const files = unzip(toXlsx("Balance sheet", [["Account", "Amount"], ["Cash", "1250.50"], ["=HYPERLINK(\"x\")", "-3"], ["ቡና & Co", null]]));
    expect(Object.keys(files).sort()).toEqual(["[Content_Types].xml", "_rels/.rels", "xl/_rels/workbook.xml.rels", "xl/styles.xml", "xl/workbook.xml", "xl/worksheets/sheet1.xml"]);
    expect(files["xl/workbook.xml"]).toContain('<sheet name="Balance sheet"');
    const sheet = files["xl/worksheets/sheet1.xml"];
    expect(sheet).toContain('<c r="A1" t="inlineStr" s="1"><is><t xml:space="preserve">Account</t></is></c>');
    expect(sheet).toContain('<c r="B2" s="2"><v>1250.50</v></c>');
    expect(sheet).toContain('<c r="B3"><v>-3</v></c>');
    expect(sheet).toContain("=HYPERLINK(&quot;x&quot;)</t>");
    expect(sheet).not.toContain("<f>");
    expect(sheet).toContain("ቡና &amp; Co");
  });

  it("drops characters XML does not allow and keeps a sheet name Excel accepts", () => {
    expect(sheetXml([["a\u0001b"]])).toContain(">ab</t>");
    const files = unzip(toXlsx("Profit/loss: a very long report name over the limit", [["x"]]));
    expect(files["xl/workbook.xml"]).toContain('<sheet name="Profit loss  a very long report"');
  });
});
