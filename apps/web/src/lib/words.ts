/* Amounts in words for cheques, in English (the product's only language): "One thousand two hundred and fifty birr and
   50 cents only". Works on the decimal string, never on floats. */

const ONES = ["zero", "one", "two", "three", "four", "five", "six", "seven", "eight", "nine", "ten", "eleven", "twelve", "thirteen",
  "fourteen", "fifteen", "sixteen", "seventeen", "eighteen", "nineteen"];
const TENS = ["", "", "twenty", "thirty", "forty", "fifty", "sixty", "seventy", "eighty", "ninety"];
const SCALES = ["", "thousand", "million", "billion", "trillion", "quadrillion"];

/** What the currency's units and hundredths are called on a cheque. */
const UNITS: Record<string, [string, string]> = { ETB: ["birr", "cents"], USD: ["US dollars", "cents"], EUR: ["euros", "cents"], KES: ["Kenyan shillings", "cents"], GBP: ["pounds sterling", "pence"] };

function underThousand(n: number): string {
  const parts: string[] = [];
  const hundreds = Math.floor(n / 100);
  const rest = n % 100;
  if (hundreds > 0) parts.push(`${ONES[hundreds]} hundred`);
  if (rest > 0) {
    const words = rest < 20 ? ONES[rest] : `${TENS[Math.floor(rest / 10)]}${rest % 10 ? `-${ONES[rest % 10]}` : ""}`;
    parts.push(hundreds > 0 ? `and ${words}` : words);
  }
  return parts.join(" ");
}

/** A whole number written out, from its digits. */
export function numberInWords(digits: string): string {
  const clean = digits.replace(/^0+(?=\d)/, "");
  if (!/^\d+$/.test(clean)) throw new Error(`Not a whole number: ${digits}`);
  if (clean === "0") return "zero";
  const groups: number[] = [];
  for (let end = clean.length; end > 0; end -= 3) groups.unshift(Number(clean.slice(Math.max(0, end - 3), end)));
  if (groups.length > SCALES.length) throw new Error("Too large for a cheque");
  const words: string[] = [];
  groups.forEach((g, i) => {
    if (g === 0) return;
    const scale = SCALES[groups.length - 1 - i];
    const isLast = i === groups.length - 1;
    // "one thousand and five": a last group below 100 after larger groups takes "and".
    const text = isLast && groups.length > 1 && g < 100 ? `and ${underThousand(g)}` : underThousand(g);
    words.push(scale ? `${text} ${scale}` : text);
  });
  return words.join(" ");
}

/** "1250.50", "ETB" -> "One thousand two hundred and fifty birr and 50 cents only". */
export function amountInWords(amount: string, currency: string): string {
  const [whole, cents = "00"] = amount.trim().replace(/[,\s]/g, "").split(".");
  if (whole.startsWith("-")) throw new Error("A cheque is for a positive amount");
  const [unit, sub] = UNITS[currency] ?? [currency, "cents"];
  const c = (cents + "00").slice(0, 2);
  const text = `${numberInWords(whole)} ${unit}${c !== "00" ? ` and ${c} ${sub}` : ""} only`;
  return text.charAt(0).toUpperCase() + text.slice(1);
}
