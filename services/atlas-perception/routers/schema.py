from uuid import UUID

import asyncpg
from fastapi import APIRouter, Depends

router = APIRouter(prefix="/atlas", tags=["schema"])

async def get_db_pool():
    pool = await asyncpg.create_pool("postgresql://postgres:postgres@postgres:5432/spidernet")
    try:
        yield pool
    finally:
        await pool.close()

@router.get("/schema/{workspace_id}")
async def get_schema_metadata(
    workspace_id: UUID,
    db_pool: asyncpg.Pool = Depends(get_db_pool)
):
    object_query = """
        SELECT id, name, api_slug
        FROM crm_objects
        WHERE workspace_id = $1 OR is_system_defined = TRUE;
    """
    attribute_query = """
        SELECT object_id, name, api_slug, type
        FROM crm_attributes
        WHERE object_id IN (
            SELECT id FROM crm_objects WHERE workspace_id = $1 OR is_system_defined = TRUE
        );
    """
    async with db_pool.acquire() as conn:
        objects = await conn.fetch(object_query, workspace_id)
        attributes = await conn.fetch(attribute_query, workspace_id)

    schema_map = {}
    for obj in objects:
        schema_map[obj['api_slug']] = {
            "object_id": str(obj['id']),
            "name": obj['name'],
            "attributes": {}
        }
    for attr in attributes:
        for slug, data in schema_map.items():
            if data["object_id"] == str(attr['object_id']):
                data["attributes"][attr['api_slug']] = attr['type']

    return {"workspace_id": workspace_id, "schema": schema_map}
