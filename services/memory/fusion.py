"""
SpiderNet OS - Memory Fusion Layer
Graph (Neo4j) + Vector (Qdrant/pgvector) + Episodic integration
Theory: Tulving (episodic memory) + Knowledge Graphs
"""

import os
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Dict, List, Optional

import numpy as np


@dataclass
class MemoryQuery:
    """Query for memory retrieval"""
    query_text: str
    query_embedding: Optional[List[float]] = None
    mode: str = "both"  # semantic, structural, both
    top_k: int = 10
    recency_weight: float = 0.3
    tenant_id: Optional[str] = None


@dataclass
class MemoryResult:
    """Result from memory retrieval"""
    content: str
    source: str  # neo4j, qdrant, pgvector
    score: float
    metadata: Dict[str, Any]
    embedding: Optional[List[float]] = None


class GraphMemory:
    """
    Neo4j graph memory for structural patterns.
    Stores: agents, workflows, dependencies, execution paths
    """

    def __init__(
        self,
        uri: str | None = None,
        user: str | None = None,
        password: str | None = None,
    ):
        # No credential default in the signature: a password written here is
        # a password in the source tree, and the one nobody remembers to
        # override. Absent config fails at connect time, loudly.
        self.uri = uri or os.getenv("NEO4J_URI", "bolt://localhost:7687")
        self.user = user or os.getenv("NEO4J_USER", "neo4j")
        self.password = password or os.getenv("NEO4J_PASSWORD")
        self._driver = None

    async def connect(self):
        """Connect to Neo4j"""
        from neo4j import AsyncGraphDatabase
        self._driver = AsyncGraphDatabase.driver(self.uri, auth=(self.user, self.password))

    async def close(self):
        """Close connection"""
        if self._driver:
            await self._driver.close()

    async def create_agent_node(self, agent_id: str, agent_type: str, capabilities: List[str], tenant_id: str):
        """Create agent node in graph"""
        query = """
        MERGE (a:Agent {id: $agent_id})
        SET a.type = $agent_type,
            a.capabilities = $capabilities,
            a.tenant_id = $tenant_id,
            a.created_at = datetime()
        RETURN a
        """
        async with self._driver.session() as session:
            await session.run(query, agent_id=agent_id, agent_type=agent_type,
                            capabilities=capabilities, tenant_id=tenant_id)

    async def create_workflow_node(self, workflow_id: str, workflow_type: str, complexity: float, tenant_id: str):
        """Create workflow node"""
        query = """
        MERGE (w:Workflow {id: $workflow_id})
        SET w.type = $workflow_type,
            w.complexity = $complexity,
            w.tenant_id = $tenant_id,
            w.created_at = datetime()
        RETURN w
        """
        async with self._driver.session() as session:
            await session.run(query, workflow_id=workflow_id, workflow_type=workflow_type,
                            complexity=complexity, tenant_id=tenant_id)

    async def create_dependency(self, from_id: str, to_id: str, dep_type: str = "DEPENDS_ON"):
        """Create dependency relationship"""
        query = f"""
        MATCH (a {{id: $from_id}}), (b {{id: $to_id}})
        MERGE (a)-[r:{dep_type}]->(b)
        SET r.created_at = datetime()
        RETURN r
        """
        async with self._driver.session() as session:
            await session.run(query, from_id=from_id, to_id=to_id)

    async def record_execution(self, plan_id: str, agent_id: str, outcome: str, reward: float, cost: float):
        """Record plan execution outcome"""
        query = """
        MATCH (p:Plan {id: $plan_id}), (a:Agent {id: $agent_id})
        CREATE (e:Execution {outcome: $outcome, reward: $reward, cost: $cost, timestamp: datetime()})
        CREATE (p)-[:EXECUTED_BY]->(e)-[:EXECUTED_ON]->(a)
        RETURN e
        """
        async with self._driver.session() as session:
            await session.run(query, plan_id=plan_id, agent_id=agent_id,
                            outcome=outcome, reward=reward, cost=cost)

    async def get_agent_dependencies(self, agent_id: str, depth: int = 2) -> List[Dict]:
        """Get agent dependency graph"""
        query = """
        MATCH path = (a:Agent {id: $agent_id})-[:DEPENDS_ON*1..$depth]->(dep)
        RETURN dep.id as dependent, dep.type as type, length(path) as distance
        """
        async with self._driver.session() as session:
            result = await session.run(query, agent_id=agent_id, depth=depth)
            return [record.data() async for record in result]

    async def get_similar_workflows(self, workflow_type: str, outcome: str, limit: int = 5) -> List[Dict]:
        """Get workflows with similar type and outcome"""
        query = """
        MATCH (w:Workflow {type: $workflow_type})-[:EXECUTED_BY]->(e:Execution {outcome: $outcome})
        RETURN w.id as workflow_id, w.complexity as complexity, e.reward as reward
        ORDER BY e.reward DESC
        LIMIT $limit
        """
        async with self._driver.session() as session:
            result = await session.run(query, workflow_type=workflow_type, outcome=outcome, limit=limit)
            return [record.data() async for record in result]

    async def get_execution_path(self, plan_id: str) -> List[Dict]:
        """Get full execution path for a plan"""
        query = """
        MATCH path = (p:Plan {id: $plan_id})-[:EXECUTED_BY]->(e:Execution)-[:EXECUTED_ON]->(a:Agent)
        RETURN p, e, a, nodes(path) as path_nodes
        """
        async with self._driver.session() as session:
            result = await session.run(query, plan_id=plan_id)
            return [record.data() async for record in result]


class VectorMemory:
    """
    Qdrant + pgvector hybrid for semantic similarity.
    Qdrant: High-performance search
    pgvector: PostgreSQL integration
    """

    def __init__(
        self,
        qdrant_host: str = "localhost",
        qdrant_port: int = 6333,
        pg_connection: Optional[str] = None
    ):
        self.qdrant_host = qdrant_host
        self.qdrant_port = qdrant_port
        self.pg_connection = pg_connection
        self._qdrant_client = None

    async def connect(self):
        """Connect to Qdrant"""
        from qdrant_client import QdrantClient
        self._qdrant_client = QdrantClient(host=self.qdrant_host, port=self.qdrant_port)

    async def create_collection(self, name: str, dimension: int = 384, distance: str = "Cosine"):
        """Create vector collection"""
        from qdrant_client.models import Distance, VectorParams

        self._qdrant_client.recreate_collection(
            collection_name=name,
            vectors_config=VectorParams(size=dimension, distance=Distance.COSINE)
        )

    async def store_embedding(
        self,
        collection: str,
        id: str,
        embedding: List[float],
        payload: Dict,
        tenant_id: Optional[str] = None
    ):
        """Store vector with metadata"""
        from qdrant_client.models import PointStruct

        payload = {**payload, 'tenant_id': tenant_id, 'timestamp': datetime.utcnow().isoformat()}

        self._qdrant_client.upsert(
            collection_name=collection,
            points=[PointStruct(id=id, vector=embedding, payload=payload)]
        )

    async def search_similar(
        self,
        collection: str,
        query_vector: List[float],
        top_k: int = 10,
        tenant_id: Optional[str] = None,
        score_threshold: Optional[float] = None
    ) -> List[Dict]:
        """Semantic similarity search"""
        filter_condition = None
        if tenant_id:
            from qdrant_client.models import FieldCondition, Filter, MatchValue
            filter_condition = Filter(
                must=[FieldCondition(key="tenant_id", match=MatchValue(value=tenant_id))]
            )

        results = self._qdrant_client.search(
            collection_name=collection,
            query_vector=query_vector,
            limit=top_k,
            query_filter=filter_condition,
            score_threshold=score_threshold
        )

        return [
            {
                'id': r.id,
                'score': r.score,
                'payload': r.payload
            }
            for r in results
        ]

    async def hybrid_search(
        self,
        collection: str,
        query_vector: List[float],
        keyword_filter: str,
        top_k: int = 10
    ) -> List[Dict]:
        """Hybrid vector + keyword search"""
        # This uses Qdrant's hybrid search capabilities
        results = self._qdrant_client.search(
            collection_name=collection,
            query_vector=query_vector,
            limit=top_k * 2  # Get more for re-ranking
        )

        # Keyword re-ranking
        filtered = [
            r for r in results
            if keyword_filter.lower() in str(r.payload).lower()
        ]

        return filtered[:top_k]


class EpisodicMemory:
    """
    PostgreSQL-based episodic memory for RL replay buffer.
    Stores (state, action, reward, next_state, done) transitions.
    """

    def __init__(self, db_pool):
        self.db_pool = db_pool

    async def store_episode(
        self,
        tenant_id: str,
        state_embedding: List[float],
        action: int,
        reward: float,
        next_state_embedding: List[float],
        done: bool,
        metadata: Optional[Dict] = None
    ):
        """Store episode in replay buffer"""
        async with self.db_pool.acquire() as conn:
            await conn.execute(
                """
                INSERT INTO cpl_replay_buffer
                (tenant_id, state, action, reward, next_state, done, metadata, priority, created_at)
                VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)
                """,
                tenant_id,
                state_embedding,
                action,
                reward,
                next_state_embedding,
                done,
                metadata or {},
                abs(reward) + 1e-6,  # Initial priority by reward magnitude
                datetime.utcnow()
            )

    async def sample_episodes(
        self,
        tenant_id: str,
        batch_size: int = 64,
        priority_exponent: float = 0.6
    ) -> List[Dict]:
        """Sample episodes with prioritized experience replay"""
        async with self.db_pool.acquire() as conn:
            # Priority sampling using importance weights
            rows = await conn.fetch(
                """
                SELECT id, state, action, reward, next_state, done, priority
                FROM cpl_replay_buffer
                WHERE tenant_id = $1
                ORDER BY priority^$2 * random() DESC
                LIMIT $3
                """,
                tenant_id, priority_exponent, batch_size
            )

        return [dict(row) for row in rows]

    async def update_priorities(self, episode_ids: List[str], td_errors: List[float]):
        """Update priorities based on TD errors"""
        async with self.db_pool.acquire() as conn:
            for episode_id, td_error in zip(episode_ids, td_errors, strict=False):
                await conn.execute(
                    """
                    UPDATE cpl_replay_buffer
                    SET priority = $1
                    WHERE id = $2
                    """,
                    abs(td_error) + 1e-6,
                    episode_id
                )


class MemoryFusion:
    """
    Unified memory interface combining Graph, Vector, and Episodic memory.

    Provides:
    - Semantic retrieval (vector similarity)
    - Structural context (graph neighbors)
    - Temporal patterns (episodic replay)
    """

    def __init__(
        self,
        graph_memory: GraphMemory,
        vector_memory: VectorMemory,
        episodic_memory: EpisodicMemory
    ):
        self.graph = graph_memory
        self.vector = vector_memory
        self.episodic = episodic_memory

    async def retrieve_context(self, query: MemoryQuery) -> List[MemoryResult]:
        """
        Retrieve fused context from all memory types.

        Modes:
        - semantic: Vector similarity only
        - structural: Graph neighborhood only
        - both: Combined retrieval with re-ranking
        """
        results = []

        # Vector similarity search
        if query.mode in ("semantic", "both") and query.query_embedding:
                vector_results = await self.vector.search_similar(
                    collection="system_memory",
                    query_vector=query.query_embedding,
                    top_k=query.top_k,
                    tenant_id=query.tenant_id
                )

                for r in vector_results:
                    results.append(MemoryResult(
                        content=r['payload'].get('content', ''),
                        source='qdrant',
                        score=r['score'],
                        metadata=r['payload'],
                        embedding=None
                    ))

        if query.mode in ("structural", "both"):
            # Graph context (if query contains entity IDs)
            # This would need entity extraction from query
            pass

        # Re-ranking for "both" mode
        if query.mode == "both":
            results = self._rerank_results(results, query.recency_weight)

        return results[:query.top_k]

    def _rerank_results(
        self,
        results: List[MemoryResult],
        recency_weight: float
    ) -> List[MemoryResult]:
        """Re-rank results combining score + recency + source diversity"""
        scored = []

        for r in results:
            # Base score
            score = r.score

            # Recency boost
            timestamp_str = r.metadata.get('timestamp')
            if timestamp_str:
                try:
                    timestamp = datetime.fromisoformat(timestamp_str)
                    hours_ago = (datetime.utcnow() - timestamp).total_seconds() / 3600
                    recency_boost = recency_weight * np.exp(-hours_ago / 24)  # Decay over 24h
                    score += recency_boost
                except Exception:
                    pass

            scored.append((score, r))

        # Sort by combined score
        scored.sort(key=lambda x: x[0], reverse=True)

        return [r for _, r in scored]

    async def record_execution_episode(
        self,
        tenant_id: str,
        plan_id: str,
        agent_id: str,
        state_embedding: List[float],
        action: int,
        reward: float,
        next_state_embedding: List[float],
        done: bool,
        cost: float,
        outcome: str
    ):
        """
        Record complete execution episode across all memory types.

        Stores:
        - Graph: Execution path and relationships
        - Episodic: RL training data
        - Vector: Semantic searchability
        """
        # Store in graph
        await self.graph.record_execution(plan_id, agent_id, outcome, reward, cost)

        # Store in episodic memory
        await self.episodic.store_episode(
            tenant_id=tenant_id,
            state_embedding=state_embedding,
            action=action,
            reward=reward,
            next_state_embedding=next_state_embedding,
            done=done,
            metadata={'plan_id': plan_id, 'agent_id': agent_id, 'cost': cost}
        )

        # Store in vector memory (for semantic retrieval)
        content = f"Plan {plan_id} executed by {agent_id} with outcome {outcome}, reward {reward}"
        await self.vector.store_embedding(
            collection="execution_memory",
            id=f"{plan_id}_{datetime.utcnow().isoformat()}",
            embedding=state_embedding,  # Use state embedding
            payload={
                'content': content,
                'plan_id': plan_id,
                'agent_id': agent_id,
                'reward': reward,
                'outcome': outcome
            },
            tenant_id=tenant_id
        )
