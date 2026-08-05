"""
SpiderNet OS - Simulation Service
FastAPI service with DeepSeek-powered scenario generation and calibration
Port: 9200
"""

import asyncio
import logging
from typing import Dict, List, Optional
from datetime import datetime
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import FastAPI, HTTPException, BackgroundTasks, Query
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
import torch
import numpy as np

# Simulation components
from engine.market_env import MarketEnv, MarketState
from engine.user_behavior import UserBehaviorModel, UserSegment
from engine.economic_reward import EconomicRewardEngine, EconomicOutcome
from engine.sim_to_real import SimToRealBridge, CalibrationData
from engine.deepseek_scenario_generator import DeepSeekScenarioGenerator, MarketScenario
from engine.deepseek_calibration_analyzer import DeepSeekCalibrationAnalyzer, CalibrationInsight

# Agents
from agents.campaign_agent import CampaignAgent, CampaignConfig
from agents.policy_registry import PolicyRegistry, PolicyVersion

# Shared components
from shared.kafka.topics import KafkaTopics
from shared.kafka.producer import EventProducer
from shared.kafka.consumer import EventConsumer


# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


# Pydantic models
class SimulationStartRequest(BaseModel):
    episodes: int = Field(default=100, ge=1, le=10000)
    budget: float = Field(default=1000.0, ge=100)
    randomize: bool = Field(default=True)
    use_deepseek_scenarios: bool = Field(default=True)
    difficulty: str = Field(default="medium", regex="^(easy|medium|hard|extreme)$")


class CampaignActionRequest(BaseModel):
    campaign_id: str
    budget_allocation: float = Field(..., ge=0)
    channel_mix: Dict[str, float] = Field(..., description="Channel weights (must sum to 1.0)")


class PolicyVersionRequest(BaseModel):
    version_id: str
    description: str


class DeploymentRequest(BaseModel):
    version_id: str
    rollout_percentage: float = Field(default=10.0, ge=0, le=100)


class CalibrationGapRequest(BaseModel):
    metric_name: str
    hours_of_data: int = Field(default=24, ge=1, le=168)


# Global state
market_env: Optional[MarketEnv] = None
user_model: Optional[UserBehaviorModel] = None
reward_engine: Optional[EconomicRewardEngine] = None
sim_to_real: Optional[SimToRealBridge] = None
campaign_agent: Optional[CampaignAgent] = None
policy_registry: Optional[PolicyRegistry] = None
event_producer: Optional[EventProducer] = None

# DeepSeek components
deepseek_scenarios: Optional[DeepSeekScenarioGenerator] = None
deepseek_calibration: Optional[DeepSeekCalibrationAnalyzer] = None
active_scenario: Optional[MarketScenario] = None


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Service lifespan manager"""
    global market_env, user_model, reward_engine, sim_to_real
    global campaign_agent, policy_registry, event_producer
    global deepseek_scenarios, deepseek_calibration
    
    logger.info("=" * 60)
    logger.info("Starting SpiderNetOS Simulation Service v2.0")
    logger.info("Features: DeepSeek Scenario Generation + RL Training")
    logger.info("=" * 60)
    
    # Initialize simulation components
    market_env = MarketEnv(seed=42)
    user_model = UserBehaviorModel()
    reward_engine = EconomicRewardEngine()
    sim_to_real = SimToRealBridge()
    policy_registry = PolicyRegistry()
    
    # Initialize DeepSeek components
    try:
        deepseek_scenarios = DeepSeekScenarioGenerator()
        deepseek_calibration = DeepSeekCalibrationAnalyzer()
        logger.info("✓ DeepSeek components initialized")
    except Exception as e:
        logger.warning(f"⚠ DeepSeek not available: {e}")
        deepseek_scenarios = None
        deepseek_calibration = None
    
    # Initialize Kafka
    try:
        event_producer = EventProducer(
            bootstrap_servers="localhost:9092",
            client_id="simulation-service"
        )
        event_producer.start()
        logger.info("✓ Kafka producer connected")
    except Exception as e:
        logger.warning(f"⚠ Kafka not available: {e}")
        event_producer = None
    
    # Initialize campaign agent
    campaign_agent = CampaignAgent(
        event_producer=event_producer,
        api_clients={}
    )
    
    # Load or generate initial scenario
    if deepseek_scenarios:
        logger.info("Generating initial DeepSeek scenario...")
        active_scenario = deepseek_scenarios.generate_scenario(
            difficulty="medium",
            scenario_type="steady_market"
        )
        logger.info(f"✓ Scenario loaded: {active_scenario.name}")
    
    logger.info("Simulation Service ready on port 9200")
    logger.info("=" * 60)
    
    yield
    
    # Cleanup
    logger.info("Shutting down Simulation Service...")
    if event_producer:
        event_producer.stop()
    logger.info("Shutdown complete")


app = FastAPI(
    title="SpiderNet OS - Simulation Service",
    description="RL training environment with DeepSeek-powered scenario generation",
    version="2.0.0",
    lifespan=lifespan
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


@app.get("/health")
async def health():
    """Health check with component status"""
    return {
        "status": "healthy",
        "service": "simulation",
        "version": "2.0.0",
        "timestamp": datetime.utcnow().isoformat(),
        "components": {
            "market_env": market_env is not None,
            "user_model": user_model is not None,
            "reward_engine": reward_engine is not None,
            "sim_to_real": sim_to_real is not None,
            "policy_registry": policy_registry is not None,
            "deepseek_scenarios": deepseek_scenarios is not None,
            "deepseek_calibration": deepseek_calibration is not None
        },
        "active_scenario": active_scenario.name if active_scenario else None
    }


@app.post("/scenario/generate")
async def generate_scenario(
    difficulty: str = Query("medium", enum=["easy", "medium", "hard", "extreme"]),
    scenario_type: str = Query("viral_competitor", enum=[
        "steady_market", "viral_competitor", "supply_shock", 
        "platform_change", "economic_downturn"
    ])
):
    """Generate AI-designed training scenario using DeepSeek"""
    global deepseek_scenarios, active_scenario
    
    if not deepseek_scenarios:
        raise HTTPException(status_code=503, detail="DeepSeek not available")
    
    scenario = deepseek_scenarios.generate_scenario(
        difficulty=difficulty,
        scenario_type=scenario_type
    )
    
    active_scenario = scenario
    
    return {
        "scenario_id": scenario.scenario_id,
        "name": scenario.name,
        "description": scenario.description,
        "difficulty": scenario.difficulty,
        "duration_hours": scenario.duration_hours,
        "events_count": len(scenario.events),
        "learning_objective": scenario.learning_objective,
        "base_conditions": scenario.base_conditions
    }


@app.post("/scenario/curriculum")
async def generate_curriculum(
    stages: int = Query(5, ge=3, le=10),
    progression_type: str = Query("gradual", enum=["gradual", "shock", "cyclical"])
):
    """Generate full training curriculum with DeepSeek"""
    global deepseek_scenarios
    
    if not deepseek_scenarios:
        raise HTTPException(status_code=503, detail="DeepSeek not available")
    
    curriculum = deepseek_scenarios.generate_curriculum(
        num_stages=stages,
        progression_type=progression_type
    )
    
    return {
        "stages": stages,
        "progression_type": progression_type,
        "scenarios": [
            {
                "stage": i + 1,
                "id": s.scenario_id,
                "name": s.name,
                "difficulty": s.difficulty,
                "duration_hours": s.duration_hours,
                "objective": s.learning_objective
            }
            for i, s in enumerate(curriculum)
        ]
    }


@app.post("/simulation/start")
async def start_simulation(
    request: SimulationStartRequest,
    background_tasks: BackgroundTasks
):
    """Start training simulation with optional DeepSeek scenarios"""
    global active_scenario, deepseek_scenarios
    
    # Generate scenario if requested and none active
    if request.use_deepseek_scenarios and deepseek_scenarios and not active_scenario:
        active_scenario = deepseek_scenarios.generate_scenario(
            difficulty=request.difficulty,
            scenario_type="viral_competitor"
        )
        logger.info(f"Generated scenario: {active_scenario.name}")
    
    background_tasks.add_task(
        _run_training_simulation,
        episodes=request.episodes,
        budget=request.budget,
        randomize=request.randomize
    )
    
    return {
        "status": "started",
        "episodes": request.episodes,
        "scenario": active_scenario.name if active_scenario else "default",
        "deepseek_enabled": request.use_deepseek_scenarios and deepseek_scenarios is not None,
        "timestamp": datetime.utcnow().isoformat()
    }


async def _run_training_simulation(
    episodes: int,
    budget: float,
    randomize: bool
):
    """Run training simulation with scenario events"""
    global market_env, user_model, reward_engine, event_producer
    global active_scenario, sim_to_real
    
    for episode in range(episodes):
        # Apply scenario base conditions
        if active_scenario:
            # Override market env with scenario conditions
            for key, value in active_scenario.base_conditions.items():
                if hasattr(market_env, key):
                    setattr(market_env, key, value)
        
        state = market_env.reset(initial_budget=budget)
        
        episode_reward = 0.0
        episode_data = []
        
        done = False
        hour = 0
        
        while not done:
            # Check for scenario events
            if active_scenario:
                for event in active_scenario.events:
                    if event["hour"] == hour:
                        logger.info(f"Scenario event: {event['description']}")
                        # Apply event effects to market
                        effects = event.get("effects", {})
                        for key, multiplier in effects.items():
                            if key in market_env.base_cpm:
                                market_env.base_cpm[key] *= multiplier
            
            # Get policy action (placeholder - would call CPL service)
            action = {
                'budget_allocation': 100.0,
                'channel_mix': {'meta': 0.4, 'google': 0.4, 'tiktok': 0.2}
            }
            
            # Apply domain randomization
            if randomize and sim_to_real:
                action = sim_to_real.apply_domain_randomization(action)
            
            # Step environment
            next_state, _, done, info = market_env.step(action)
            
            # Simulate user behavior
            impressions = {
                ch: int(info[f'cpm_{ch}'] * action['budget_allocation'] / 1000)
                for ch in ['meta', 'google', 'tiktok']
            }
            
            conversions, _ = user_model.predict_conversions(impressions)
            
            # Calculate outcome and reward
            outcome = reward_engine.compute_outcome(
                conversions=conversions,
                total_spend=info['spend']
            )
            
            reward, metrics = reward_engine.compute_reward(
                outcome=outcome,
                market_state={'cpm': info},
                action=action
            )
            
            episode_reward += reward
            episode_data.append({
                'hour': hour,
                'state': state,
                'action': action,
                'reward': reward,
                'outcome': outcome.__dict__
            })
            
            state = next_state
            hour += 1
        
        # Publish episode complete
        if event_producer:
            await event_producer.send(
                topic=KafkaTopics.SIMULATION_EPISODE_END,
                value={
                    'episode': episode,
                    'total_reward': episode_reward,
                    'scenario': active_scenario.scenario_id if active_scenario else 'default',
                    'timestamp': datetime.utcnow().isoformat()
                }
            )
        
        if episode % 10 == 0:
            logger.info(f"Episode {episode}/{episodes} complete: reward={episode_reward:.2f}")
    
    logger.info(f"Training complete: {episodes} episodes")


@app.post("/campaign/execute")
async def execute_campaign_action(request: CampaignActionRequest):
    """Execute action on real campaign with calibration checks"""
    global campaign_agent, sim_to_real
    
    cal_quality = sim_to_real.get_calibration_quality()
    
    # Warn if calibration insufficient
    if cal_quality['quality'] == 'insufficient_data':
        logger.warning("Executing with insufficient calibration data")
    
    results = await campaign_agent.execute_action(
        campaign_id=request.campaign_id,
        action={
            'budget_allocation': request.budget_allocation,
            'channel_mix': request.channel_mix
        }
    )
    
    return {
        "status": "executed",
        "campaign_id": request.campaign_id,
        "results": results,
        "calibration_quality": cal_quality
    }


@app.post("/calibration/analyze")
async def analyze_calibration_gap(request: CalibrationGapRequest):
    """Use DeepSeek to analyze sim-to-real gap"""
    global deepseek_calibration, sim_to_real
    
    if not deepseek_calibration:
        raise HTTPException(status_code=503, detail="DeepSeek calibration analyzer not available")
    
    # Get historical data from sim_to_real bridge
    # (In production, this would fetch from database)
    sim_values = [10.0 + np.random.randn() for _ in range(request.hours_of_data)]
    real_values = [12.0 + np.random.randn() for _ in range(request.hours_of_data)]
    
    market_context = {
        'competition_index': 0.5,
        'market_trend': 0.1,
        'day_of_week': datetime.utcnow().weekday(),
        'active_campaigns': 5
    }
    
    insight = deepseek_calibration.analyze_gap(
        metric_name=request.metric_name,
        sim_values=sim_values,
        real_values=real_values,
        market_context=market_context
    )
    
    return {
        "metric": insight.metric_name,
        "gap_percentage": insight.gap_percentage,
        "root_cause": insight.root_cause,
        "suggested_fix": insight.suggested_fix,
        "confidence": insight.confidence,
        "timestamp": insight.timestamp
    }


@app.get("/calibration/report")
async def calibration_report():
    """Generate human-readable calibration report using DeepSeek"""
    global deepseek_calibration, sim_to_real
    
    if not deepseek_calibration:
        raise HTTPException(status_code=503, detail="DeepSeek not available")
    
    # Collect all gaps
    all_gaps = deepseek_calibration.insight_history[-20:]
    bridge_stats = sim_to_real.get_calibration_quality()
    
    report = deepseek_calibration.generate_calibration_report(all_gaps, bridge_stats)
    
    return {
        "report": report,
        "metrics_analyzed": len(all_gaps),
        "calibration_quality": bridge_stats
    }


@app.post("/policy/explain-decision")
async def explain_policy_decision(
    policy_action: Dict,
    outcome: Dict
):
    """Use DeepSeek to explain a policy decision"""
    global deepseek_scenarios, active_scenario
    
    if not deepseek_scenarios:
        raise HTTPException(status_code=503, detail="DeepSeek not available")
    
    explanation = deepseek_scenarios.explain_policy_decision(
        scenario=active_scenario or deepseek_scenarios._default_scenario("medium", "default"),
        policy_action=policy_action,
        outcome=outcome
    )
    
    return {
        "explanation": explanation,
        "scenario": active_scenario.name if active_scenario else "default"
    }


@app.post("/policy/suggest-reward-improvements")
async def suggest_reward_improvements(
    training_history: List[Dict]
):
    """Use DeepSeek to analyze reward function and suggest improvements"""
    global deepseek_scenarios, reward_engine
    
    if not deepseek_scenarios:
        raise HTTPException(status_code=503, detail="DeepSeek not available")
    
    current_reward = f"""
    profit_weight: {reward_engine.profit_weight}
    roas_weight: {reward_engine.roas_weight}
    risk_weight: {reward_engine.risk_weight}
    target_roas: {reward_engine.target_roas}
    """
    
    suggestions = deepseek_scenarios.suggest_reward_shaping(
        training_history=training_history,
        current_reward_function=current_reward
    )
    
    return suggestions


@app.post("/policy/register")
async def register_policy_version(request: PolicyVersionRequest):
    """Register new policy version"""
    global policy_registry
    
    policy_state = {
        'version': request.version_id,
        'timestamp': datetime.utcnow().isoformat()
    }
    
    version = policy_registry.register_version(
        version_id=request.version_id,
        policy_state=policy_state,
        description=request.description,
        training_episodes=1000,
        sim_performance={
            'avg_reward': 150.0,
            'profit': 5000.0,
            'roas': 3.5
        }
    )
    
    return {
        "version_id": version.version_id,
        "status": version.status,
        "created_at": version.created_at
    }


@app.post("/policy/deploy")
async def deploy_policy(request: DeploymentRequest):
    """Deploy policy with gradual rollout"""
    global policy_registry, event_producer
    
    success = policy_registry.deploy_version(request.version_id)
    if not success:
        raise HTTPException(status_code=404, detail="Version not found")
    
    if event_producer:
        await event_producer.send(
            topic=KafkaTopics.SIMULATION_DEPLOYMENT_REQUEST,
            value={
                'version_id': request.version_id,
                'rollout_percentage': request.rollout_percentage,
                'timestamp': datetime.utcnow().isoformat()
            }
        )
    
    return {
        "status": "deployment_initiated",
        "version_id": request.version_id,
        "rollout_percentage": request.rollout_percentage
    }


@app.get("/metrics")
async def get_metrics():
    """Get comprehensive simulation metrics"""
    global market_env, policy_registry, sim_to_real, active_scenario
    
    return {
        "timestamp": datetime.utcnow().isoformat(),
        "market_env": {
            "history_length": len(market_env.history) if market_env else 0,
            "current_budget": market_env.current_state.budget_remaining if market_env and market_env.current_state else 0
        },
        "policy": {
            "versions": len(policy_registry.versions) if policy_registry else 0,
            "active_version": policy_registry.active_version if policy_registry else None
        },
        "calibration": sim_to_real.get_calibration_quality() if sim_to_real else {},
        "scenario": {
            "active": active_scenario.name if active_scenario else None,
            "id": active_scenario.scenario_id if active_scenario else None
        } if active_scenario else None
    }


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=9200)
