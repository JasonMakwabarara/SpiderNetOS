# E-Requisition

Integrates with People when a requester employee is supplied. `approved` means approved for procurement, not completed.

Submit is allowed only from `draft`. A second submit returns 409. Approval and rejection are written only when `ApprovalEngine` resolves the approval.
