"use client";

/**
 * Page translation (Chrome's "Translate this page", common on phones) swaps the page's text for its own elements
 * behind React's back. When React later removes or moves a piece of text that is no longer where it left it, the
 * browser throws (NotFoundError: removeChild / insertBefore) and the whole app falls to its error page. This
 * makes those two calls tolerate a node that has already been moved, which is the known remedy
 * (facebook/react#11538); everything else is unchanged.
 */
if (typeof window !== "undefined" && typeof Node === "function" && !(window as { __ledgerlyDomGuard?: boolean }).__ledgerlyDomGuard) {
  (window as { __ledgerlyDomGuard?: boolean }).__ledgerlyDomGuard = true;
  const removeChild = Node.prototype.removeChild;
  Node.prototype.removeChild = function <T extends Node>(this: Node, child: T): T {
    if (child.parentNode !== this) return child;
    return removeChild.call(this, child) as T;
  };
  const insertBefore = Node.prototype.insertBefore;
  Node.prototype.insertBefore = function <T extends Node>(this: Node, node: T, reference: Node | null): T {
    if (reference && reference.parentNode !== this) return node;
    return insertBefore.call(this, node, reference) as T;
  };
}

export function DomGuard() {
  return null;
}
