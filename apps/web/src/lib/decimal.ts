/**
 * Exact decimal maths for money in the browser, mirroring the API's Money/TaxCalculator
 * (apps/api/app/Domain). Product rule: every amount has exactly 2 decimal places. Amounts are bigint
 * hundredths — never JS floats — so a total previewed in the invoice form always equals the API's.
 * Rates (tax, discount) are parsed at 6 decimals so 7.5% stays exact.
 */

export const SCALE = 2;
const FACTOR = 10n ** BigInt(SCALE);
const RATE_SCALE = 6;
const RATE_FACTOR = 10n ** BigInt(RATE_SCALE);

/**
 * Every active ISO 4217 currency (same list as the API's Currency class). All amounts use 2 decimal places,
 * whatever the currency.
 */
export const CURRENCIES = [
  "AED", "AFN", "ALL", "AMD", "AOA", "ARS", "AUD", "AWG", "AZN", "BAM", "BBD", "BDT", "BGN", "BHD", "BIF", "BMD",
  "BND", "BOB", "BRL", "BSD", "BTN", "BWP", "BYN", "BZD", "CAD", "CDF", "CHF", "CLP", "CNY", "COP", "CRC", "CUP",
  "CVE", "CZK", "DJF", "DKK", "DOP", "DZD", "EGP", "ERN", "ETB", "EUR", "FJD", "FKP", "GBP", "GEL", "GHS", "GIP",
  "GMD", "GNF", "GTQ", "GYD", "HKD", "HNL", "HTG", "HUF", "IDR", "ILS", "INR", "IQD", "IRR", "ISK", "JMD", "JOD",
  "JPY", "KES", "KGS", "KHR", "KMF", "KRW", "KWD", "KYD", "KZT", "LAK", "LBP", "LKR", "LRD", "LSL", "LYD", "MAD",
  "MDL", "MGA", "MKD", "MMK", "MNT", "MOP", "MRU", "MUR", "MVR", "MWK", "MXN", "MYR", "MZN", "NAD", "NGN", "NIO",
  "NOK", "NPR", "NZD", "OMR", "PAB", "PEN", "PGK", "PHP", "PKR", "PLN", "PYG", "QAR", "RON", "RSD", "RUB", "RWF",
  "SAR", "SBD", "SCR", "SDG", "SEK", "SGD", "SHP", "SLE", "SOS", "SRD", "SSP", "STN", "SYP", "SZL", "THB", "TJS",
  "TMT", "TND", "TOP", "TRY", "TTD", "TWD", "TZS", "UAH", "UGX", "USD", "UYU", "UZS", "VES", "VND", "VUV", "WST",
  "XAF", "XCD", "XOF", "XPF", "YER", "ZAR", "ZMW", "ZWG",
] as const;

export type Scaled = bigint; // amount × 100

function parseAt(value: string | number, scale: number): bigint {
  const s = String(value).trim();
  if (!/^-?\d+(\.\d+)?$/.test(s)) throw new Error(`Not a decimal: ${s}`);
  const negative = s.startsWith("-");
  const [whole, frac = ""] = s.replace("-", "").split(".");
  const digits = (frac + "0".repeat(scale)).slice(0, scale);
  let scaled = BigInt(whole) * 10n ** BigInt(scale) + BigInt(digits || "0");
  // Round half-up on the first digit beyond the scale.
  if (frac.length > scale && Number(frac[scale]) >= 5) scaled += 1n;
  return negative ? -scaled : scaled;
}

/** Parse an amount, price or quantity to 2 decimals (half-up). */
export function parse(value: string | number): Scaled {
  return parseAt(value, SCALE);
}

/** Parse a tax or discount rate (0..1) at 6 decimals. */
export function parseRate(value: string | number): bigint {
  return parseAt(value, RATE_SCALE);
}

export function toString(value: Scaled): string {
  const negative = value < 0n;
  const abs = negative ? -value : value;
  const whole = abs / FACTOR;
  const frac = (abs % FACTOR).toString().padStart(SCALE, "0");
  return `${negative ? "-" : ""}${whole}.${frac}`;
}

/** Kept for call sites: amounts are already at 2 decimals for every currency. */
// eslint-disable-next-line @typescript-eslint/no-unused-vars -- currency kept in the signature for call sites
export function roundToCurrency(value: Scaled, _currency?: string): Scaled {
  return value;
}

function divRound(numerator: bigint, denominator: bigint): bigint {
  const negative = (numerator < 0n) !== (denominator < 0n);
  const n = numerator < 0n ? -numerator : numerator;
  const d = denominator < 0n ? -denominator : denominator;
  let q = n / d;
  if ((n % d) * 2n >= d) q += 1n;
  return negative ? -q : q;
}

/** An amount per unit, to 2 decimals (half-up): value 450.00 over 3 units is 150.00. null for no units. */
export function perUnit(amount: string, quantity: string): string | null {
  const q = parse(quantity);
  if (q === 0n) return null;
  return toString(divRound(parse(amount) * FACTOR, q));
}

/** A change as a percentage of the earlier figure, to one decimal: "+12.5%", "−3.0%"; a dash with nothing to compare. */
export function percentChange(current: string, previous: string | null): string {
  if (previous === null) return "—";
  const p = parse(previous);
  if (p === 0n) return "—";
  const tenths = divRound((parse(current) - p) * 1000n, p < 0n ? -p : p);
  const abs = tenths < 0n ? -tenths : tenths;
  return `${tenths >= 0n ? "+" : "−"}${abs / 10n}.${abs % 10n}%`;
}

export interface LineInput {
  quantity: string;
  unitPrice: string;
  discountRate?: string; // 0..1
  taxRate?: string; // 0..1
}

export interface Amounts {
  subtotal: Scaled;
  tax: Scaled;
  total: Scaled;
}

/** Same rules as TaxCalculator::line(): round each line to 2 decimals, then sum. */
// eslint-disable-next-line @typescript-eslint/no-unused-vars -- currency kept in the signature for call sites
export function lineAmounts(line: LineInput, _currency?: string, inclusive = false): Amounts {
  const qty = parse(line.quantity);
  const price = parse(line.unitPrice);
  const keep = RATE_FACTOR - parseRate(line.discountRate ?? "0");
  const rate = parseRate(line.taxRate ?? "0");

  // price × quantity × (1 − discount), exact, then one rounding to 2 decimals
  const gross = divRound(price * qty * keep, FACTOR * RATE_FACTOR);

  if (inclusive) {
    const subtotal = divRound(gross * RATE_FACTOR, RATE_FACTOR + rate);
    return { subtotal, tax: gross - subtotal, total: gross };
  }
  const tax = divRound(gross * rate, RATE_FACTOR);
  return { subtotal: gross, tax, total: gross + tax };
}

/** A discount on the whole document (113): a percentage of the subtotal, or an amount. */
export interface DocumentDiscount { type: "percent" | "amount"; value: string }

/**
 * QuickBooks' discount before tax, as the API gives it (TaxCalculator::discount): shared out over the positive lines in
 * proportion, the last one taking the rounding, each line's tax worked out again on what is left. `subtotal` is before
 * the discount; `total` is what is charged.
 */
export function discountedAmounts(lines: LineInput[], currency?: string, inclusive = false, discount?: DocumentDiscount | null): Amounts & { discount: Scaled; taxable: Scaled } {
  const each = lines.map((l) => lineAmounts(l, currency, inclusive));
  const subtotal = each.reduce((t, a) => t + a.subtotal, 0n);
  const base = each.reduce((t, a) => (a.subtotal > 0n ? t + a.subtotal : t), 0n);
  let wanted = 0n;
  if (discount && base > 0n && /^\d+(\.\d+)?$/.test(discount.value.trim())) {
    wanted = discount.type === "percent" ? divRound(base * parseRate(discount.value), RATE_FACTOR * 100n) : parse(discount.value);
  }
  const off = wanted > base ? base : wanted;
  if (off === 0n) {
    const tax = each.reduce((t, a) => t + a.tax, 0n);
    return { subtotal, discount: 0n, taxable: subtotal, tax, total: subtotal + tax };
  }
  const positive = each.map((a, i) => (a.subtotal > 0n ? i : -1)).filter((i) => i >= 0);
  const last = positive[positive.length - 1];
  let given = 0n;
  let tax = 0n;
  each.forEach((a, i) => {
    const share = !positive.includes(i) ? 0n : i === last ? off - given : divRound(off * a.subtotal, base);
    given += share;
    tax += divRound((a.subtotal - share) * parseRate(lines[i].taxRate ?? "0"), RATE_FACTOR);
  });
  return { subtotal, discount: off, taxable: subtotal - off, tax, total: subtotal - off + tax };
}

export function documentAmounts(lines: LineInput[], currency?: string, inclusive = false): Amounts {
  return lines.reduce<Amounts>(
    (sum, l) => {
      const a = lineAmounts(l, currency, inclusive);
      return { subtotal: sum.subtotal + a.subtotal, tax: sum.tax + a.tax, total: sum.total + a.total };
    },
    { subtotal: 0n, tax: 0n, total: 0n },
  );
}
