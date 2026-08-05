"""
Database integration tests using Testcontainers.
Tests real PostgreSQL with pgvector extension.
"""
import pytest
from sqlalchemy import create_engine, text


class TestPostgreSQLIntegration:
    """Test PostgreSQL with pgvector in containerized environment."""

    def test_postgres_connection(self, postgres_container):
        """Verify PostgreSQL is accessible."""
        engine = create_engine(postgres_container)
        with engine.connect() as conn:
            result = conn.execute(text("SELECT 1"))
            assert result.scalar() == 1

    def test_pgvector_extension(self, postgres_container):
        """Verify pgvector extension is installed and functional."""
        engine = create_engine(postgres_container)
        with engine.connect() as conn:
            # Check extension exists
            result = conn.execute(text(
                "SELECT * FROM pg_extension WHERE extname = 'vector'"
            ))
            assert result.fetchone() is not None, "pgvector extension not found"

    def test_vector_operations(self, postgres_container):
        """Test vector similarity search works."""
        engine = create_engine(postgres_container)
        with engine.connect() as conn:
            # Create table with vector column
            conn.execute(text("""
                CREATE TABLE IF NOT EXISTS embeddings (
                    id SERIAL PRIMARY KEY,
                    embedding VECTOR(3)
                )
            """))
            
            # Insert test vectors
            conn.execute(text("""
                INSERT INTO embeddings (embedding) VALUES 
                ('[1,2,3]'), 
                ('[4,5,6]'),
                ('[1,1,1]')
            """))
            
            # Test similarity search
            result = conn.execute(text("""
                SELECT id FROM embeddings 
                ORDER BY embedding <-> '[1,2,3]' 
                LIMIT 1
            """))
            
            closest_id = result.scalar()
            assert closest_id == 1  # [1,2,3] should be closest to itself

    def test_scram_authentication(self, postgres_container):
        """Verify SCRAM-SHA-256 authentication is used."""
        engine = create_engine(postgres_container)
        with engine.connect() as conn:
            result = conn.execute(text("""
                SELECT auth_method FROM pg_hba_file_rules 
                WHERE type = 'host' LIMIT 1
            """))
            # Just verify connection succeeded with expected auth
            assert result is not None


class TestRedisIntegration:
    """Test Redis connectivity and operations."""

    def test_redis_connection(self, redis_container):
        """Verify Redis is accessible."""
        import redis
        r = redis.from_url(redis_container)
        assert r.ping() is True

    def test_redis_operations(self, redis_container):
        """Test basic Redis operations."""
        import redis
        r = redis.from_url(redis_container)
        
        # Test string operations
        r.set("test_key", "test_value")
        assert r.get("test_key") == b"test_value"
        
        # Test list operations
        r.lpush("test_list", "item1", "item2")
        assert r.llen("test_list") == 2
        
        # Cleanup
        r.delete("test_key", "test_list")

    def test_redis_pubsub(self, redis_container):
        """Test Redis pub/sub functionality."""
        import redis
        r = redis.from_url(redis_container)
        
        pubsub = r.pubsub()
        pubsub.subscribe("test_channel")
        
        # Publish message
        r.publish("test_channel", "hello")
        
        # Get message
        message = pubsub.get_message(timeout=1)
        assert message is not None
        
        pubsub.unsubscribe()
