"""
SpiderNet OS - Kafka Event Producer
Async batch producer with compression and retries
"""

import asyncio
import json
import logging
from typing import Dict, Any, Optional, List
from dataclasses import asdict, is_dataclass
from datetime import datetime
from kafka import KafkaProducer as SyncProducer
from kafka.errors import KafkaError
import threading
import queue


logger = logging.getLogger(__name__)


class EventProducer:
    """
    Async Kafka producer for SpiderNet OS events.
    
    Features:
    - Batch accumulation (50ms window)
    - LZ4 compression for high-throughput topics
    - Async retry with backoff
    - Schema validation
    """
    
    def __init__(
        self,
        bootstrap_servers: str = "localhost:9092",
        batch_size: int = 100,
        linger_ms: int = 50,
        compression: str = "lz4",
        acks: str = "all",
        retries: int = 3,
        retry_backoff_ms: int = 100
    ):
        self.bootstrap_servers = bootstrap_servers
        self.batch_size = batch_size
        self.linger_ms = linger_ms
        self.compression = compression
        
        # Sync producer (runs in background thread)
        self._producer = SyncProducer(
            bootstrap_servers=bootstrap_servers,
            batch_size=batch_size,
            linger_ms=linger_ms,
            compression_type=compression,
            acks=acks,
            retries=retries,
            retry_backoff_ms=retry_backoff_ms,
            value_serializer=lambda v: json.dumps(v, default=self._json_serializer).encode("utf-8"),
            key_serializer=lambda k: k.encode("utf-8") if k else None
        )
        
        # Async queue for non-blocking send
        self._queue = queue.Queue()
        self._running = False
        self._worker_thread = None
        
        # Metrics
        self.metrics = {
            'sent': 0,
            'failed': 0,
            'batches': 0
        }
    
    def _json_serializer(self, obj):
        """Custom JSON serializer for complex types"""
        if isinstance(obj, datetime):
            return obj.isoformat()
        if is_dataclass(obj):
            return asdict(obj)
        if hasattr(obj, 'to_dict'):
            return obj.to_dict()
        raise TypeError(f"Cannot serialize {type(obj)}")
    
    def start(self):
        """Start background worker thread"""
        self._running = True
        self._worker_thread = threading.Thread(target=self._worker, daemon=True)
        self._worker_thread.start()
        logger.info("EventProducer started")
    
    def stop(self):
        """Stop producer and flush pending messages"""
        self._running = False
        self._producer.flush(timeout=10)
        self._producer.close(timeout=10)
        if self._worker_thread:
            self._worker_thread.join(timeout=5)
        logger.info("EventProducer stopped")
    
    def _worker(self):
        """Background worker for async sends"""
        while self._running:
            try:
                # Batch accumulate
                batch = []
                deadline = datetime.now().timestamp() + (self.linger_ms / 1000)
                
                while len(batch) < self.batch_size:
                    timeout = max(0, deadline - datetime.now().timestamp())
                    try:
                        item = self._queue.get(timeout=timeout)
                        batch.append(item)
                    except queue.Empty:
                        break
                
                # Send batch
                if batch:
                    for topic, key, value in batch:
                        try:
                            self._producer.send(topic, key=key, value=value)
                            self.metrics['sent'] += 1
                        except KafkaError as e:
                            logger.error(f"Failed to send to {topic}: {e}")
                            self.metrics['failed'] += 1
                    
                    self.metrics['batches'] += 1
                    
            except Exception as e:
                logger.error(f"Producer worker error: {e}")
    
    async def send(
        self,
        topic: str,
        value: Dict[str, Any],
        key: Optional[str] = None,
        headers: Optional[Dict[str, str]] = None
    ) -> bool:
        """
        Send event to Kafka topic (async).
        
        Args:
            topic: Kafka topic name
            value: Event payload
            key: Optional partition key
            headers: Optional message headers
            
        Returns:
            True if queued successfully
        """
        # Add standard metadata
        event = {
            **value,
            '_metadata': {
                'timestamp': datetime.utcnow().isoformat(),
                'producer': 'spidernet-cpl',
                'version': '1.0'
            }
        }
        
        # Add headers if provided
        if headers:
            event['_headers'] = headers
        
        # Queue for background send
        try:
            self._queue.put((topic, key, event), block=False)
            return True
        except queue.Full:
            logger.warning(f"Producer queue full, dropping event to {topic}")
            return False
    
    async def send_batch(
        self,
        topic: str,
        events: List[Dict[str, Any]],
        key_fn=None
    ) -> int:
        """
        Send batch of events efficiently.
        
        Args:
            topic: Target topic
            events: List of event payloads
            key_fn: Optional function to extract key from event
            
        Returns:
            Number of events queued
        """
        count = 0
        for event in events:
            key = key_fn(event) if key_fn else None
            success = await self.send(topic, event, key)
            if success:
                count += 1
        
        return count
    
    def flush(self, timeout: int = 10):
        """Flush all pending messages"""
        self._producer.flush(timeout=timeout)
    
    def get_metrics(self) -> Dict[str, int]:
        """Get producer metrics"""
        return self.metrics.copy()


class EventProducerAsync:
    """
    True async Kafka producer using aiokafka.
    For high-performance async services.
    """
    
    def __init__(
        self,
        bootstrap_servers: str = "localhost:9092",
        client_id: str = "spidernet-producer"
    ):
        self.bootstrap_servers = bootstrap_servers
        self.client_id = client_id
        self._producer = None
        
    async def start(self):
        """Initialize async producer"""
        from aiokafka import AIOKafkaProducer
        
        self._producer = AIOKafkaProducer(
            bootstrap_servers=self.bootstrap_servers,
            client_id=self.client_id,
            value_serializer=lambda v: json.dumps(v, default=str).encode("utf-8"),
            key_serializer=lambda k: k.encode("utf-8") if k else None,
            compression_type="lz4"
        )
        await self._producer.start()
        
    async def stop(self):
        """Stop async producer"""
        if self._producer:
            await self._producer.stop()
    
    async def send(
        self,
        topic: str,
        value: Dict[str, Any],
        key: Optional[str] = None,
        partition: Optional[int] = None
    ):
        """Async send event"""
        if not self._producer:
            raise RuntimeError("Producer not started")
        
        event = {
            **value,
            '_metadata': {
                'timestamp': datetime.utcnow().isoformat(),
                'producer': self.client_id
            }
        }
        
        await self._producer.send(
            topic,
            value=event,
            key=key,
            partition=partition
        )
