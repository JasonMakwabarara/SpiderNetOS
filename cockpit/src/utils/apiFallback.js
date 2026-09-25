/**
 * Shared helpers for the "render from fixtures when the API is down"
 * contract the Brain / Skills / Runs / Today stores follow.
 *
 * Every store keeps an `error` string (rendered inline by the view) and a
 * `fallback` flag; when a GET fails the store fills its state from the
 * shipped fixture so the surface still renders end-to-end. Mutations never
 * fall back — they return `{ success: false, error }` like the other stores.
 */

export function describeError(err, fallback = 'Request failed') {
  const status = err?.response?.status
  const message = err?.response?.data?.message || err?.message || fallback
  return status ? `${message} (HTTP ${status})` : message
}

export function fallbackNotice(err, what) {
  return `Couldn't load ${what} — ${describeError(err)}. Showing sample data.`
}

/** Deep-clone a fixture so store mutations never leak into the module cache. */
export function clone(value) {
  return value == null ? value : JSON.parse(JSON.stringify(value))
}

/** Drop empty query params before they hit the wire. */
export function cleanParams(params = {}) {
  return Object.fromEntries(
    Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined),
  )
}
