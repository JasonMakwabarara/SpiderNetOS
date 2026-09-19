"""
SpiderNet OS v3.2 - Intelligence Worker
Action Plane entry point with Meta-Planner integration
"""

import asyncio
import json
import signal
import sys
from datetime import datetime, timezone
from typing import Optional

import asyncpg
from agents.atlas_agent import AtlasAgent
from agents.forge_agent import ForgeAgent
from agents.hannah_agent import HannahAgent
from agents.nexus_agent import NexusAgent
from agents.prism_agent import PrismAgent
from agents.sentinel_agent import SentinelAgent
from config import DATABASE_URL, INFERENCE_URL, REDIS_URL
from core.agent_base import MetaPlanner
from core.cost_governor import CostGovernor
from core.memory_graph import EmbeddingService, MemoryGraph

import redis.asyncio as aioredis


class IntelligenceWorker:
    """
    SpiderNet OS Intelligence Worker

    - Subscribes to agent:dispatch Redis channel
    - Routes through Meta-Planner (Hard Rule #2)
    - Enforces CostGovernor (Hard Rule #4)
    - Manages agent lifecycle
    """

    def __init__(self):
        self.redis: Optional[aioredis.Redis] = None
        self.db_pool: Optional[asyncpg.Pool] = None
        self.meta_planner: Optional[MetaPlanner] = None
        self.cost_governor: Optional[CostGovernor] = None
        self.memory_graph: Optional[MemoryGraph] = None
        self.llm_client = None
        self._running = False
        self._shutdown_event = asyncio.Event()

    async def initialize(self) -> None:
        """Initialize all services"""
        print("SpiderNet OS v3.2 - Intelligence Worker")
        print("Initializing services...")

        # Connect to Redis (using redis-py v5 native async)
        self.redis = aioredis.from_url(REDIS_URL)
        print(f"[ok] Redis connected: {REDIS_URL}")

        # Connect to PostgreSQL
        self.db_pool = await asyncpg.create_pool(DATABASE_URL)
        print("[ok] PostgreSQL connected")

        # Initialize core services
        self.cost_governor = CostGovernor(self.redis, self.db_pool)
        print("[ok] CostGovernor initialized")

        embedding_service = EmbeddingService(inference_url=INFERENCE_URL)
        self.memory_graph = MemoryGraph(self.db_pool, self.redis, embedding_service)
        print("[ok] MemoryGraph initialized")

        # EventStore bridge (writes to event_log via asyncpg)
        event_store = EventStoreBridge(self.db_pool)

        self.meta_planner = MetaPlanner(self.redis, event_store, self.cost_governor)
        print("[ok] MetaPlanner initialized")

        # Initialize LLM client
        self.llm_client = LLMClient(inference_url=INFERENCE_URL)

        # Initialize core agents
        await self._initialize_agents()

        # Load dynamic agents from DB
        await self._load_dynamic_agents()

        print("[ready] Intelligence Worker ready")

    async def _initialize_agents(self) -> None:
        """Initialize and register all 6 core agents"""

        # Atlas: NL Compiler
        atlas = AtlasAgent(
            meta_planner=self.meta_planner,
            cost_governor=self.cost_governor,
            memory_graph=self.memory_graph,
            llm_client=self.llm_client,
        )
        self.meta_planner.register_agent(atlas)
        print(f"[ok] Agent registered: {atlas.name} ({atlas.agent_id})")

        # Forge: Flow Builder
        forge = ForgeAgent(
            meta_planner=self.meta_planner,
            cost_governor=self.cost_governor,
            memory_graph=self.memory_graph,
            llm_client=self.llm_client,
        )
        self.meta_planner.register_agent(forge)
        print(f"[ok] Agent registered: {forge.name} ({forge.agent_id})")

        # Sentinel: Monitoring & Anomaly Detection
        sentinel = SentinelAgent(
            meta_planner=self.meta_planner,
            cost_governor=self.cost_governor,
            memory_graph=self.memory_graph,
            db_pool=self.db_pool,
        )
        self.meta_planner.register_agent(sentinel)
        print(f"[ok] Agent registered: {sentinel.name} ({sentinel.agent_id})")

        # Prism: Analysis & Research
        prism = PrismAgent(
            meta_planner=self.meta_planner,
            cost_governor=self.cost_governor,
            memory_graph=self.memory_graph,
            llm_client=self.llm_client,
        )
        self.meta_planner.register_agent(prism)
        print(f"[ok] Agent registered: {prism.name} ({prism.agent_id})")

        # Nexus: Execution & Orchestration
        nexus = NexusAgent(
            meta_planner=self.meta_planner,
            cost_governor=self.cost_governor,
            memory_graph=self.memory_graph,
            db_pool=self.db_pool,
        )
        self.meta_planner.register_agent(nexus)
        print(f"[ok] Agent registered: {nexus.name} ({nexus.agent_id})")

        # Hannah: Tutor & Teacher
        hannah = HannahAgent(
            meta_planner=self.meta_planner,
            cost_governor=self.cost_governor,
            memory_graph=self.memory_graph,
            llm_client=self.llm_client,
        )
        self.meta_planner.register_agent(hannah)
        print(f"[ok] Agent registered: {hannah.name} ({hannah.agent_id})")

    async def _load_dynamic_agents(self) -> None:
        """Load user-created dynamic agents from DB and register them."""
        try:
            from agents.dynamic_agent import DynamicAgent
        except ImportError:
            # DynamicAgent not yet implemented (Sprint 5B)
            return

        try:
            rows = await self.db_pool.fetch(
                "SELECT id, name, slug, capabilities, config FROM agents "
                "WHERE type = 'dynamic' AND status = 'active'"
            )
            for row in rows:
                config = json.loads(row['config']) if isinstance(row['config'], str) else (row['config'] or {})
                capabilities = json.loads(row['capabilities']) if isinstance(row['capabilities'], str) else (row['capabilities'] or [])
                agent = DynamicAgent(
                    agent_id=row['slug'],
                    name=row['name'],
                    capabilities=capabilities,
                    config=config,
                    meta_planner=self.meta_planner,
                    cost_governor=self.cost_governor,
                    memory_graph=self.memory_graph,
                    llm_client=self.llm_client,
                )
                self.meta_planner.register_agent(agent)
                print(f"[ok] Dynamic agent registered: {agent.name} ({agent.agent_id})")

            if rows:
                print(f"[ok] Loaded {len(rows)} dynamic agent(s) from DB")
        except Exception as e:
            print(f"[warn] Could not load dynamic agents: {e}")

    async def run(self) -> None:
        """Main event loop"""
        self._running = True

        # Setup signal handlers (Unix only)
        if sys.platform != 'win32':
            for sig in (signal.SIGTERM, signal.SIGINT):
                asyncio.get_event_loop().add_signal_handler(sig, self._signal_handler)

        print("\n[listen] Listening for dispatch events...")

        # Start hot-reload listener for dynamic agents
        asyncio.create_task(self._listen_agent_events())

        try:
            while self._running:
                try:
                    # Block on Redis queue with timeout
                    result = await self.redis.blpop('agent:dispatch', timeout=1)

                    if result:
                        queue_name, message = result
                        await self._process_message(message)

                    # Check for shutdown
                    if self._shutdown_event.is_set():
                        break

                except asyncio.CancelledError:
                    break
                except Exception as e:
                    print(f"[error] Error processing message: {e}")
                    await asyncio.sleep(1)

        finally:
            await self.shutdown()

    async def _listen_agent_events(self) -> None:
        """Listen for agent:registered events to hot-load new dynamic agents."""
        try:
            pubsub = self.redis.pubsub()
            await pubsub.subscribe('agent:registered')

            async for message in pubsub.listen():
                if message['type'] != 'message':
                    continue
                try:
                    data = json.loads(message['data'])
                    if data.get('type') == 'dynamic':
                        print(f"[hot-reload] New dynamic agent detected: {data.get('slug')}")
                        await self._load_dynamic_agents()
                except Exception as e:
                    print(f"[warn] Error processing agent event: {e}")
        except asyncio.CancelledError:
            pass
        except Exception as e:
            print(f"[warn] Agent event listener error: {e}")

    async def _process_message(self, message: bytes) -> None:
        """Process a dispatch message"""
        try:
            data = json.loads(message)
            print(f"[dispatch] {data.get('agent_id')} <- {data.get('intent')}")

            result = await self.meta_planner.dispatch(
                tenant_id=data['tenant_id'],
                agent_id=data['agent_id'],
                intent=data['intent'],
                context=data['context'],
                flow_id=data.get('flow_id'),
            )

            print(f"[result] {result['status']}")

            # Publish result back
            result_data = {
                'event_id': data.get('event_id'),
                'result': result,
                'timestamp': datetime.now(timezone.utc).isoformat(),
            }
            await self.redis.publish(
                f"agent:result:{data['tenant_id']}",
                json.dumps(result_data)
            )

        except Exception as e:
            print(f"[error] Failed to process message: {e}")

    def _signal_handler(self) -> None:
        """Handle shutdown signals"""
        print("\n[shutdown] Shutdown signal received...")
        self._running = False
        self._shutdown_event.set()

    async def shutdown(self) -> None:
        """Graceful shutdown"""
        print("\n[shutdown] Shutting down...")

        if self.redis:
            await self.redis.close()
            print("[ok] Redis disconnected")

        if self.db_pool:
            await self.db_pool.close()
            print("[ok] PostgreSQL disconnected")

        print("[done] Goodbye")


class EventStoreBridge:
    """
    EventStore bridge — writes events directly to PostgreSQL event_log table.
    Implements the same interface as the Laravel EventStore service.
    """

    def __init__(self, db_pool: asyncpg.Pool):
        self.db_pool = db_pool

    async def append(self, tenant_id: str, aggregate_type: str, aggregate_id: str,
                     event_type: str, payload: dict, metadata: dict = None,
                     expected_version: int = None) -> dict:
        """Append an event to the event_log."""
        import uuid

        async with self.db_pool.acquire() as conn:
            # Get next sequence number
            seq = await conn.fetchval(
                "UPDATE event_sequence SET next_num = next_num + 1 RETURNING next_num - 1"
            )
            if seq is None:
                await conn.execute("INSERT INTO event_sequence (next_num) VALUES (2)")
                seq = 1

            # Get current version for this aggregate
            current_version = await conn.fetchval(
                "SELECT COALESCE(MAX(version), 0) FROM event_log "
                "WHERE aggregate_type = $1 AND aggregate_id = $2",
                aggregate_type, aggregate_id
            )

            if expected_version is not None and current_version != expected_version:
                raise Exception(
                    f"Concurrency conflict: expected version {expected_version}, "
                    f"got {current_version}"
                )

            new_version = current_version + 1
            event_id = str(uuid.uuid4())
            now = datetime.now(timezone.utc)

            await conn.execute(
                """INSERT INTO event_log
                   (id, tenant_id, aggregate_type, aggregate_id, event_type,
                    payload, metadata, version, occurred_at, sequence_num)
                   VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)""",
                event_id, tenant_id, aggregate_type, aggregate_id,
                event_type, json.dumps(payload), json.dumps(metadata or {}),
                new_version, now, seq
            )

            return {
                'id': event_id,
                'sequence_num': seq,
                'version': new_version,
                'occurred_at': now.isoformat(),
            }

    async def get_events(self, tenant_id: str = None, aggregate_type: str = None,
                         aggregate_id: str = None, event_type: str = None,
                         since: str = None, limit: int = 100) -> list:
        """Query events from event_log."""
        conditions = []
        params = []
        idx = 1

        if tenant_id:
            conditions.append(f"tenant_id = ${idx}")
            params.append(tenant_id)
            idx += 1
        if aggregate_type:
            conditions.append(f"aggregate_type = ${idx}")
            params.append(aggregate_type)
            idx += 1
        if aggregate_id:
            conditions.append(f"aggregate_id = ${idx}")
            params.append(aggregate_id)
            idx += 1
        if event_type:
            conditions.append(f"event_type = ${idx}")
            params.append(event_type)
            idx += 1
        if since:
            conditions.append(f"occurred_at > ${idx}")
            params.append(since)
            idx += 1

        where = " AND ".join(conditions) if conditions else "1=1"

        rows = await self.db_pool.fetch(
            f"SELECT * FROM event_log WHERE {where} "
            f"ORDER BY sequence_num ASC LIMIT ${idx}",
            *params, limit
        )

        return [dict(row) for row in rows]


class LLMClient:
    """
    LLM Client — routes inference requests through the inference plane.
    Replaces the stub with real HTTP calls to the FastAPI inference service.
    """

    def __init__(self, inference_url: str = "http://inference:9000"):
        self.inference_url = inference_url

    async def complete(self, prompt: str, model: str = 'gemma4',
                       system_prompt: str = None, temperature: float = 0.7,
                       max_tokens: int = 4096, tenant_id: str = None,
                       cost_ceiling: float = 50.0) -> dict:
        """Send completion request to inference plane."""
        import httpx

        try:
            async with httpx.AsyncClient(timeout=120.0) as client:
                resp = await client.post(
                    f"{self.inference_url}/generate",
                    json={
                        "prompt": prompt,
                        "system_prompt": system_prompt or "",
                        "model": model,
                        "temperature": temperature,
                        "max_tokens": max_tokens,
                        "tenant_id": tenant_id or "system",
                        "cost_ceiling": cost_ceiling,
                        "tenant_tier": "growth",
                    }
                )
                resp.raise_for_status()
                data = resp.json()

                return {
                    'text': data.get('text', ''),
                    'tokens': data.get('tokens_used', 0),
                    'model': data.get('model', model),
                    'cost': data.get('cost', 0),
                    'latency_ms': data.get('latency_ms', 0),
                }
        except Exception as e:
            print(f"[warn] LLM call failed, using echo fallback: {e}")
            return {
                'text': f'[Inference unavailable] Echo: {prompt[:100]}...',
                'tokens': len(prompt.split()),
                'model': model,
                'cost': 0,
                'latency_ms': 0,
            }


async def main():
    """Entry point"""
    worker = IntelligenceWorker()

    try:
        await worker.initialize()
        await worker.run()
    except Exception as e:
        print(f"[fatal] Fatal error: {e}")
        sys.exit(1)


if __name__ == '__main__':
    asyncio.run(main())
