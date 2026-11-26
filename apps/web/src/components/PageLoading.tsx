/**
 * While a page loads: the shape of a page, not a word.
 *
 * Every folder of the app with pages below it has a loading.tsx that shows this. Without one, a click on a link
 * left the old page on screen, apparently doing nothing, until the server had built the whole next page. With one,
 * Next.js swaps this in at once (it is fetched ahead with the link) and streams the page into its place.
 */
export default function PageLoading() {
  return (
    <div aria-busy="true" aria-label="Loading" className="mt-2 animate-pulse">
      <div className="h-7 w-64 rounded-lg bg-line-soft" />
      <div className="mt-3 h-4 w-96 max-w-full rounded bg-line-soft" />
      <div className="mt-6 grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-4">
        {[0, 1, 2, 3].map((i) => <div key={i} className="h-[112px] rounded-[14px] border border-line bg-surface" />)}
      </div>
      <div className="mt-4 h-72 rounded-[14px] border border-line bg-surface" />
    </div>
  );
}
