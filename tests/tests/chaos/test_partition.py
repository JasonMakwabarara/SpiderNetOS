#!/usr/bin/env python3
"""
Chaos test: Network partition simulation
Tests system behavior during network splits
"""
import subprocess
import time
import sys


def disconnect_container(container, network="spidernet"):
    """Simulate network partition."""
    subprocess.run(
        ["docker", "network", "disconnect", network, container],
        capture_output=True
    )
    print(f"Disconnected {container} from {network}")


def reconnect_container(container, network="spidernet"):
    """Restore network connection."""
    subprocess.run(
        ["docker", "network", "connect", network, container],
        capture_output=True
    )
    print(f"Reconnected {container} to {network}")


def test_kafka_partition():
    """Test Kafka during network partition."""
    print("\n=== Testing Kafka Network Partition ===")
    
    # Disconnect one broker
    disconnect_container("kafka-1")
    time.sleep(5)
    
    # Check remaining brokers are functional
    result = subprocess.run(
        ["docker", "exec", "kafka-2", "kafka-broker-api-versions.sh", "--bootstrap-server", "localhost:9092"],
        capture_output=True
    )
    
    if result.returncode == 0:
        print("PASS: Kafka cluster functional without partition")
    else:
        print("FAIL: Kafka cluster degraded")
    
    reconnect_container("kafka-1")
    time.sleep(3)
    print("Kafka partition test complete")


def test_redis_partition():
    """Test Redis during partition."""
    print("\n=== Testing Redis Network Partition ===")
    disconnect_container("redis")
    time.sleep(5)
    reconnect_container("redis")
    print("Redis partition test complete")


if __name__ == "__main__":
    test_kafka_partition()
    test_redis_partition()
    print("\n=== Chaos Tests Complete ===")
