"""
Hermes Learning Sync Worker
Synchronizes communication patterns with SpiderNetOS for RL training
"""
import os
import redis
import requests
import schedule
import time
import json
import logging
from datetime import datetime

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Configuration
REDIS_URL = os.getenv("REDIS_URL", "redis://127.0.0.1:6379/2")
SPIDERNET_API_URL = os.getenv("SPIDERNET_API_URL", "http://127.0.0.1:8000")

# Initialize Redis
r = redis.Redis.from_url(REDIS_URL, decode_responses=True)


def sync_learning():
    """Sync learning patterns to SpiderNetOS."""
    try:
        # Get communication patterns from Redis
        patterns = r.lrange("hermes:learning:patterns", 0, 99)
        if patterns:
            payload = {
                "source": "hermes",
                "learning_type": "communication_patterns",
                "entries": [json.loads(p) for p in patterns],
                "sync_timestamp": datetime.utcnow().isoformat()
            }
            # Send to SpiderNetOS for RL training
            response = requests.post(
                f"{SPIDERNET_API_URL}/api/hermes/learning-sync",
                json=payload,
                timeout=30
            )
            response.raise_for_status()
            # Clear processed entries
            r.ltrim("hermes:learning:patterns", 100, -1)
            logger.info(f"Synced {len(patterns)} learning patterns")
    except Exception as e:
        logger.error(f"Sync error: {e}")


def main():
    """Main worker loop."""
    logger.info("Hermes Learning Worker started")
    schedule.every(15).minutes.do(sync_learning)

    while True:
        schedule.run_pending()
        time.sleep(60)


if __name__ == "__main__":
    main()
