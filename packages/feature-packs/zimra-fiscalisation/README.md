# Fiscalisation

Invoice fiscal state is `none`, `pending`, `fiscalised`, or `failed`. Each attempt is a `fiscal_submissions` row: `pending`, `accepted`, or `failed`. Verification codes stay on the submission, not on the invoice.

The default driver is sandbox. Codes look like `SANDBOX-…` and `is_live` is false. Live FDMS runs only when the environment driver is `fdms`, the tenant has fiscalisation enabled, a TIN is present, VAT configuration is valid when the tenant is VAT-registered, the device is active, and device credentials are present. This pack does not perform a live ZIMRA HTTP call. Later operations to map are open fiscal day, submit invoice, credit note, and close day.
