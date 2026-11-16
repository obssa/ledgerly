import { parse, toString } from "./decimal";

/* Module 5, petty cash: the notes and coins a cash box is counted in. The API adds them up again. */

/** Notes and coins in circulation, largest first. Ethiopian birr: 200, 100, 50, 10, 5 notes; 1 birr and cents coins. */
export const DENOMINATIONS: Record<string, string[]> = {
  ETB: ["200", "100", "50", "10", "5", "1", "0.50", "0.25", "0.10", "0.05"],
  KES: ["1000", "500", "200", "100", "50", "20", "10", "5", "1"],
  USD: ["100", "50", "20", "10", "5", "2", "1", "0.25", "0.10", "0.05", "0.01"],
};

/** The pieces of each value entered on the count form (fields "pieces:200" and so on); empty and zero are left out. */
export function denominationsFrom(form: FormData): Record<string, number> {
  const out: Record<string, number> = {};
  for (const [key, value] of form.entries()) {
    if (!key.startsWith("pieces:") || typeof value !== "string") continue;
    const pieces = value.trim() === "" ? 0 : Number(value.trim());
    if (!Number.isInteger(pieces) || pieces < 0) throw new Error(`Not a number of pieces: ${value}`);
    if (pieces > 0) out[key.slice(7)] = pieces;
  }
  return out;
}

/** What the pieces add up to, exactly. */
export function countTotal(pieces: Record<string, number | string>): string {
  let total = 0n;
  for (const [value, n] of Object.entries(pieces)) {
    const count = typeof n === "number" ? n : Number(String(n).trim() || "0");
    if (!Number.isInteger(count) || count <= 0) continue;
    total += parse(value) * BigInt(count);
  }
  return toString(total);
}
