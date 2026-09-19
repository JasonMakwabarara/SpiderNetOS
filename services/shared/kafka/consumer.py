"""
SpiderNet OS - Kafka Event Consumer
Async consumer with consumer groups and replay capability
"""

import json
import logging
import threading
from typing import Callable, Dict, List

from kafka import KafkaConsumer as SyncConsumer

logger = logging.getLogger(__name__)


class EventConsumer:
    """
    Async Kafka consumer for SpiderNet OS services.

    Features:
    - Consumer group support
    - Auto offset reset (earliest/latest)
    - Manual commit for reliability
    - Event replay capability
    """

    def __init__(
        self,
        topic: str,
        group_id: str = "spidernet",
        bootstrap_servers: str = "localhost:9092",
        auto_offset_reset: str = "earliest",
        enable_auto_commit: bool = False,  # Manual for reliability
        max_poll_records: int = 100,
        session_timeout_ms: int = 30000,
        heartbeat_interval_ms: int = 10000
    ):
        self.topic = topic
        self.group_id = group_id
        self.bootstrap_servers = bootstrap_servers

        # Sync consumer (runs in background thread)
        self._consumer = SyncConsumer(
            topic,
            bootstrap_servers=bootstrap_servers,
            group_id=group_id,
            auto_offset_reset=auto_offset_reset,
            enable_auto_commit=enable_auto_commit,
            max_poll_records=max_poll_records,
            session_timeout_ms=session_timeout_ms,
            heartbeat_interval_ms=heartbeat_interval_ms,
            value_deserializer=lambda v: json.loads(v.decode("utf-8")),
            key_deserializer=lambda k: k.decode("utf-8") if k else None
        )

        self._running = False
        self._worker_thread = None
        self._handlers: List[Callable] = []

        # Metrics
        self.metrics = {
            'received': 0,
            'processed': 0,
            'failed': 0,
            'lag': 0
        }

    def register_handler(self, handler: Callable[[Dict], None]):
        """Register event handler callback"""
        self._handlers.append(handler)

    def start(self):
        """Start consumer in background thread"""
        self._running = True
        self._worker_thread = threading.Thread(target=self._worker, daemon=True)
        self._worker_thread.start()
        logger.info(f"EventConsumer started for topic: {self.topic}")

    def stop(self):
        """Stop consumer gracefully"""
        self._running = False
        self._consumer.close(timeout=10)
        if self._worker_thread:
            self._worker_thread.join(timeout=5)
        logger.info(f"EventConsumer stopped for topic: {self.topic}")

    def _worker(self):
        """Background worker for event consumption"""
        try:
            while self._running:
                # Poll for messages
                messages = self._consumer.poll(timeout_ms=1000)

                for _topic_partition, msgs in messages.items():
                    for msg in msgs:
                        self.metrics['received'] += 1

                        try:
                            # Process event
                            event = {
                                'topic': msg.topic,
                                'partition': msg.partition,
                                'offset': msg.offset,
                                'key': msg.key,
                                'value': msg.value,
                                'timestamp': msg.timestamp
                            }

                            # Call handlers
                            for handler in self._handlers:
                                try:
                                    handler(event['value'])
                                except Exception as e:
                                    logger.error(f"Handler error: {e}")

                            self.metrics['processed'] += 1

                        except Exception as e:
                            logger.error(f"Message processing error: {e}")
                            self.metrics['failed'] += 1

                # Manual commit after processing
                if not self._consumer.config['enable_auto_commit']:
                    self._consumer.commit()

        except Exception as e:
            logger.error(f"Consumer worker error: {e}")

    def seek_to_beginning(self):
        """Seek to beginning of topic (for replay)"""
        self._consumer.seek_to_beginning()
        logger.info(f"Consumer seeked to beginning of {self.topic}")

    def seek_to_end(self):
        """Seek to end of topic (skip history)"""
        self._consumer.seek_to_end()
        logger.info(f"Consumer seeked to end of {self.topic}")

    def seek_to_timestamp(self, timestamp_ms: int):
        """Seek to specific timestamp"""

        partitions = self._consumer.assignment()
        timestamps = {p: timestamp_ms for p in partitions}
        offsets = self._consumer.offsets_for_times(timestamps)

        for partition, offset in offsets.items():
            if offset:
                self._consumer.seek(partition, offset.offset)

    def get_metrics(self) -> Dict[str, int]:
        """Get consumer metrics"""
        # Update lag metric
        try:
            partitions = self._consumer.assignment()
            total_lag = 0
            for partition in partitions:
                committed = self._consumer.committed(partition)
                end_offset = self._consumer.end_offsets([partition])[partition]
                if committed:
                    total_lag += end_offset - committed.offset
            self.metrics['lag'] = total_lag
        except Exception:
            pass

        return self.metrics.copy()


class MultiTopicConsumer:
    """
    Consumer that subscribes to multiple topics.
    Routes events to topic-specific handlers.
    """

    def __init__(
        self,
        topics: List[str],
        group_id: str = "spidernet",
        bootstrap_servers: str = "localhost:9092"
    ):
        self.topics = topics
        self.group_id = group_id
        self.bootstrap_servers = bootstrap_servers

        self._consumer = SyncConsumer(
            bootstrap_servers=bootstrap_servers,
            group_id=group_id,
            value_deserializer=lambda v: json.loads(v.decode("utf-8"))
        )
        self._consumer.subscribe(topics)

        self._handlers: Dict[str, List[Callable]] = {t: [] for t in topics}
        self._running = False

    def register_handler(self, topic: str, handler: Callable[[Dict], None]):
        """Register handler for specific topic"""
        if topic in self._handlers:
            self._handlers[topic].append(handler)

    def start(self):
        """Start multi-topic consumer"""
        self._running = True
        threading.Thread(target=self._worker, daemon=True).start()

    def stop(self):
        """Stop consumer"""
        self._running = False
        self._consumer.close()

    def _worker(self):
        """Consume from multiple topics"""
        while self._running:
            messages = self._consumer.poll(timeout_ms=1000)

            for topic_partition, msgs in messages.items():
                topic = topic_partition.topic
                handlers = self._handlers.get(topic, [])

                for msg in msgs:
                    for handler in handlers:
                        try:
                            handler(msg.value)
                        except Exception as e:
                            logger.error(f"Handler error for {topic}: {e}")

            self._consumer.commit()


class EventConsumerAsync:
    """
    True async Kafka consumer using aiokafka.
    For high-performance async services.
    """

    def __init__(
        self,
        topic: str,
        group_id: str,
        bootstrap_servers: str = "localhost:9092"
    ):
        self.topic = topic
        self.group_id = group_id
        self.bootstrap_servers = bootstrap_servers
        self._consumer = None
        self._handlers = []

    async def start(self):
        """Initialize async consumer"""
        from aiokafka import AIOKafkaConsumer

        self._consumer = AIOKafkaConsumer(
            self.topic,
            bootstrap_servers=self.bootstrap_servers,
            group_id=self.group_id,
            value_deserializer=lambda v: json.loads(v.decode("utf-8")),
            auto_offset_reset="earliest"
        )
        await self._consumer.start()

    async def stop(self):
        """Stop async consumer"""
        if self._consumer:
            await self._consumer.stop()

    def register_handler(self, handler: Callable[[Dict], None]):
        """Register event handler"""
        self._handlers.append(handler)

    async def consume(self):
        """Consume messages asynchronously"""
        if not self._consumer:
            raise RuntimeError("Consumer not started")

        async for msg in self._consumer:
            for handler in self._handlers:
                try:
                    handler(msg.value)
                except Exception as e:
                    logger.error(f"Handler error: {e}")
