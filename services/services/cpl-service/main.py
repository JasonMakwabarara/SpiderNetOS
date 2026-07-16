"""
SpiderNet OS - CPL Service (Control Plane Learning)
FastAPI service with Streaming PPO for real-time RL
"""

import asyncio
import logging
from typing import Dict, List, Optional
from datetime import datetime

from fastapi import FastAPI, HTTPException, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field
import torch
import asyncpg
from kafka import KafkaProducer

from engine.policy_network import PolicyNetwork, StateEncoder
from engine.ppo_trainer import StreamingPPOTrainer, Trajectory
from engine.cost_governor import CostGovernor, BudgetStatus
from shared.kafka.topics import KafkaTopics
from shared.kafka.producer import EventProducer


# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# FastAPI app
app = FastAPI(
    title="SpiderNet OS - CPL Service",
    description="Control Plane Learning with Streaming PPO",
    version="1.0.0"
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


# Pydantic models
class StateUpdateRequest(BaseModel):
    tenant_id: str
    system_graph_embedding: List[float] = Field(..., min_items=256, max_items=256)
    cost_vector: List[float] = Field(..., min_items=6, max_items=6)
    performance_vector: List[float] = Field(..., min_items=6, max_items=6)
    agent_health: List[float] = Field(..., min_items=8, max_items=8)
    queue_depths: List[float] = Field(..., min_items=4, max_items=4)
    
class ActionRequest(BaseModel):
    tenant_id: str
    deterministic: bool = False
    
class ActionResponse(BaseModel):
    action: int
    action_name: str
    log_prob: float
    value: float
    entropy: float
    predicted_cost: float
    confidence: float
    
class TrajectorySegment(BaseModel):
    states: List[List[float]]
    actions: List[int]
    log_probs: List[float]
    rewards: List[float]
    values: List[float]
    costs: List[float]
    dones: List[bool]
    
class TrainingMetrics(BaseModel):
    policy_loss: float
    value_loss: float
    entropy: float
    clip_fraction: float
    lagrangian_multiplier: float
    
class BudgetCheckRequest(BaseModel):
    tenant_id: str
    estimated_cost: float


# Global state
policy_network: Optional[PolicyNetwork] = None
state_encoder: Optional[StateEncoder] = None
ppo_trainer: Optional[StreamingPPOTrainer] = None
cost_governor: Optional[CostGovernor] = None
event_producer: Optional[EventProducer] = None
db_pool: Optional[asyncpg.Pool] = None


@app.on_event("startup")
async def startup():
    """Initialize CPL service components"""
    global policy_network, state_encoder, ppo_trainer, cost_governor, event_producer, db_pool
    
    logger.info("Starting CPL Service...")
    
    # Initialize policy network (GPU optimized for RTX 5090)
    device = 'cuda' if torch.cuda.is_available() else 'cpu'
    logger.info(f"Using device: {device}")
    
    policy_network = PolicyNetwork(
        state_dim=280,
        action_dim=6,
        use_fp16=(device == 'cuda')
    ).to(device)
    
    # Compile for speed (PyTorch 2.0)
    if hasattr(torch, 'compile'):
        policy_network = torch.compile(policy_network, mode='reduce-overhead')
    
    state_encoder = StateEncoder(gnn_embedding_dim=256)
    
    # Initialize PPO trainer
    ppo_trainer = StreamingPPOTrainer(
        policy=policy_network,
        lr_policy=3e-4,
        lr_value=1e-3,
        device=device
    )
    
    # Initialize Cost Governor
    cost_governor = CostGovernor(default_budget=50.0)
    
    # Initialize Kafka producer (optional)
    try:
        event_producer = EventProducer(bootstrap_servers="kafka:29092")
        event_producer.start()
        logger.info("Kafka producer initialized")
    except Exception as e:
        logger.warning(f"Kafka not available: {e}")
        event_producer = None
    
    # Initialize database pool
    try:
        db_pool = await asyncpg.create_pool(
            "postgresql://spidernet:spidernet@localhost:5432/spidernet",
            min_size=5,
            max_size=20
        )
        logger.info("Database connected")
    except Exception as e:
        logger.warning(f"Database connection failed: {e}")
        db_pool = None
    
    logger.info("CPL Service started successfully")


@app.on_event("shutdown")
async def shutdown():
    """Cleanup resources"""
    logger.info("Shutting down CPL Service...")
    
    if event_producer is not None:
        event_producer.stop()
    
    if db_pool is not None:
        await db_pool.close()
    
    logger.info("CPL Service shut down")


@app.get("/health")
async def health():
    """Health check endpoint"""
    return {
        "status": "healthy",
        "service": "cpl",
        "timestamp": datetime.utcnow().isoformat(),
        "gpu_available": torch.cuda.is_available(),
        "gpu_count": torch.cuda.device_count() if torch.cuda.is_available() else 0
    }


@app.post("/state/update", status_code=202)
async def update_state(state: StateUpdateRequest, background_tasks: BackgroundTasks):
    """
    Update system state and trigger CPL processing.
    
    This encodes the state and publishes to Kafka for downstream processing.
    """
    # Encode state
    state_tensor = state_encoder.encode(
        system_graph_embedding=torch.tensor(state.system_graph_embedding),
        cost_vector=torch.tensor(state.cost_vector),
        performance_vector=torch.tensor(state.performance_vector),
        agent_health=torch.tensor(state.agent_health),
        queue_depths=torch.tensor(state.queue_depths)
    )
    
    # Publish to Kafka
    await event_producer.send(
        topic=KafkaTopics.CPL_STATE_UPDATED,
        value={
            "tenant_id": state.tenant_id,
            "state_embedding": state_tensor.tolist(),
            "timestamp": datetime.utcnow().isoformat()
        },
        key=state.tenant_id
    )
    
    return {"status": "queued", "timestamp": datetime.utcnow().isoformat()}


@app.post("/action/select", response_model=ActionResponse)
async def select_action(request: ActionRequest):
    """
    Select action using current policy.
    
    This is called by MetaPlanner for resource allocation decisions.
    """
    if not policy_network:
        raise HTTPException(status_code=503, detail="Policy not initialized")
    
    # Get latest state from database (simplified)
    # In production, this would use cached state
    dummy_state = torch.randn(280, device=policy_network.shared[0].weight.device)
    
    # Select action
    with torch.no_grad():
        action, log_prob, value, entropy = policy_network.get_action(
            dummy_state,
            deterministic=request.deterministic
        )
        predicted_cost = policy_network.predict_cost(dummy_state.unsqueeze(0))
    
    action_map = ['spawn_agent', 'kill_agent', 'route_task', 'allocate_budget', 'adjust_temperature', 'prioritize_workflow']
    
    # Publish action executed event
    await event_producer.send(
        topic=KafkaTopics.CPL_ACTION_EXECUTED,
        value={
            "tenant_id": request.tenant_id,
            "action": int(action.item()),
            "action_name": action_map[int(action.item())],
            "value": float(value.item()),
            "predicted_cost": float(predicted_cost.item()),
            "timestamp": datetime.utcnow().isoformat()
        },
        key=request.tenant_id
    )
    
    return ActionResponse(
        action=int(action.item()),
        action_name=action_map[int(action.item())],
        log_prob=float(log_prob.item()),
        value=float(value.item()),
        entropy=float(entropy.mean().item()),
        predicted_cost=float(predicted_cost.item()),
        confidence=float(torch.exp(log_prob).item())
    )


@app.post("/trajectory/update", response_model=TrainingMetrics)
async def update_trajectory(segment: TrajectorySegment, budget: float = 50.0):
    """
    Update policy from trajectory segment.
    
    Called after execution outcomes are received.
    """
    if not ppo_trainer:
        raise HTTPException(status_code=503, detail="Trainer not initialized")
    
    device = next(policy_network.parameters()).device
    
    # Convert to trajectory
    trajectory = Trajectory(
        states=torch.tensor(segment.states, dtype=torch.float32, device=device),
        actions=torch.tensor(segment.actions, device=device),
        log_probs=torch.tensor(segment.log_probs, device=device),
        rewards=torch.tensor(segment.rewards, device=device),
        values=torch.tensor(segment.values, device=device),
        costs=torch.tensor(segment.costs, device=device),
        dones=torch.tensor(segment.dones, dtype=torch.float32, device=device)
    )
    
    # Update policy
    metrics = ppo_trainer.update(trajectory, budget)
    
    # Publish policy updated event
    await event_producer.send(
        topic=KafkaTopics.CPL_POLICY_UPDATED,
        value={
            "timestamp": datetime.utcnow().isoformat(),
            "metrics": metrics
        }
    )
    
    return TrainingMetrics(**metrics)


@app.post("/budget/check")
async def check_budget(request: BudgetCheckRequest):
    """Check if action is within budget (Cost Governor)"""
    if not cost_governor:
        raise HTTPException(status_code=503, detail="Cost Governor not initialized")
    
    allowed, details = await cost_governor.check_budget(
        request.tenant_id,
        request.estimated_cost
    )
    
    return {
        "allowed": allowed,
        "details": details
    }


@app.get("/budget/status/{tenant_id}")
async def budget_status(tenant_id: str):
    """Get current budget status for tenant"""
    if not cost_governor:
        raise HTTPException(status_code=503, detail="Cost Governor not initialized")
    
    status = await cost_governor.get_budget_status(tenant_id)
    
    return {
        "tenant_id": status.tenant_id,
        "budget_limit": status.budget_limit,
        "spent_today": status.spent_today,
        "remaining": status.remaining,
        "utilization_rate": status.utilization_rate,
        "projected_end_of_day": status.projected_end_of_day,
        "status": status.status
    }


@app.post("/checkpoint/save")
async def save_checkpoint(path: str, episode: int = 0):
    """Save policy checkpoint"""
    if not ppo_trainer:
        raise HTTPException(status_code=503, detail="Trainer not initialized")
    
    ppo_trainer.save_checkpoint(path, episode)
    
    return {"status": "saved", "path": path, "episode": episode}


@app.get("/metrics")
async def get_metrics():
    """Get CPL service metrics"""
    metrics = {
        "producer": event_producer.get_metrics() if event_producer else {},
        "training": ppo_trainer.metrics if ppo_trainer else {},
        "timestamp": datetime.utcnow().isoformat()
    }
    
    return metrics


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=9100)
