# SpiderNetOS - Operations Runbook

## Deployment
1. Clone: git clone https://github.com/JasonMakwabarara/SpiderNetOS.git
2. Backend: cd backend && composer install && php artisan migrate
3. Frontend: cd cockpit && npm install && npm run build
4. Start: php artisan serve

## Verification
Run: powershell -ExecutionPolicy Bypass -File smoke-test.ps1

## Rollback
- Revert code: git reset --hard <previous-commit>
- Rollback migrations: php artisan migrate:rollback
- Clear cache: php artisan optimize:clear

## Common Issues
- 500 errors: Check storage/logs/laravel.log
- Login fails: Check APP_KEY and database connection
- Email fails: Check MAIL_* settings in .env
