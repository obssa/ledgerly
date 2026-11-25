import { describe, expect, it } from "vitest";
import { clientAddress } from "./clientAddress";

const from = (h: Record<string, string>) => (name: string) => h[name];

describe("clientAddress without Cloudflare", () => {
  it("takes the address our own web server added last, not the one the visitor wrote first", () => {
    expect(clientAddress(from({ "x-forwarded-for": "198.51.100.77, 102.213.68.32" }), false)).toBe("102.213.68.32");
    expect(clientAddress(from({ "x-forwarded-for": "1.1.1.1,2.2.2.2 , 102.213.68.32 " }), false)).toBe("102.213.68.32");
  });

  it("takes the only address when the visitor sent none", () => {
    expect(clientAddress(from({ "x-forwarded-for": "102.213.68.32" }), false)).toBe("102.213.68.32");
  });

  it("ignores CF-Connecting-IP, which the visitor wrote when there is no Cloudflare in front", () => {
    expect(clientAddress(from({ "cf-connecting-ip": "198.51.100.77", "x-forwarded-for": "102.213.68.32" }), false)).toBe("102.213.68.32");
    expect(clientAddress(from({ "cf-connecting-ip": "198.51.100.77" }), false)).toBeUndefined();
  });

  it("gives nothing when there is no address", () => {
    expect(clientAddress(from({}), false)).toBeUndefined();
    expect(clientAddress(from({ "x-forwarded-for": " , " }), false)).toBeUndefined();
  });
});

describe("clientAddress behind Cloudflare", () => {
  it("takes Cloudflare's header and nothing else", () => {
    expect(clientAddress(from({ "cf-connecting-ip": "102.213.68.32", "x-forwarded-for": "198.51.100.77, 172.70.1.1" }), true)).toBe("102.213.68.32");
    expect(clientAddress(from({ "x-forwarded-for": "198.51.100.77" }), true)).toBeUndefined();
  });
});
