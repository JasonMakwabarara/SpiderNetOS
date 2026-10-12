"""
SpiderNet OS - Policy Registry
Versioned store of trained policies. Each version's weights are saved beside
a registry.json index; a version moves registered -> evaluating -> active,
and promoting one supersedes the previous active version.
"""

import json
import os
import re
from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict, Optional

# The id becomes part of the weights' file name.
VERSION_ID = re.compile(r"[A-Za-z0-9_-][A-Za-z0-9._-]{0,63}")


@dataclass
class PolicyVersion:
    version_id: str
    created_at: str
    description: str
    training_episodes: int
    sim_performance: Dict[str, float] = field(default_factory=dict)
    real_performance: Optional[Dict[str, float]] = None
    status: str = 'registered'
    file_path: str = ''


class PolicyRegistry:
    def __init__(self, registry_path: Optional[str] = None):
        self.registry_path = Path(registry_path or os.getenv('POLICY_REGISTRY_PATH', './policies'))
        self.registry_path.mkdir(parents=True, exist_ok=True)
        self.index_file = self.registry_path / 'registry.json'
        self.versions: Dict[str, PolicyVersion] = {}
        self.active_version: Optional[str] = None
        self._load()

    def _load(self) -> None:
        if not self.index_file.exists():
            return
        data = json.loads(self.index_file.read_text(encoding='utf-8'))
        self.versions = {
            vid: PolicyVersion(**version) for vid, version in data.get('versions', {}).items()
        }
        self.active_version = data.get('active_version')

    def _save(self) -> None:
        data = {
            'versions': {vid: asdict(v) for vid, v in self.versions.items()},
            'active_version': self.active_version,
        }
        tmp = self.index_file.with_suffix('.json.tmp')
        tmp.write_text(json.dumps(data, indent=2), encoding='utf-8')
        tmp.replace(self.index_file)

    def register_version(
        self,
        version_id: str,
        policy_state: Dict,
        description: str,
        training_episodes: int,
        sim_performance: Dict[str, float],
    ) -> PolicyVersion:
        if not VERSION_ID.fullmatch(version_id):
            raise ValueError("version_id may only contain letters, digits, '.', '_' and '-'")
        if version_id in self.versions:
            raise ValueError(f"policy version {version_id} is already registered")

        import torch

        file_path = self.registry_path / f'policy_{version_id}.pt'
        torch.save(policy_state, file_path)

        version = PolicyVersion(
            version_id=version_id,
            created_at=datetime.now(timezone.utc).isoformat(),
            description=description,
            training_episodes=training_episodes,
            sim_performance=sim_performance,
            file_path=str(file_path),
        )
        self.versions[version_id] = version
        self._save()
        return version

    def load_policy_state(self, version_id: str) -> Dict:
        import torch

        version = self.versions[version_id]
        return torch.load(version.file_path, weights_only=False)

    def deploy_version(self, version_id: str) -> bool:
        """Put a version into evaluation. False if it does not exist."""
        version = self.versions.get(version_id)
        if version is None:
            return False
        version.status = 'evaluating'
        self._save()
        return True

    def promote_version(self, version_id: str) -> bool:
        """Make a version the active one. False if it does not exist."""
        version = self.versions.get(version_id)
        if version is None:
            return False
        if self.active_version and self.active_version in self.versions:
            self.versions[self.active_version].status = 'superseded'
        version.status = 'active'
        self.active_version = version_id
        self._save()
        return True
