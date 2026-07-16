"""
Testcontainers fixtures for SpiderNetOS integration testing.
Provides real PostgreSQL, Redis, and Kafka instances for tests.
"""
import pytest
from testcontainers.postgres import PostgresContainer
from testcontainers.redis import RedisContainer
from testcontainers.kafka import KafkaContainer


@pytest.fixture(scope="session")
def postgres_container():
    """
    Provide a real PostgreSQL instance with pgvector extension.
    Yields connection string for SQLAlchemy/psycopg2.
    """
    with PostgresContainer(
        image="pgvector/pgvector:pg16",
        username="spidernet",
        password="test_password",
        dbname="spidernet"
    ) as postgres:
        yield postgres.get_connection_url()


@pytest.fixture(scope="session")
def redis_container():
    """
    Provide a real Redis instance.
    Yields connection URL for redis-py.
    """
    with RedisContainer(image="redis:7-alpine") as redis:
        yield redis.get_connection_url()


@pytest.fixture(scope="session")
def kafka_container():
    """
    Provide a real Kafka instance.
    Yields bootstrap server address.
    """
    with KafkaContainer(image="confluentinc/cp-kafka:7.5.0") as kafka:
        yield kafka.get_bootstrap_server()
