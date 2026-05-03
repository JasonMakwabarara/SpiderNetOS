# SpiderNet OS - Simulation Service v2.0

**DeepSeek-Powered RL Training Environment**

This service provides a safe simulation environment for training RL policies before risking real capital, with DeepSeek v4 integration for enhanced scenario generation and calibration analysis.

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                    DeepSeek v4 (Vast.ai)                        │
│  ┌─────────────┐  ┌──────────────┐  ┌─────────────────────┐    │
│  │  Scenario   │  │ Calibration  │  │ Policy Explanation │    │
│  │  Generator  │  │  Analyzer    │  │                    │    │
│  └──────┬──────┘  └──────┬───────┘  └─────────┬──────────┘    │
│         │                │                    │               │
└─────────┼────────────────┼────────────────────┼───────────────┘
          │                │                    │
          ▼                ▼                    ▼
┌─────────────────────────────────────────────────────────────────┐
│              Simulation Service (Port 9200)                     │
│  ┌──────────────┐  ┌──────────────┐  ┌─────────────────────┐   │
│  │  MarketEnv   │  │   UserModel  │  │  EconomicReward     │   │
│  │  (OU Process)│  │ (Logistic)   │  │  (Profit-based)     │   │
│  └──────┬───────┘  └──────┬───────┘  └─────────┬───────────┘   │
│         │                │                    │               │
│  ┌──────▼────────────────▼────────────────────▼───────────┐      │
│  │              Training Loop (PPO)                      │      │
│  │  • Episode generation  • GAE  • Policy updates         │      │
│  └───────────────────────┬───────────────────────────────┘      │
│                          │                                      │
│  ┌───────────────────────▼───────────────────────────────┐      │
│  │              Sim-to-Real Bridge                        │      │
│  │  • Online calibration  • Domain randomization        │      │
│  └───────────────────────┬───────────────────────────────┘      │
└──────────────────────────┼─────────────────────────────────────┘
                           │
          ┌────────────────┼────────────────┐
          │                │                │
          ▼                ▼                ▼
   ┌──────────────┐  ┌──────────────┐  ┌──────────────┐
   │   CPL Service│  │  Campaign    │  │   Policy     │
   │   (Port 9100)│  │   Agent        │  │   Registry   │
   │              │  │   (Real APIs)  │  │              │
   └──────────────┘  └──────────────┘  └──────────────┘
```

## Key Features

### 1. DeepSeek Scenario Generation
- **AI-designed market scenarios**: Viral competitors, supply shocks, platform changes
- **Progressive difficulty**: Easy → Medium → Hard → Extreme
- **Curriculum learning**: Multi-stage training programs
- **Scenario explanations**: Understand what each scenario teaches

### 2. Market Simulation
- **Ornstein-Uhlenbeck process**: Realistic CPM mean reversion
- **Jump diffusion**: Competitor/viral shocks
- **Seasonal patterns**: Day-of-week and hourly effects
- **Channel dynamics**: Meta, Google, TikTok with different behaviors

### 3. User Behavior Model
- **Logistic response curves**: Diminishing returns
- **Ad fatigue**: Exponential decay over time
- **Segment-based**: Early adopters, bargain hunters, mainstream
- **Channel affinity**: Different segments prefer different channels

### 4. Economic Reward Engine
```
Reward = α × Profit + β × ROAS - γ × Risk + δ × Smoothness

Where:
- α = profit_weight (default: 1.0)
- β = roas_weight (default: 0.3)
- γ = risk_weight (default: 0.2)
- δ = smoothness bonus (default: 0.1)
```

### 5. DeepSeek Calibration Analysis
- **Gap identification**: Why simulation diverges from reality
- **Root cause analysis**: Model limitations vs market changes
- **Predictive drift**: Forecast future calibration needs
- **Human-readable reports**: Operator-friendly explanations

## API Endpoints

### Scenario Management
```bash
# Generate AI-designed scenario
POST /scenario/generate?difficulty=hard&scenario_type=viral_competitor

# Generate full training curriculum
POST /scenario/curriculum?stages=5&progression_type=gradual
```

### Training
```bash
# Start training with DeepSeek scenarios
POST /simulation/start
{
  "episodes": 1000,
  "budget": 5000,
  "randomize": true,
  "use_deepseek_scenarios": true,
  "difficulty": "hard"
}
```

### Calibration
```bash
# Analyze sim-to-real gap with DeepSeek
POST /calibration/analyze
{
  "metric_name": "cpm_meta",
  "hours_of_data": 48
}

# Get human-readable calibration report
GET /calibration/report
```

### Policy Management
```bash
# Register new policy version
POST /policy/register
{
  "version_id": "v2.1.0",
  "description": "Improved ROAS optimization"
}

# Deploy with gradual rollout
POST /policy/deploy
{
  "version_id": "v2.1.0",
  "rollout_percentage": 10
}

# Explain a policy decision
POST /policy/explain-decision
{
  "policy_action": {...},
  "outcome": {...}
}
```

## Integration with MetaPlanner

```python
from intelligence.core.simulation_aware_planner import SimulationAwarePlanner

# Initialize with DeepSeek and simulation
planner = SimulationAwarePlanner(
    redis_client=redis,
    event_store=event_store,
    cost_governor=cost_governor,
    use_deepseek=True,
    simulation_service_url="http://localhost:9200"
)

# Plan with simulation validation
result = await planner.plan_with_simulation(
    tenant_id="tenant-1",
    command="Launch marketing campaign",
    context={"budget": 5000, "load": 45},
    simulate_first=True  # Test in sim before real execution
)

# Result includes:
# - plan: Selected execution plan
# - simulation_validated: True
# - predicted_success_rate: 0.85
# - options_tested: 3
```

## Configuration

### Environment Variables
```bash
# DeepSeek / Ollama
OLLAMA_URL=http://localhost:11434
DEEPSEEK_MODEL=deepseek-v4-flash:cloud

# Simulation
SIMULATION_PORT=9200
SIMULATION_BUDGET_DEFAULT=1000
SIMULATION_RANDOMIZATION=true

# Kafka
KAFKA_BOOTSTRAP=localhost:9092
KAFKA_TOPIC_PREFIX=spidernet

# Calibration
CALIBRATION_WINDOW_HOURS=168
CALIBRATION_LEARNING_RATE=0.01
```

## Running the Service

### Local Development
```bash
# Install dependencies
pip install -r requirements.txt

# Start with auto-reload
uvicorn main:app --reload --port 9200
```

### Production (Vast.ai)
```bash
# Copy to Vast.ai instance
cp -r services/simulation-service /workspace/SpiderNetOS/services/

# Start service
cd /workspace/SpiderNetOS/services/simulation-service
python3 main.py

# Or with Docker
docker-compose up simulation-service
```

## Training Pipeline

### Stage 1: Simulation-Only Training
```python
# Run 1000 episodes in simulation
for episode in range(1000):
    state = market_env.reset()
    done = False
    
    while not done:
        action = policy.select_action(state)
        next_state, reward, done, info = market_env.step(action)
        
        # DeepSeek scenario events applied
        # User behavior modeled
        # Economic reward computed
        
        trajectory.append((state, action, reward))
        state = next_state
    
    # PPO update
    ppo_trainer.update(trajectory)
```

### Stage 2: Shadow Mode
```python
# Run policy alongside human decisions
# Compare outcomes without affecting real campaigns
```

### Stage 3: A/B Testing
```python
# 10% traffic to RL policy
# 90% traffic to baseline
# Gradual increase if RL outperforms
```

### Stage 4: Full Deployment
```python
# RL policy controls all decisions
# Continuous learning from real outcomes
# DeepSeek monitors for drift
```

## Calibration Process

### 1. Data Collection
```python
# Every real campaign execution
sim_to_real.calibrate(
    sim_outcome=simulated_prediction,
    real_outcome=actual_results
)
```

### 2. Gap Analysis (DeepSeek)
```python
# Analyze discrepancies
insight = deepseek_calibration.analyze_gap(
    metric_name="cpm_meta",
    sim_values=[10.0, 10.5, 11.0],
    real_values=[12.0, 12.5, 13.0],
    market_context={...}
)

# Result:
# - Root cause: "Competitor underpricing not modeled"
# - Suggested fix: "Add 15% CPM multiplier"
# - Confidence: 0.82
```

### 3. Parameter Update
```python
# Apply DeepSeek suggestion
market_env.base_cpm['meta'] *= 1.15
```

### 4. Validation
```python
# Monitor for improvement
cal_quality = sim_to_real.get_calibration_quality()
# Should show 'good' after sufficient data
```

## Monitoring

### Key Metrics
```python
# Service health
GET /health

# Calibration status
GET /calibration/status

# Training metrics
GET /metrics
```

### DeepSeek Insights
- Scenario generation quality
- Calibration predictions accuracy
- Policy decision explanations
- Reward function suggestions

## Troubleshooting

### Simulation diverging from reality
1. Check calibration quality: `GET /calibration/status`
2. Analyze gaps: `POST /calibration/analyze`
3. Review DeepSeek report: `GET /calibration/report`
4. Adjust parameters based on suggestions

### Poor policy performance
1. Generate harder scenarios: `POST /scenario/generate?difficulty=extreme`
2. Check reward function alignment: `POST /policy/suggest-reward-improvements`
3. Increase training episodes
4. Review DeepSeek explanations

### DeepSeek unavailable
- Falls back to default scenarios
- Uses heuristic calibration
- Logs warning but continues operation

## Files

```
services/simulation-service/
├── main.py                          # FastAPI service
├── engine/
│   ├── market_env.py               # Stochastic market simulation
│   ├── user_behavior.py            # Logistic response model
│   ├── economic_reward.py          # Profit-based reward
│   ├── sim_to_real.py             # Calibration bridge
│   ├── deepseek_scenario_generator.py  # AI scenario generation
│   ├── deepseek_calibration_analyzer.py # AI gap analysis
│   └── training_loop.py            # PPO training
├── agents/
│   ├── campaign_agent.py           # Real API execution
│   └── policy_registry.py          # Versioned policies
└── README.md                       # This file
```

## Next Steps

1. **Connect real ad APIs**: Meta Marketing API, Google Ads API, TikTok API
2. **Expand DeepSeek scenarios**: Add seasonal, regulatory, competitive scenarios
3. **Multi-agent coordination**: BDM agent, Product agent, Growth agent collaboration
4. **Capital allocation**: Base-12 scoring integration
5. **Memory system**: Vector DB for past campaign embeddings

## References

- **PPO**: Schulman et al., "Proximal Policy Optimization Algorithms", 2017
- **GAE**: Schulman et al., "High-Dimensional Continuous Control Using Generalized Advantage Estimation", 2016
- **Sim-to-Real**: OpenAI, "Domain Randomization for Transferring Deep Neural Networks", 2017
- **MDP**: Sutton & Barto, "Reinforcement Learning: An Introduction", 2018
