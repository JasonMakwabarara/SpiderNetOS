"""Persistent agent session manager for Telegram.

Stores and loads Agent SDK session IDs to disk so that sessions
survive bot restarts. Thread-safe with file locking and atomic writes.
"""

import fcntl
import json
import os
import tempfile
from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone


@dataclass
class SessionInfo:
    """Metadata for a single agent session."""

    session_id: str
    model: str
    created: str  # ISO timestamp
    name: str  # Topic name
    total_cost: float = 0.0
    total_turns: int = 0
    last_input_tokens: int = 0


class SessionManager:
    """Manages persistent agent sessions for Telegram.

    Sessions are keyed by topic:
    - "general" for the General topic
    - str(topic_id) for spawned agent topics

    Data is stored in a JSON file with fcntl file locking for
    thread safety. Writes are atomic via tempfile + os.replace.
    """

    def __init__(self, data_dir: str):
        self._path = os.path.join(data_dir, "agent_sessions.json")
        self._sessions: dict[str, SessionInfo] = {}
        os.makedirs(data_dir, exist_ok=True)
        self._load()

    def get(self, topic_key: str) -> SessionInfo | None:
        """Get session info for a topic, or None if not found."""
        # Reload from disk to pick up sessions created by external scripts
        self._load()
        return self._sessions.get(topic_key)

    def save(self, topic_key: str, info: SessionInfo) -> None:
        """Save or update a session for a topic."""
        self._sessions[topic_key] = info
        self._persist()

    def delete(self, topic_key: str) -> None:
        """Remove a session for a topic."""
        if topic_key in self._sessions:
            del self._sessions[topic_key]
            self._persist()

    def list_all(self) -> dict[str, SessionInfo]:
        """Return all active sessions."""
        self._load()
        return dict(self._sessions)

    def update_usage(
        self, topic_key: str, cost: float, turns: int, input_tokens: int
    ) -> None:
        """Update cumulative usage stats for a session."""
        session = self._sessions.get(topic_key)
        if session:
            session.total_cost += cost
            session.total_turns += turns
            session.last_input_tokens = input_tokens
            self._persist()

    def _load(self) -> None:
        """Load sessions from disk."""
        if not os.path.exists(self._path):
            return
        try:
            with open(self._path, "r", encoding="utf-8") as f:
                fcntl.flock(f, fcntl.LOCK_SH)
                try:
                    data = json.load(f)
                finally:
                    fcntl.flock(f, fcntl.LOCK_UN)
            self._sessions = {
                key: SessionInfo(**val) for key, val in data.items()
            }
        except (json.JSONDecodeError, OSError, TypeError):
            # Corrupted file — start fresh
            self._sessions = {}

    def _persist(self) -> None:
        """Atomic write sessions to disk."""
        data = {key: asdict(info) for key, info in self._sessions.items()}
        dir_name = os.path.dirname(self._path)
        fd, tmp_path = tempfile.mkstemp(dir=dir_name, suffix=".tmp")
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as f:
                json.dump(data, f, ensure_ascii=False, indent=2)
            os.replace(tmp_path, self._path)
        except OSError:
            try:
                os.unlink(tmp_path)
            except OSError:
                pass
