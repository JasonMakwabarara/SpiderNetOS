"""
SpiderNet OS v3.2 - Memory Graph (Campaign Memory)
Hybrid retrieval: Vector similarity + Graph traversal
Persistence: PostgreSQL with pgvector for embeddings
"""

import hashlib
import json
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional


@dataclass
class MemoryNode:
    id: str
    tenant_id: str
    agent_id: Optional[str]
    node_type: str  # 'fact', 'conversation', 'document', 'insight'
    content: str
    embedding: Optional[List[float]]
    metadata: Dict[str, Any]
    recency_score: float
    importance_score: float
    access_count: int
    last_accessed_at: Optional[datetime]


@dataclass
class MemoryEdge:
    source_id: str
    target_id: str
    relation_type: str  # 'related', 'derived_from', 'part_of', 'contradicts'
    weight: float
    metadata: Dict[str, Any]


class MemoryGraph:
    """
    Campaign Memory Graph with hybrid retrieval.
    Combines vector similarity with graph traversal for context assembly.
    """

    def __init__(self, db_pool, redis_client, embedding_service):
        self.db = db_pool
        self.redis = redis_client
        self.embeddings = embedding_service
        self._cache = {}  # In-memory cache for hot nodes

    async def add_node(
        self,
        tenant_id: str,
        content: str,
        node_type: str = 'fact',
        agent_id: Optional[str] = None,
        metadata: Optional[Dict] = None,
        embedding: Optional[List[float]] = None
    ) -> MemoryNode:
        """Add a memory node with optional pre-computed embedding"""

        node_id = self._generate_id(content, tenant_id)

        # Generate embedding if not provided
        if embedding is None:
            embedding = await self.embeddings.embed(content)

        node = MemoryNode(
            id=node_id,
            tenant_id=tenant_id,
            agent_id=agent_id,
            node_type=node_type,
            content=content,
            embedding=embedding,
            metadata=metadata or {},
            recency_score=1.0,
            importance_score=metadata.get('importance', 0.5) if metadata else 0.5,
            access_count=0,
            last_accessed_at=None,
        )

        await self._persist_node(node)
        self._cache[node_id] = node

        return node

    async def add_edge(
        self,
        source_id: str,
        target_id: str,
        relation_type: str,
        weight: float = 1.0,
        metadata: Optional[Dict] = None
    ) -> MemoryEdge:
        """Add an edge between memory nodes"""

        edge = MemoryEdge(
            source_id=source_id,
            target_id=target_id,
            relation_type=relation_type,
            weight=weight,
            metadata=metadata or {},
        )

        await self._persist_edge(edge)
        return edge

    async def retrieve(
        self,
        tenant_id: str,
        query: str,
        agent_id: Optional[str] = None,
        top_k: int = 5,
        depth: int = 2
    ) -> List[Dict[str, Any]]:
        """
        Hybrid retrieval combining vector similarity with graph traversal.

        Algorithm:
        1. Vector search for top-K similar nodes
        2. Graph traversal to find related nodes (expanding context)
        3. Score fusion: vector_score * 0.5 + graph_score * 0.3 + recency * 0.2
        4. Return assembled context
        """

        # Step 1: Vector similarity search
        query_embedding = await self.embeddings.embed(query)
        vector_results = await self._vector_search(
            tenant_id=tenant_id,
            embedding=query_embedding,
            agent_id=agent_id,
            top_k=top_k
        )

        # Step 2: Graph expansion
        graph_results = []
        for node in vector_results:
            related = await self._graph_traverse(
                node_id=node['id'],
                depth=depth,
                tenant_id=tenant_id
            )
            graph_results.extend(related)

        # Step 3: Score fusion and deduplication
        combined = self._fuse_scores(vector_results, graph_results)

        # Step 4: Record access for recency update
        for item in combined[:top_k]:
            await self._record_access(item['id'])

        return combined[:top_k]

    async def _vector_search(
        self,
        tenant_id: str,
        embedding: List[float],
        agent_id: Optional[str],
        top_k: int
    ) -> List[Dict]:
        """Vector similarity search using pgvector cosine distance operator."""
        if not embedding or not self.db:
            return []

        try:
            # Build the embedding string for pgvector
            embedding_str = '[' + ','.join(str(v) for v in embedding) + ']'

            if agent_id:
                rows = await self.db.fetch(
                    """SELECT id, content, node_type, metadata, recency_score, importance_score,
                              last_accessed_at,
                              1 - (embedding <=> $1::vector) AS similarity
                       FROM memory_nodes
                       WHERE tenant_id = $2 AND agent_id = $3 AND embedding IS NOT NULL
                       ORDER BY embedding <=> $1::vector
                       LIMIT $4""",
                    embedding_str, tenant_id, agent_id, top_k
                )
            else:
                rows = await self.db.fetch(
                    """SELECT id, content, node_type, metadata, recency_score, importance_score,
                              last_accessed_at,
                              1 - (embedding <=> $1::vector) AS similarity
                       FROM memory_nodes
                       WHERE tenant_id = $2 AND embedding IS NOT NULL
                       ORDER BY embedding <=> $1::vector
                       LIMIT $3""",
                    embedding_str, tenant_id, top_k
                )

            return [
                {
                    'id': str(row['id']),
                    'content': row['content'],
                    'node_type': row['node_type'],
                    'metadata': json.loads(row['metadata']) if isinstance(row['metadata'], str) else (row['metadata'] or {}),
                    'score': float(row['similarity']) if row['similarity'] else 0.0,
                    'recency_score': float(row['recency_score']),
                    'last_accessed_at': row['last_accessed_at'].isoformat() if row['last_accessed_at'] else None,
                }
                for row in rows
            ]
        except Exception as e:
            print(f"[warn] Vector search failed: {e}")
            return []

    async def _graph_traverse(
        self,
        node_id: str,
        depth: int,
        tenant_id: str
    ) -> List[Dict]:
        """Traverse graph from starting node"""
        if depth <= 0:
            return []

        edges = await self._get_edges(node_id, tenant_id)

        results = []
        for edge in edges:
            target_id = edge.target_id if edge.source_id == node_id else edge.source_id
            node = await self._get_node(target_id)
            if node:
                results.append({
                    'id': node.id,
                    'content': node.content,
                    'score': edge.weight * (0.7 ** (3 - depth)),
                    'relation': edge.relation_type,
                })

        return results

    def _fuse_scores(
        self,
        vector_results: List[Dict],
        graph_results: List[Dict]
    ) -> List[Dict]:
        """
        Fuse scores into institutional intelligence ranking.

        score =
          0.4 * similarity +
          0.3 * recency_decay +
          0.2 * graph_connectivity +
          0.1 * case_relevance
        """
        scores: Dict[str, Dict[str, Any]] = {}

        for item in vector_results:
            node_id = item['id']
            metadata = item.get('metadata', {}) or {}
            similarity = float(item.get('score', 0.0))
            recency_decay = self._calculate_recency_decay(item)
            case_relevance = self._calculate_case_relevance(metadata)

            scores[node_id] = {
                **item,
                'similarity': similarity,
                'recency_decay': recency_decay,
                'graph_connectivity': 0.0,
                'case_relevance': case_relevance,
                'final_score': 0.4 * similarity + 0.3 * recency_decay + 0.1 * case_relevance,
            }

        for item in graph_results:
            node_id = item['id']
            graph_score = float(item.get('score', 0.0))

            if node_id not in scores:
                node = self._cache.get(node_id)
                metadata = getattr(node, 'metadata', {}) if node else {}
                recency_decay = node.recency_score if node else 0.5
                case_relevance = self._calculate_case_relevance(metadata)
                scores[node_id] = {
                    **item,
                    'similarity': 0.0,
                    'recency_decay': recency_decay,
                    'graph_connectivity': graph_score,
                    'case_relevance': case_relevance,
                    'final_score': 0.3 * recency_decay + 0.2 * graph_score + 0.1 * case_relevance,
                }
            else:
                scores[node_id]['graph_connectivity'] += graph_score

            # Recompute with canonical weights
            s = scores[node_id]
            s['final_score'] = (
                0.4 * float(s.get('similarity', 0.0)) +
                0.3 * float(s.get('recency_decay', 0.0)) +
                0.2 * float(s.get('graph_connectivity', 0.0)) +
                0.1 * float(s.get('case_relevance', 0.0))
            )

        return sorted(scores.values(), key=lambda x: x['final_score'], reverse=True)

    def _calculate_recency_decay(self, item: Dict[str, Any]) -> float:
        """Recency decay from 0..1 where newer access scores higher."""
        if 'last_accessed_at' in item and item['last_accessed_at']:
            try:
                ts = item['last_accessed_at']
                if isinstance(ts, str):
                    ts = datetime.fromisoformat(ts.replace('Z', '+00:00'))
                age_hours = max(0.0, (datetime.now(timezone.utc) - ts).total_seconds() / 3600.0)
                return 1.0 / (1.0 + age_hours / 24.0)
            except Exception:
                pass
        return float(item.get('recency_score', 0.5))

    def _calculate_case_relevance(self, metadata: Dict[str, Any]) -> float:
        """Case relevance heuristic for legal/precedent-style retrieval."""
        if not metadata:
            return 0.0
        relevance = 0.0
        if metadata.get('case_type'):
            relevance += 0.4
        if metadata.get('precedent_cluster'):
            relevance += 0.3
        if metadata.get('jurisdiction'):
            relevance += 0.2
        if metadata.get('outcome'):
            relevance += 0.1
        return min(1.0, relevance)

    async def _record_access(self, node_id: str) -> None:
        """Record node access and update recency score"""
        if not self.db:
            return
        try:
            await self.db.execute(
                """UPDATE memory_nodes
                   SET access_count = access_count + 1,
                       last_accessed_at = $1,
                       recency_score = 1.0
                   WHERE id = $2""",
                datetime.now(timezone.utc), node_id
            )
        except Exception as e:
            print(f"[warn] Failed to record access for {node_id}: {e}")

    async def _persist_node(self, node: MemoryNode) -> None:
        """Persist node to PostgreSQL memory_nodes table."""
        if not self.db:
            return
        try:
            embedding_str = None
            if node.embedding:
                embedding_str = '[' + ','.join(str(v) for v in node.embedding) + ']'

            await self.db.execute(
                """INSERT INTO memory_nodes
                   (id, tenant_id, agent_id, node_type, content, embedding,
                    metadata, recency_score, importance_score, access_count,
                    last_accessed_at, created_at, updated_at)
                   VALUES ($1, $2, $3, $4, $5, $6::vector, $7, $8, $9, $10, $11, $12, $12)
                   ON CONFLICT (id) DO UPDATE SET
                    content = EXCLUDED.content,
                    embedding = EXCLUDED.embedding,
                    metadata = EXCLUDED.metadata,
                    recency_score = EXCLUDED.recency_score,
                    importance_score = EXCLUDED.importance_score,
                    access_count = EXCLUDED.access_count,
                    last_accessed_at = EXCLUDED.last_accessed_at,
                    updated_at = EXCLUDED.updated_at""",
                node.id, node.tenant_id, node.agent_id, node.node_type,
                node.content, embedding_str, json.dumps(node.metadata),
                node.recency_score, node.importance_score, node.access_count,
                node.last_accessed_at, datetime.now(timezone.utc)
            )
        except Exception as e:
            print(f"[warn] Failed to persist node {node.id}: {e}")

    async def _persist_edge(self, edge: MemoryEdge) -> None:
        """Persist edge to PostgreSQL memory_edges table."""
        if not self.db:
            return
        try:
            await self.db.execute(
                """INSERT INTO memory_edges
                   (source_id, target_id, relation_type, weight, metadata, created_at, updated_at)
                   VALUES ($1, $2, $3, $4, $5, $6, $6)
                   ON CONFLICT (source_id, target_id, relation_type) DO UPDATE SET
                    weight = EXCLUDED.weight,
                    metadata = EXCLUDED.metadata,
                    updated_at = EXCLUDED.updated_at""",
                edge.source_id, edge.target_id, edge.relation_type,
                edge.weight, json.dumps(edge.metadata),
                datetime.now(timezone.utc)
            )
        except Exception as e:
            print(f"[warn] Failed to persist edge {edge.source_id}->{edge.target_id}: {e}")

    async def _get_node(self, node_id: str) -> Optional[MemoryNode]:
        """Get node by ID (cache-aware, DB fallback)."""
        if node_id in self._cache:
            return self._cache[node_id]

        if not self.db:
            return None

        try:
            row = await self.db.fetchrow(
                "SELECT * FROM memory_nodes WHERE id = $1", node_id
            )
            if row:
                node = MemoryNode(
                    id=str(row['id']),
                    tenant_id=str(row['tenant_id']),
                    agent_id=str(row['agent_id']) if row['agent_id'] else None,
                    node_type=row['node_type'],
                    content=row['content'],
                    embedding=None,  # Don't load embedding into cache
                    metadata=json.loads(row['metadata']) if isinstance(row['metadata'], str) else (row['metadata'] or {}),
                    recency_score=float(row['recency_score']),
                    importance_score=float(row['importance_score']),
                    access_count=int(row['access_count']),
                    last_accessed_at=row['last_accessed_at'],
                )
                self._cache[node_id] = node
                return node
        except Exception as e:
            print(f"[warn] Failed to get node {node_id}: {e}")

        return None

    async def _get_edges(self, node_id: str, tenant_id: str) -> List[MemoryEdge]:
        """Get edges connected to node from PostgreSQL."""
        if not self.db:
            return []

        try:
            rows = await self.db.fetch(
                """SELECT source_id, target_id, relation_type, weight, metadata
                   FROM memory_edges
                   WHERE source_id = $1 OR target_id = $1""",
                node_id
            )
            return [
                MemoryEdge(
                    source_id=str(row['source_id']),
                    target_id=str(row['target_id']),
                    relation_type=row['relation_type'],
                    weight=float(row['weight']),
                    metadata=json.loads(row['metadata']) if isinstance(row['metadata'], str) else (row['metadata'] or {}),
                )
                for row in rows
            ]
        except Exception as e:
            print(f"[warn] Failed to get edges for {node_id}: {e}")
            return []

    def _generate_id(self, content: str, tenant_id: str) -> str:
        """Generate deterministic ID from content"""
        hash_input = f"{tenant_id}:{content}"
        return hashlib.sha256(hash_input.encode()).hexdigest()[:16]


class EmbeddingService:
    """
    Embedding generation via inference plane /embed endpoint.
    Uses Gemma 4 through Ollama for 384-dim embeddings.
    """

    def __init__(self, inference_url: str = "http://inference:9000", model: str = None):
        self.inference_url = inference_url
        self.model = model  # None = use server default

    async def embed(self, text: str) -> List[float]:
        """Generate embedding for text via inference plane."""
        try:
            import httpx

            payload = {"text": text}
            if self.model:
                payload["model"] = self.model

            async with httpx.AsyncClient(timeout=30.0) as client:
                resp = await client.post(
                    f"{self.inference_url}/embed",
                    json=payload,
                )
                resp.raise_for_status()
                data = resp.json()
                return data.get("embedding", [])
        except Exception as e:
            print(f"[warn] Embedding generation failed: {e}")
            # Return zero vector as fallback (will have low similarity scores)
            from config import EMBEDDING_DIM
            return [0.0] * EMBEDDING_DIM
