# Fiscalisation

Live fiscalisation talks to the ZIMRA FDMS device API over mutual TLS with the device certificate. It runs only when `FDMS_LIVE` is true, the configured tenant matches, and the device certificate and key are readable. An operator syncs the device configuration, opens the fiscal day, fiscalises the tenant's own sent or paid sales invoices, and closes the day.

Each invoice becomes one signed receipt in `fdms_receipts`: `pending`, `accepted`, or `rejected`. A pending receipt is resent with the identical payload, a rejected one is rebuilt, and device counters advance only on acceptance. A day with pending receipts cannot be closed.

Supplier invoices are never sent to FDMS: the supplier fiscalises them. A sandbox submission can be recorded for a posted supplier invoice; any other environment is refused.

## Device registration

`php artisan fdms:register <activation key>` first shows the taxpayer that FDMS links to the device, then generates an ECDSA P-256 key on the server and sends a CSR named `ZIMRA-<serial>-<10-digit device id>`. It writes the key and the issued certificate to `FDMS_KEY_PATH` and `FDMS_CERT_PATH`, or to `storage/app/fdms` when those are unset. The serial comes from `--serial` or `FDMS_DEVICE_SERIAL_NO`. It refuses to overwrite an existing key without `--force`. If FDMS refuses the request, the unused key is deleted. If FDMS gives no answer, the key is kept, because the activation key works only once. `php artisan fdms:renew-certificate` gets a new certificate for the same key and keeps the old certificate as a `.bak` file.

## Discounts

An invoice discount is spread across the invoice lines in proportion to each line's tax-inclusive amount, so the shares add up to the exact discount. A discounted invoice is sent with tax-inclusive lines and one `Discount` line per tax. Tax is worked out on the amount after the discount. The receipt total always equals the invoice total.

## Sales credit notes

`POST /api/enterprise/sales-invoices/{id}/credit-notes` takes a reason and quantities of the invoice's own lines. It copies each line's price and tax and its share of any discount; the last credit on a line takes whatever discount share is left. Once the note is issued, `POST /api/enterprise/credit-notes/{id}/fiscalise` sends a `CreditNote` receipt that points at the original FDMS receipt. Its amounts and payments are negative and it updates the `CreditNoteByTax` and `CreditNoteTaxByTax` counters. The original invoice must already be fiscalised and no more than 12 months old, and credit notes together cannot exceed the original receipt total.
