"use client";

import NextLink from "next/link";
import { useState, type ComponentProps } from "react";

/**
 * next/link, prefetching a page only when the person shows they may open it: the pointer on the link, a finger on
 * it, or the keyboard on it. The whole app imports Link from here (eslint.config.mjs enforces it).
 *
 * next/link on its own prefetches every link that scrolls into view. A page here has dozens (the menu, buttons,
 * one per table row), so each page sent 14-16 requests in the same second. The host serves a few at once and starts
 * another Node.js process for the rest, which takes about 20 seconds on it: the prefetches waited, and the click
 * the person actually made waited behind them (measured 26 Nov 2026, docs/runbook/cpanel.md "Speed").
 *
 * Hover still gives a head start of a few hundred milliseconds, and the loading.tsx of each section shows at once.
 * A link that sets `prefetch` itself keeps its own choice.
 */
export default function Link({ prefetch, onMouseEnter, onTouchStart, onFocus, ...props }: ComponentProps<typeof NextLink>) {
  const [intent, setIntent] = useState(false);
  return (
    <NextLink
      {...props}
      prefetch={prefetch !== undefined ? prefetch : intent ? null : false}
      onMouseEnter={(e) => { setIntent(true); onMouseEnter?.(e); }}
      onTouchStart={(e) => { setIntent(true); onTouchStart?.(e); }}
      onFocus={(e) => { setIntent(true); onFocus?.(e); }}
    />
  );
}
