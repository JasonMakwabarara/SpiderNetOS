import json


async def generate_embedding(text: str) -> list:
    return [0.0] * 384

async def evaluate_incoming_event(
    event_payload: str,
    workspace_id: str,
    db_pool,
    redis_client
):
    event_embedding = await generate_embedding(event_payload)
    embedding_str = '[' + ','.join(str(x) for x in event_embedding) + ']'

    async with db_pool.acquire() as conn:
        matched_pattern = await conn.fetchrow(
            """
            SELECT playbook_id, confidence_score
            FROM crm_cached_patterns
            WHERE workspace_id = $1 AND embedding <=> $2 < 0.05
            ORDER BY embedding <=> $2 ASC LIMIT 1;
            """, workspace_id, embedding_str
        )

    if matched_pattern:
        await redis_client.lpush("queue:runtime:deterministic", json.dumps({
            "playbook_id": str(matched_pattern['playbook_id']),
            "context": event_payload
        }))
        return {"route": "deterministic_cache_bypass", "source": str(matched_pattern['playbook_id'])}

    await redis_client.lpush("queue:atlas:cognitive:perception", json.dumps({
        "workspace_id": workspace_id,
        "payload": event_payload
    }))
    return {"route": "cognitive_analysis_pipeline"}
