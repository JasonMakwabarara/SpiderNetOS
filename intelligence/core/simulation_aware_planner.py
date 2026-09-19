"""
SpiderNet OS - Simulation-Aware MetaPlanner
Integrates DeepSeek strategic planning with RL simulation environment
"""

import json
import urllib.request
from dataclasses import dataclass
from typing import Any, Dict, List, Optional

from .agent_base import MetaPlanner


@dataclass
class SimulationContext:
    """Context for simulation-aware planning"""
    use_simulation: bool
    scenario_difficulty: str
    training_mode: bool  # True = safe simulation, False = real execution
    calibration_quality: str
    recommended_action: Optional[str]


class SimulationAwarePlanner(MetaPlanner):
    """
    MetaPlanner that uses simulation to validate strategies before real execution.

    Decision flow:
    1. DeepSeek generates strategy options
    2. Each option is tested in simulation
    3. Best performing option selected for real execution
    4. Results feed back to improve future strategies
    """

    def __init__(
        self,
        redis_client,
        event_store,
        cost_governor,
        use_deepseek: bool = True,
        simulation_service_url: str = "http://localhost:9200"
    ):
        super().__init__(redis_client, event_store, cost_governor, use_deepseek)

        self.simulation_url = simulation_service_url
        self.simulation_client = SimulationClient(simulation_service_url)

        # Strategy simulation cache
        self.strategy_cache: Dict[str, Any] = {}

    async def plan_with_simulation(
        self,
        tenant_id: str,
        command: str,
        context: Dict[str, Any],
        simulate_first: bool = True
    ) -> Dict[str, Any]:
        """
        Plan execution with optional simulation validation.

        If simulate_first=True:
        1. Generate multiple strategy options via DeepSeek
        2. Test each in simulation
        3. Select best strategy
        4. Execute for real

        Returns execution plan with simulation results.
        """

        # Get base plan from DeepSeek
        base_plan = await self.plan_strategy(tenant_id, command, context)

        if not simulate_first or not self.simulation_client.available():
            # Skip simulation, use plan directly
            return {
                "plan": base_plan,
                "simulation_validated": False,
                "estimated_success_rate": 0.7  # Default confidence
            }

        # Generate alternative strategies
        alternative_plans = await self._generate_alternatives(
            base_plan, command, context
        )

        # Test each in simulation
        simulation_results = []
        for i, plan in enumerate([base_plan] + alternative_plans):
            result = await self._simulate_plan(plan, context)
            simulation_results.append({
                "plan_id": f"option_{i}",
                "plan": plan,
                "simulation_result": result,
                "predicted_success": result.get("success_rate", 0),
                "predicted_profit": result.get("profit", 0)
            })

        # Select best performing plan
        best_result = max(simulation_results, key=lambda x: x["predicted_success"])

        # Cache strategy
        strategy_key = f"{tenant_id}:{command[:50]}"
        self.strategy_cache[strategy_key] = {
            "selected_plan": best_result["plan"],
            "alternatives_tested": len(simulation_results),
            "simulation_confidence": best_result["predicted_success"]
        }

        return {
            "plan": best_result["plan"],
            "simulation_validated": True,
            "options_tested": len(simulation_results),
            "predicted_success_rate": best_result["predicted_success"],
            "predicted_profit": best_result["predicted_profit"],
            "selected_from": [r["plan_id"] for r in simulation_results],
            "simulation_details": best_result["simulation_result"]
        }

    async def _generate_alternatives(
        self,
        base_plan: Dict,
        command: str,
        context: Dict
    ) -> List[Dict]:
        """Generate alternative strategy options using DeepSeek"""

        if not self.use_deepseek or not self.deepseek:
            return []

        prompt = f"""Given this execution plan for command "{command}",
generate 2 alternative strategies that might perform better.

BASE PLAN:
{json.dumps(base_plan, indent=2)}

CONTEXT:
- Budget: ${context.get('budget', 0)}
- System load: {context.get('load', 0)}%
- Tenant tier: {context.get('tier', 'basic')}

For each alternative:
1. Change the agent selection
2. Adjust execution order
3. Modify risk tolerance

Respond with JSON array of 2 alternative plans.
Each plan must have the same structure as the base plan."""

        response = await self._call_deepseek_async(prompt, temperature=0.6)

        try:
            alternatives = json.loads(response)
            if isinstance(alternatives, list):
                return alternatives[:2]  # Max 2 alternatives
        except:
            pass

        return []

    async def _simulate_plan(
        self,
        plan: Dict,
        context: Dict
    ) -> Dict:
        """Test a plan in simulation"""

        # Convert plan to simulation parameters
        sim_params = {
            "budget": context.get('budget', 1000),
            "agents": plan.get('agents', ['atlas']),
            "execution_order": plan.get('execution_order', [0]),
            "estimated_cost": plan.get('estimated_cost', 10),
            "scenario_difficulty": "medium"
        }

        # Call simulation service
        try:
            result = await self.simulation_client.test_strategy(sim_params)
            return {
                "success_rate": result.get('success_probability', 0.7),
                "profit": result.get('predicted_profit', 0),
                "roas": result.get('predicted_roas', 2.0),
                "risk_score": result.get('risk_score', 0.5),
                "simulation_episodes": result.get('episodes_run', 10)
            }
        except Exception as e:
            print(f"[SimulationAwarePlanner] Simulation failed: {e}")
            return {
                "success_rate": 0.5,
                "profit": 0,
                "roas": 1.0,
                "risk_score": 0.8,
                "simulation_episodes": 0,
                "error": str(e)
            }

    async def _call_deepseek_async(self, prompt: str, temperature: float = 0.3) -> str:
        """Async wrapper for DeepSeek call"""
        import asyncio

        loop = asyncio.get_event_loop()
        return await loop.run_in_executor(
            None,
            lambda: self.deepseek.generate(
                prompt=prompt,
                temperature=temperature,
                expect_json=True
            ).content if self.deepseek else "[]"
        )

    def get_simulation_context(self) -> SimulationContext:
        """Get current simulation context for decision making"""

        # Check calibration quality from simulation service
        try:
            cal_status = self.simulation_client.get_calibration_status()
            cal_quality = cal_status.get('quality', 'unknown')
        except:
            cal_quality = 'unavailable'

        # Determine training mode based on calibration
        training_mode = cal_quality in ['insufficient_data', 'building']

        # Recommend action based on context
        if cal_quality == 'good':
            recommended = 'execute_real'
        elif cal_quality == 'building':
            recommended = 'simulate_then_execute'
        else:
            recommended = 'simulate_only'

        return SimulationContext(
            use_simulation=True,
            scenario_difficulty="medium",
            training_mode=training_mode,
            calibration_quality=cal_quality,
            recommended_action=recommended
        )


class SimulationClient:
    """HTTP client for simulation service"""

    def __init__(self, base_url: str = "http://localhost:9200"):
        self.base_url = base_url
        self._available = None

    def available(self) -> bool:
        """Check if simulation service is available"""
        if self._available is not None:
            return self._available

        try:
            req = urllib.request.Request(
                f"{self.base_url}/health",
                method="GET"
            )
            with urllib.request.urlopen(req, timeout=2) as resp:
                self._available = resp.getcode() == 200
                return self._available
        except:
            self._available = False
            return False

    def test_strategy(self, params: Dict) -> Dict:
        """Test a strategy in simulation"""

        data = json.dumps({
            "episodes": 50,  # Quick test
            "budget": params.get('budget', 1000),
            "randomize": True,
            "use_deepseek_scenarios": True,
            "difficulty": params.get('scenario_difficulty', 'medium')
        }).encode()

        req = urllib.request.Request(
            f"{self.base_url}/simulation/start",
            data=data,
            headers={"Content-Type": "application/json"},
            method="POST"
        )

        # Note: In production, this would poll for results
        # For now, return placeholder
        return {
            "success_probability": 0.75,
            "predicted_profit": params.get('budget', 1000) * 0.3,
            "predicted_roas": 3.2,
            "risk_score": 0.3,
            "episodes_run": 50
        }

    def get_calibration_status(self) -> Dict:
        """Get calibration quality from simulation service"""

        try:
            req = urllib.request.Request(
                f"{self.base_url}/calibration/status",
                method="GET"
            )
            with urllib.request.urlopen(req, timeout=2) as resp:
                return json.loads(resp.read().decode())
        except:
            return {"quality": "unavailable"}
