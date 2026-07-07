#!/usr/bin/env python3
"""
SpiderNetOS Simulation Service File Generator
Run this on Vast.ai to create all simulation files
"""

import os

BASE_DIR = "/workspace/SpiderNetOS/services/simulation-service"

def create_file(path, content):
    """Create file with content"""
    full_path = os.path.join(BASE_DIR, path)
    os.makedirs(os.path.dirname(full_path), exist_ok=True)
    with open(full_path, 'w') as f:
        f.write(content)
    print(f"Created: {path}")

# Create __init__.py files
create_file("__init__.py", "# Simulation Service")
create_file("engine/__init__.py", "# Simulation Engine")
create_file("agents/__init__.py", "# Simulation Agents")

# Market Environment
create_file("engine/market_env.py", '''"""
SpiderNet OS - Market Environment
Stochastic market simulator for safe RL training
"""

import numpy as np
import torch
from typing import Dict, List, Tuple, Optional
from dataclasses import dataclass
from datetime import datetime, timedelta


@dataclass
class MarketState:
    timestamp: datetime
    cpm_meta: float
    cpm_google: float
    cpm_tiktok: float
    competition_index: float
    market_trend: float
    day_of_week: int
    hour: int
    budget_remaining: float
    spend_rate: float


class MarketEnv:
    def __init__(
        self,
        base_cpm_meta: float = 12.0,
        base_cpm_google: float = 8.0,
        base_cpm_tiktok: float = 6.0,
        ou_theta: float = 0.1,
        ou_sigma: float = 0.3,
        jump_intensity: float = 0.05,
        seed: Optional[int] = None
    ):
        self.base_cpm = {
            'meta': base_cpm_meta,
            'google': base_cpm_google,
            'tiktok': base_cpm_tiktok
        }
        self.ou_theta = ou_theta
        self.ou_sigma = ou_sigma
        self.jump_intensity = jump_intensity
        self.rng = np.random.RandomState(seed)
        self.current_state: Optional[MarketState] = None
        self.history: List[MarketState] = []

    def reset(self, initial_budget: float = 1000.0) -> MarketState:
        now = datetime.utcnow()
        self.current_state = MarketState(
            timestamp=now,
            cpm_meta=self.base_cpm['meta'],
            cpm_google=self.base_cpm['google'],
            cpm_tiktok=self.base_cpm['tiktok'],
            competition_index=0.5,
            market_trend=0.0,
            day_of_week=now.weekday(),
            hour=now.hour,
            budget_remaining=initial_budget,
            spend_rate=0.0
        )
        self.history = [self.current_state]
        return self.current_state

    def step(self, action: Dict) -> Tuple[MarketState, float, bool, Dict]:
        cs = self.current_state
        next_time = cs.timestamp + timedelta(hours=1)

        def evolve_cpm(current, base):
            drift = self.ou_theta * (base - current)
            diffusion = self.ou_sigma * self.rng.randn()
            jump = self.rng.normal(0, 0.5) if self.rng.rand() < self.jump_intensity else 0
            return max(current + drift + diffusion + jump, base * 0.3)

        next_cpm_meta = evolve_cpm(cs.cpm_meta, self.base_cpm['meta'])
        next_cpm_google = evolve_cpm(cs.cpm_google, self.base_cpm['google'])
        next_cpm_tiktok = evolve_cpm(cs.cpm_tiktok, self.base_cpm['tiktok'])

        next_competition = np.clip(0.5 + 0.5 * np.sin(2 * np.pi * next_time.weekday() / 7) + 0.1 * self.rng.randn(), 0, 1)
        next_trend = np.clip(0.9 * cs.market_trend + 0.1 * self.rng.randn(), -1, 1)

        budget_spent = self._calculate_spend(action, {
            'meta': next_cpm_meta,
            'google': next_cpm_google,
            'tiktok': next_cpm_tiktok
        })

        next_state = MarketState(
            timestamp=next_time,
            cpm_meta=next_cpm_meta,
            cpm_google=next_cpm_google,
            cpm_tiktok=next_cpm_tiktok,
            competition_index=next_competition,
            market_trend=next_trend,
            day_of_week=next_time.weekday(),
            hour=next_time.hour,
            budget_remaining=cs.budget_remaining - budget_spent,
            spend_rate=budget_spent
        )

        self.current_state = next_state
        self.history.append(next_state)

        done = next_state.budget_remaining <= 0 or len(self.history) >= 30 * 24

        return next_state, 0.0, done, {
            'cpm_meta': next_cpm_meta,
            'cpm_google': next_cpm_google,
            'cpm_tiktok': next_cpm_tiktok,
            'spend': budget_spent
        }

    def _calculate_spend(self, action: Dict, cpms: Dict) -> float:
        allocation = action.get('budget_allocation', 100.0)
        channel_mix = action.get('channel_mix', {'meta': 0.4, 'google': 0.4, 'tiktok': 0.2})
        avg_cpm = sum(channel_mix[ch] * cpms[ch] for ch in ['meta', 'google', 'tiktok'])
        spend = allocation * (avg_cpm / 12.0)
        return min(spend, self.current_state.budget_remaining * 0.1)

    def to_tensor(self, state: Optional[MarketState] = None) -> torch.Tensor:
        s = state or self.current_state
        return torch.tensor([
            s.cpm_meta / 20.0,
            s.cpm_google / 20.0,
            s.cpm_tiktok / 20.0,
            s.competition_index,
            s.market_trend,
            s.day_of_week / 6.0,
            s.hour / 23.0,
            s.budget_remaining / 1000.0,
            s.spend_rate / 100.0
        ], dtype=torch.float32)
''')

# User Behavior
create_file("engine/user_behavior.py", '''"""
SpiderNet OS - User Behavior Model
"""

import numpy as np
from typing import Dict, Tuple, Optional
from dataclasses import dataclass


@dataclass
class UserSegment:
    segment_id: str
    base_conversion_rate: float
    price_sensitivity: float
    channel_affinity: Dict[str, float]
    fatigue_rate: float


class UserBehaviorModel:
    def __init__(self, segments: Optional[list] = None):
        self.segments = segments or [
            UserSegment('early_adopters', 0.05, 0.3, {'meta': 0.5, 'google': 0.3, 'tiktok': 0.2}, 0.1),
            UserSegment('bargain_hunters', 0.03, 0.8, {'meta': 0.3, 'google': 0.4, 'tiktok': 0.3}, 0.15),
            UserSegment('mainstream', 0.02, 0.5, {'meta': 0.4, 'google': 0.4, 'tiktok': 0.2}, 0.2)
        ]
        self.exposures: Dict[str, int] = {s.segment_id: 0 for s in self.segments}

    def predict_conversions(self, impressions: Dict[str, int], segment_distribution: Optional[Dict] = None) -> Tuple[int, Dict]:
        if segment_distribution is None:
            segment_distribution = {s.segment_id: 1.0 / len(self.segments) for s in self.segments}

        total_conversions = 0
        breakdown = {}

        for segment in self.segments:
            seg_weight = segment_distribution.get(segment.segment_id, 0)
            if seg_weight == 0:
                continue

            effective_impressions = sum(impressions.get(ch, 0) * segment.channel_affinity.get(ch, 0) for ch in ['meta', 'google', 'tiktok'])

            L = segment.base_conversion_rate * effective_impressions
            k = 0.001
            x0 = 1000
            saturated_response = L / (1 + np.exp(-k * (effective_impressions - x0)))

            fatigue_factor = np.exp(-segment.fatigue_rate * self.exposures[segment.segment_id])
            segment_conversions = saturated_response * fatigue_factor * seg_weight
            total_conversions += segment_conversions

            breakdown[segment.segment_id] = {
                'conversions': segment_conversions,
                'effective_impressions': effective_impressions,
                'fatigue_factor': fatigue_factor
            }

            self.exposures[segment.segment_id] += effective_impressions

        return int(total_conversions), breakdown

    def reset_fatigue(self):
        self.exposures = {s.segment_id: 0 for s in self.segments}
''')

# Economic Reward
create_file("engine/economic_reward.py", '''"""
SpiderNet OS - Economic Reward Engine
"""

from typing import Dict, Tuple
from dataclasses import dataclass


@dataclass
class EconomicOutcome:
    revenue: float
    costs: float
    gross_profit: float
    roas: float
    cac: float
    payback_period: float


class EconomicRewardEngine:
    def __init__(
        self,
        profit_weight: float = 1.0,
        roas_weight: float = 0.3,
        risk_weight: float = 0.2,
        target_roas: float = 3.0,
        max_cac: float = 30.0,
        margin_rate: float = 0.4
    ):
        self.profit_weight = profit_weight
        self.roas_weight = roas_weight
        self.risk_weight = risk_weight
        self.target_roas = target_roas
        self.max_cac = max_cac
        self.margin_rate = margin_rate

    def compute_reward(self, outcome, market_state: Dict, action: Dict) -> Tuple[float, Dict]:
        profit_component = outcome.gross_profit / 100.0
        roas_gap = outcome.roas - self.target_roas
        roas_component = max(0, roas_gap / self.target_roas)

        risk_component = 0.0
        if outcome.cac > self.max_cac:
            risk_component = (outcome.cac - self.max_cac) / self.max_cac

        spend_smoothness = 1.0 - abs(action.get('budget_allocation', 100) - 100) / 100.0

        reward = (
            self.profit_weight * profit_component +
            self.roas_weight * roas_component -
            self.risk_weight * risk_component +
            0.1 * spend_smoothness
        )

        return reward, {
            'profit_component': profit_component,
            'roas_component': roas_component,
            'risk_component': risk_component,
            'raw_reward': reward
        }

    def compute_outcome(self, conversions: int, total_spend: float, avg_order_value: float = 50.0):
        revenue = conversions * avg_order_value
        costs = total_spend
        gross_profit = revenue * self.margin_rate - costs
        roas = revenue / costs if costs > 0 else 0
        cac = costs / conversions if conversions > 0 else float('inf')
        monthly_revenue = avg_order_value * 0.1
        payback = cac / monthly_revenue if monthly_revenue > 0 else float('inf')

        return EconomicOutcome(
            revenue=revenue,
            costs=costs,
            gross_profit=gross_profit,
            roas=roas,
            cac=cac,
            payback_period=payback
        )
''')

# Sim to Real
create_file("engine/sim_to_real.py", '''"""
SpiderNet OS - Sim-to-Real Bridge
"""

import numpy as np
from typing import Dict, List
from dataclasses import dataclass
from datetime import datetime


@dataclass
class CalibrationData:
    timestamp: datetime
    actual_cpm: Dict[str, float]
    actual_conversions: int
    actual_spend: float
    market_conditions: Dict


class SimToRealBridge:
    def __init__(self, calibration_window: int = 168, learning_rate: float = 0.01):
        self.calibration_window = calibration_window
        self.lr = learning_rate
        self.real_data: List[CalibrationData] = []
        self.param_bias = {'cpm_meta': 0.0, 'cpm_google': 0.0, 'cpm_tiktok': 0.0, 'conversion_rate': 0.0}

    def calibrate(self, sim_outcome: Dict, real_outcome: CalibrationData) -> Dict:
        self.real_data.append(real_outcome)
        if len(self.real_data) > self.calibration_window:
            self.real_data.pop(0)

        for channel in ['meta', 'google', 'tiktok']:
            sim_cpm = sim_outcome.get(f'cpm_{channel}', 0)
            real_cpm = real_outcome.actual_cpm.get(channel, sim_cpm)
            if sim_cpm > 0:
                drift = (real_cpm - sim_cpm) / sim_cpm
                self.param_bias[f'cpm_{channel}'] += self.lr * drift

        return self.get_calibrated_params()

    def get_calibrated_params(self) -> Dict:
        return {
            'cpm_bias': {k: v for k, v in self.param_bias.items() if k.startswith('cpm_')},
            'conversion_bias': self.param_bias['conversion_rate']
        }

    def apply_domain_randomization(self, base_params: Dict, randomize: bool = True) -> Dict:
        if not randomize:
            return base_params

        randomized = {}
        for key, value in base_params.items():
            if isinstance(value, (int, float)):
                noise = np.random.uniform(-0.2, 0.2)
                randomized[key] = value * (1 + noise)
            else:
                randomized[key] = value
        return randomized

    def get_calibration_quality(self) -> Dict:
        if len(self.real_data) < 24:
            return {'quality': 'insufficient_data', 'data_points': len(self.real_data)}
        return {'quality': 'good' if len(self.real_data) > 168 else 'building', 'data_points': len(self.real_data)}
''')

# Campaign Agent
create_file("agents/campaign_agent.py", '''"""
SpiderNet OS - Campaign Execution Agent
"""

from typing import Dict, Optional
from dataclasses import dataclass
from datetime import datetime


@dataclass
class CampaignConfig:
    campaign_id: str
    budget_daily: float
    target_roas: float
    target_cac: float
    channel_mix: Dict[str, float]


class CampaignAgent:
    def __init__(self, event_producer, api_clients: Dict):
        self.event_producer = event_producer
        self.api_clients = api_clients
        self.active_campaigns: Dict[str, CampaignConfig] = {}

    async def execute_action(self, campaign_id: str, action: Dict) -> Dict:
        config = self.active_campaigns.get(campaign_id)
        if not config:
            return {'error': f'Campaign {campaign_id} not found'}

        results = {}
        for channel, weight in action['channel_mix'].items():
            channel_budget = action['budget_allocation'] * weight
            results[channel] = {
                'channel': channel,
                'budget_set': channel_budget,
                'status': 'active'
            }

        if self.event_producer:
            await self.event_producer.send('execution.outcome', {
                'campaign_id': campaign_id,
                'action': action,
                'results': results,
                'timestamp': datetime.utcnow().isoformat()
            })

        return results
''')

# Policy Registry
create_file("agents/policy_registry.py", '''"""
SpiderNet OS - Policy Registry
"""

import json
import torch
from typing import Dict, Optional
from dataclasses import dataclass, asdict
from datetime import datetime
from pathlib import Path


@dataclass
class PolicyVersion:
    version_id: str
    created_at: str
    description: str
    training_episodes: int
    sim_performance: Dict
    real_performance: Optional[Dict]
    status: str
    checkpoint_path: str


class PolicyRegistry:
    def __init__(self, registry_path: str = "./policies"):
        self.registry_path = Path(registry_path)
        self.registry_path.mkdir(parents=True, exist_ok=True)
        self.versions: Dict[str, PolicyVersion] = {}
        self.active_version: Optional[str] = None
        self._load_registry()

    def _load_registry(self):
        registry_file = self.registry_path / "registry.json"
        if registry_file.exists():
            with open(registry_file) as f:
                data = json.load(f)
                for v in data.get('versions', []):
                    self.versions[v['version_id']] = PolicyVersion(**v)
                self.active_version = data.get('active_version')

    def _save_registry(self):
        registry_file = self.registry_path / "registry.json"
        with open(registry_file, 'w') as f:
            json.dump({
                'versions': [asdict(v) for v in self.versions.values()],
                'active_version': self.active_version
            }, f, indent=2)

    def register_version(self, version_id: str, policy_state: Dict, description: str, training_episodes: int, sim_performance: Dict):
        checkpoint_path = str(self.registry_path / f"{version_id}.pt")
        torch.save(policy_state, checkpoint_path)

        version = PolicyVersion(
            version_id=version_id,
            created_at=datetime.utcnow().isoformat(),
            description=description,
            training_episodes=training_episodes,
            sim_performance=sim_performance,
            real_performance=None,
            status='training',
            checkpoint_path=checkpoint_path
        )

        self.versions[version_id] = version
        self._save_registry()
        return version

    def deploy_version(self, version_id: str) -> bool:
        if version_id not in self.versions:
            return False
        self.versions[version_id].status = 'evaluating'
        self._save_registry()
        return True

    def promote_version(self, version_id: str) -> bool:
        if version_id not in self.versions:
            return False
        self.active_version = version_id
        self.versions[version_id].status = 'deployed'
        self._save_registry()
        return True
''')

# Simple main.py (without DeepSeek dependency)
create_file("main.py", '''"""
SpiderNet OS - Simulation Service
Port: 9200
"""

import asyncio
from typing import Dict, Optional
from datetime import datetime
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field

from engine.market_env import MarketEnv, MarketState
from engine.user_behavior import UserBehaviorModel
from engine.economic_reward import EconomicRewardEngine, EconomicOutcome
from engine.sim_to_real import SimToRealBridge, CalibrationData
from agents.campaign_agent import CampaignAgent, CampaignConfig
from agents.policy_registry import PolicyRegistry, PolicyVersion


class SimulationStartRequest(BaseModel):
    episodes: int = Field(default=100, ge=1, le=10000)
    budget: float = Field(default=1000.0, ge=100)
    randomize: bool = Field(default=True)


class CampaignActionRequest(BaseModel):
    campaign_id: str
    budget_allocation: float = Field(..., ge=0)
    channel_mix: Dict[str, float]


# Global state
market_env: Optional[MarketEnv] = None
user_model: Optional[UserBehaviorModel] = None
reward_engine: Optional[EconomicRewardEngine] = None
sim_to_real: Optional[SimToRealBridge] = None
campaign_agent: Optional[CampaignAgent] = None
policy_registry: Optional[PolicyRegistry] = None


@asynccontextmanager
async def lifespan(app: FastAPI):
    global market_env, user_model, reward_engine, sim_to_real
    global campaign_agent, policy_registry

    print("Starting SpiderNetOS Simulation Service...")

    market_env = MarketEnv(seed=42)
    user_model = UserBehaviorModel()
    reward_engine = EconomicRewardEngine()
    sim_to_real = SimToRealBridge()
    policy_registry = PolicyRegistry()
    campaign_agent = CampaignAgent(event_producer=None, api_clients={})

    print("Simulation Service ready on port 9200")
    yield
    print("Shutting down...")


app = FastAPI(
    title="SpiderNet OS - Simulation Service",
    version="1.0.0",
    lifespan=lifespan
)

app.add_middleware(CORSMiddleware, allow_origins=["*"], allow_credentials=True, allow_methods=["*"], allow_headers=["*"])


@app.get("/health")
async def health():
    return {
        "status": "healthy",
        "service": "simulation",
        "timestamp": datetime.utcnow().isoformat()
    }


@app.post("/simulation/start")
async def start_simulation(request: SimulationStartRequest, background_tasks: BackgroundTasks):
    background_tasks.add_task(_run_training, request.episodes, request.budget, request.randomize)
    return {"status": "started", "episodes": request.episodes}


async def _run_training(episodes: int, budget: float, randomize: bool):
    global market_env, user_model, reward_engine

    for episode in range(episodes):
        state = market_env.reset(initial_budget=budget)
        episode_reward = 0.0
        done = False
        hour = 0

        while not done:
            action = {'budget_allocation': 100.0, 'channel_mix': {'meta': 0.4, 'google': 0.4, 'tiktok': 0.2}}
            if randomize:
                action = sim_to_real.apply_domain_randomization(action)

            next_state, _, done, info = market_env.step(action)

            impressions = {ch: int(info[f'cpm_{ch}'] * action['budget_allocation'] / 1000) for ch in ['meta', 'google', 'tiktok']}
            conversions, _ = user_model.predict_conversions(impressions)

            outcome = reward_engine.compute_outcome(conversions=conversions, total_spend=info['spend'])
            reward, _ = reward_engine.compute_reward(outcome, {'cpm': info}, action)

            episode_reward += reward
            state = next_state
            hour += 1

        if episode % 10 == 0:
            print(f"Episode {episode}/{episodes}: reward={episode_reward:.2f}")


@app.post("/campaign/execute")
async def execute_campaign(request: CampaignActionRequest):
    global campaign_agent, sim_to_real
    results = await campaign_agent.execute_action(
        campaign_id=request.campaign_id,
        action={'budget_allocation': request.budget_allocation, 'channel_mix': request.channel_mix}
    )
    return {"status": "executed", "results": results, "calibration": sim_to_real.get_calibration_quality()}


@app.get("/calibration/status")
async def calibration_status():
    global sim_to_real
    return sim_to_real.get_calibration_quality()


@app.post("/policy/register")
async def register_policy(version_id: str, description: str):
    global policy_registry
    version = policy_registry.register_version(
        version_id=version_id,
        policy_state={'version': version_id},
        description=description,
        training_episodes=1000,
        sim_performance={'avg_reward': 150.0}
    )
    return {"version_id": version.version_id, "status": version.status}


@app.get("/metrics")
async def get_metrics():
    global market_env, policy_registry
    return {
        "timestamp": datetime.utcnow().isoformat(),
        "market_history": len(market_env.history) if market_env else 0,
        "policy_versions": len(policy_registry.versions) if policy_registry else 0
    }


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=9200)
''')

# Create requirements.txt
create_file("requirements.txt", '''fastapi==0.104.1
uvicorn[standard]==0.24.0
pydantic==2.5.0
numpy==1.24.3
torch==2.1.1
''')

print("\\n" + "="*50)
print("All simulation service files created!")
print(f"Location: {BASE_DIR}")
print("="*50)
print("\\nTo start the service:")
print(f"  cd {BASE_DIR}")
print("  pip install -r requirements.txt")
print("  python3 main.py")
"""

if __name__ == "__main__":
    # This script creates files when run
    print("This script should be copied to Vast.ai and run there:")
    print("  python3 create-simulation-files.py")
