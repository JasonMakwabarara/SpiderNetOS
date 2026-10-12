"""
SpiderNet OS - Services API
Hosts the Hermes integration routes. Run from the repository root:

    uvicorn services.api.main:app --port 8090
"""

from contextlib import asynccontextmanager

from fastapi import FastAPI

from services.api.routes.hermes_integration import router as hermes_router
from services.shared.hermes_bridge import start_hermes_bridge, stop_hermes_bridge


@asynccontextmanager
async def lifespan(app: FastAPI):
    await start_hermes_bridge()
    try:
        yield
    finally:
        await stop_hermes_bridge()


app = FastAPI(title="SpiderNet OS - Services API", version="1.0.0", lifespan=lifespan)
app.include_router(hermes_router)
