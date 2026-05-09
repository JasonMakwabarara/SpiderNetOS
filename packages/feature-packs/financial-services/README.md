# Financial Services Feature Pack

Comprehensive financial operating system for SpiderNetOS.

## Features

- **Double-Entry Ledger**: Immutable, event-sourced financial records
- **Invoicing**: Create, send, and track invoices with line items
- **Payment Processing**: Stripe integration with webhook support
- **Wallet Management**: Multi-currency fiat and crypto wallets
- **Portfolio Management**: Track investments, execute trades
- **Financial Reports**: P&L, Balance Sheet, Cash Flow statements
- **Budget Tracking**: Monitor spending with alerts
- **Fraud Detection**: AI-powered transaction monitoring
- **Customer Management**: CRM-lite for financial customers

## Installation

```bash
cd backend
php artisan migrate
```

Then install the feature pack through the SpiderNetOS admin panel.

## API Endpoints

All endpoints require authentication and are tenant-scoped:

- `GET /api/financial/ledger` - View ledger entries
- `POST /api/financial/ledger/journal-entry` - Create journal entry
- `GET /api/financial/invoices` - List invoices
- `POST /api/financial/invoices` - Create invoice
- `GET /api/financial/payments` - List payments
- `POST /api/financial/payments` - Record payment
- `GET /api/financial/dashboard` - Financial dashboard metrics
- `POST /api/financial/reports/generate` - Generate financial report
- `GET /api/financial/wallets` - List wallets
- `GET /api/financial/budgets` - List budgets
- `GET /api/financial/portfolios` - List portfolios
- `POST /api/financial/portfolios/{id}/trades` - Execute trade
