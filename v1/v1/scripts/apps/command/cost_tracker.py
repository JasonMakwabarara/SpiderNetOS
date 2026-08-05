"""JSONL cost tracking for AI Command Bot tasks.

Append-only JSONL log file with fcntl locking for concurrent access.
Each line is a JSON object representing one task's cost entry.
"""

import fcntl
import json
from datetime import datetime, timezone
from pathlib import Path


class CostTracker:
    """Tracks task costs in an append-only JSONL file."""

    def __init__(self, log_dir: Path):
        self.log_dir = log_dir
        self.log_file = log_dir / "costs.jsonl"

    def log(self, task) -> None:
        """Append a cost entry for a completed task."""
        entry = {
            "task_id": task.task_id,
            "topic_id": task.topic_id,
            "topic_name": task.topic_name,
            "prompt_preview": task.prompt[:100],
            "model": task.model,
            "cost_usd": task.cost_usd,
            "duration_ms": task.duration_ms,
            "num_turns": task.num_turns,
            "started_at": task.started_at.isoformat(),
            "completed_at": (task.completed_at or datetime.now(timezone.utc)).isoformat(),
            "status": task.status.value,
        }
        self.log_dir.mkdir(parents=True, exist_ok=True)
        with open(self.log_file, "a") as f:
            fcntl.flock(f, fcntl.LOCK_EX)
            f.write(json.dumps(entry) + "\n")
            fcntl.flock(f, fcntl.LOCK_UN)

    def log_inline(self, topic_key: str, cost_usd: float) -> None:
        """Append a cost entry for an inline agent interaction (no Task object)."""
        entry = {
            "task_id": f"inline-{topic_key}",
            "topic_id": topic_key,
            "topic_name": f"Agent ({topic_key})",
            "prompt_preview": "(inline)",
            "model": "sonnet",
            "cost_usd": cost_usd,
            "duration_ms": 0,
            "num_turns": 0,
            "started_at": datetime.now(timezone.utc).isoformat(),
            "completed_at": datetime.now(timezone.utc).isoformat(),
            "status": "completed",
        }
        self.log_dir.mkdir(parents=True, exist_ok=True)
        with open(self.log_file, "a") as f:
            fcntl.flock(f, fcntl.LOCK_EX)
            f.write(json.dumps(entry) + "\n")
            fcntl.flock(f, fcntl.LOCK_UN)

    def read_costs(self, since: str | None = None) -> list[dict]:
        """Read cost entries, optionally filtered by date prefix (YYYY-MM-DD)."""
        if not self.log_file.exists():
            return []
        entries = []
        with open(self.log_file) as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                entry = json.loads(line)
                if since and entry.get("started_at", "") < since:
                    continue
                entries.append(entry)
        return entries

    def daily_total(self) -> float:
        """Sum of today's task costs."""
        today = datetime.now(timezone.utc).strftime("%Y-%m-%d")
        entries = self.read_costs(since=today)
        return sum(e.get("cost_usd", 0) for e in entries)
