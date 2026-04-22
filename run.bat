@echo off
echo ========================================
echo   SpiderNet OS v3.2 -- Starting...
echo ========================================

REM Copy .env if not exists
if not exist .env (
    copy .env.example .env
    echo [init] Created .env from .env.example
)

REM Build and start all planes
docker compose up --build -d

echo.
echo Waiting for services to be healthy...
timeout /t 5 /nobreak >nul

REM Run Laravel migrations
echo [migrate] Running database migrations...
docker compose exec api php artisan migrate --force 2>nul || echo [migrate] Skipped (API not ready yet)

echo.
echo ========================================
echo   SpiderNet OS is running!
echo ========================================
echo   API:        http://localhost:8000
echo   Cockpit:    http://localhost:5173
echo   Inference:  http://localhost:9000
echo   WebSocket:  ws://localhost:6001
echo   PostgreSQL: localhost:5432
echo   Redis:      localhost:6379
echo ========================================
