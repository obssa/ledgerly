/**
 * Evenbooks can be used in any country. Countries with their own pack (tax rates, tax rules, e-invoicing)
 * are listed first; every other country uses the general setup, where the company picks its currency and
 * enters its own tax rates.
 */
export const LOCALISED_COUNTRIES = ["ET"] as const; // launch country. Kenya's pack exists in the API but is not reviewed yet.

const ISO_3166 =
  "AD AE AF AG AI AL AM AO AR AS AT AU AW AZ BA BB BD BE BF BG BH BI BJ BM BN BO BR BS BT BW BY BZ CA CD CF CG CH CI CK CL CM CN CO CR CU CV CW CY CZ " +
  "DE DJ DK DM DO DZ EC EE EG ER ES ET FI FJ FM FR GA GB GD GE GH GI GM GN GQ GR GT GU GW GY HK HN HR HT HU ID IE IL IN IQ IR IS IT JM JO JP " +
  "KE KG KH KI KM KN KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MG MH MK ML MM MN MO MR MT MU MV MW MX MY MZ NA NE NG NI NL NO NP NR NZ " +
  "OM PA PE PG PH PK PL PR PS PT PW PY QA RO RS RU RW SA SB SC SD SE SG SI SK SL SM SN SO SR SS ST SV SY SZ TD TG TH TJ TL TM TN TO TR TT TV TW TZ " +
  "UA UG US UY UZ VC VE VN VU WS YE ZA ZM ZW";

const names = new Intl.DisplayNames(["en"], { type: "region" });

export interface Country {
  code: string;
  name: string;
  localised: boolean;
}

export const COUNTRIES: Country[] = ISO_3166.split(" ")
  .map((code) => ({ code, name: names.of(code) ?? code, localised: (LOCALISED_COUNTRIES as readonly string[]).includes(code) }))
  .sort((a, b) => Number(b.localised) - Number(a.localised) || a.name.localeCompare(b.name));

/** Industry templates: keys match database/country-packs/_industries.json in the API. */
export const INDUSTRIES: { key: string; name: string }[] = [
  { key: "retail_wholesale", name: "Retail and wholesale" },
  { key: "services", name: "Professional and other services" },
  { key: "restaurant_hospitality", name: "Restaurant, cafe and hotel" },
  { key: "manufacturing", name: "Manufacturing" },
  { key: "construction", name: "Construction and contracting" },
  { key: "import_export", name: "Import and export" },
  { key: "transport_logistics", name: "Transport and logistics" },
  { key: "agriculture", name: "Agriculture and agro-processing" },
  { key: "healthcare", name: "Clinic, pharmacy and healthcare" },
  { key: "education", name: "School and training" },
  { key: "nonprofit", name: "Non-profit and NGO" },
  { key: "real_estate", name: "Real estate and rental" },
  { key: "other", name: "Other" },
];
