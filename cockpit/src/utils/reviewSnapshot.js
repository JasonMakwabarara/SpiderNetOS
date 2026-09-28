// What an approver is looking at, kept as one value.
//
// The server binds an approval to the version of the payload the approver
// saw (approvals.version_hash) and refuses a decision that names another one.
// For that to mean anything the page must send the version of the content it
// actually displayed — so the displayed payload and its version travel
// together as one snapshot, captured when the review is shown and when a
// decision dialog opens. A background refresh replaces the list, never an
// open dialog's snapshot.
import { parseContext } from './format.js'

/**
 * The review an approval asks for, as shown to the approver.
 * `items` are the editable units: the steps of a sequence, or the drafts of
 * any other bundle. Returns null for approvals that bind no payload.
 */
export function reviewSnapshot(approval) {
  if (!approval) return null
  const ctx = parseContext(approval.context)
  const payload = ctx.payload
  if (!payload || typeof payload !== 'object') return null

  if (Array.isArray(payload.steps)) {
    return {
      approvalId: approval.id,
      version: approval.version_hash ?? null,
      kind: payload.kind,
      destination: payload.destination || null,
      items: payload.steps.map((step) => ({
        artifactId: step.artifact_id,
        label: `Step ${step.n}`,
        subject: step.subject ?? '',
        body: step.body ?? '',
        variants: Array.isArray(step.variants) ? step.variants : [],
        hasSubject: true,
      })),
    }
  }

  return {
    approvalId: approval.id,
    version: approval.version_hash ?? null,
    kind: payload.kind,
    destination: null,
    items: (payload.items || []).map((item) => {
      const [subject, body] = splitDraftEmail(item.content ?? '')
      return { artifactId: item.id, label: item.title || item.kind, subject, body, variants: [], hasSubject: subject !== '' }
    }),
  }
}

/** The backend's draft-email text form: "Subject: X\n\nbody", or just the body. */
export function draftEmailContent(subject, body) {
  const s = String(subject ?? '').trim()
  return s !== '' ? `Subject: ${s}\n\n${body ?? ''}` : String(body ?? '')
}

/** Inverse of draftEmailContent — mirrors ArtifactApplier::splitDraftEmail. */
export function splitDraftEmail(content) {
  const m = /^Subject:\s*(.+?)\r?\n\r?\n([\s\S]*)$/.exec(String(content ?? '').trim())
  return m ? [m[1].trim(), m[2].trim()] : ['', String(content ?? '').trim()]
}
