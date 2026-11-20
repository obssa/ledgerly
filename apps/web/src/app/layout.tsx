import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: { default: "Ledgerly", template: "%s · Ledgerly" },
  description: "Accounting for African businesses: invoicing, banking, mobile money, VAT and e-invoicing.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" className="h-full antialiased">
      <head>
        {/* Loaded at runtime so builds work offline; swap for next/font when the build has network access. */}
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
        {/* eslint-disable-next-line @next/next/no-page-custom-font -- App Router root layout: applies to every page */}
        <link
          rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap"
        />
      </head>
      <body
        className="min-h-full font-sans"
        style={{ ["--font-inter" as string]: "'Inter'", ["--font-plex-mono" as string]: "'IBM Plex Mono'" }}
      >
        {children}
      </body>
    </html>
  );
}
