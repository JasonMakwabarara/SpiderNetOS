"""
SpiderNet OS - DeepSeek Scenario Generator
Uses DeepSeek v4 to generate realistic market scenarios for simulation
Enhances domain randomization with AI-generated edge cases
"""

import json
import urllib.request
from typing import Dict, List, Optional, Any
from dataclasses import dataclass
import random


@dataclass
class MarketScenario:
    """AI-generated market scenario"""
    scenario_id: str
    name: str
    description: str
    duration_hours: int
    events: List[Dict[str, Any]]  # Timed market events
    base_conditions: Dict[str, float]
    difficulty: str  # 'easy', 'medium', 'hard', 'extreme'
    learning_objective: str


class DeepSeekScenarioGenerator:
    """
    Uses DeepSeek v4 to generate challenging training scenarios.
    
    Capabilities:
    - Generate realistic market shock scenarios
    - Create multi-phase training curricula
    - Design edge cases for robustness testing
    - Explain scenario rationale for debugging
    """
    
    def __init__(
        self,
        ollama_url: str = "http://localhost:11434",
        model: str = "deepseek-v4-flash:cloud",
        timeout: int = 60
    ):
        self.ollama_url = ollama_url
        self.model = model
        self.timeout = timeout
        self.scenario_history: List[MarketScenario] = []
        
    def _call_deepseek(
        self,
        prompt: str,
        temperature: float = 0.7,
        expect_json: bool = True
    ) -> str:
        """Call DeepSeek via Ollama"""
        
        if expect_json:
            prompt += "\n\nRespond with valid JSON only. No markdown, no explanations outside JSON."
        
        data = json.dumps({
            "model": self.model,
            "prompt": prompt,
            "stream": False,
            "options": {
                "temperature": temperature,
                "num_predict": 2000
            }
        }).encode()
        
        req = urllib.request.Request(
            f"{self.ollama_url}/api/generate",
            data=data,
            headers={"Content-Type": "application/json"},
            method="POST"
        )
        
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                result = json.loads(resp.read().decode())
                return result.get("response", "")
        except Exception as e:
            print(f"[DeepSeekScenario] Error: {e}")
            return ""
    
    def generate_scenario(
        self,
        difficulty: str = "medium",
        scenario_type: str = "viral_competitor",
        duration_hours: int = 168  # 1 week
    ) -> MarketScenario:
        """
        Generate AI-designed market scenario.
        
        Args:
            difficulty: 'easy', 'medium', 'hard', 'extreme'
            scenario_type: 'viral_competitor', 'supply_shock', 'platform_change', 'economic_downturn'
            duration_hours: Scenario duration
        """
        
        prompt = f"""You are a market simulation designer for an RL training system.

Create a {difficulty} difficulty scenario of type '{scenario_type}' lasting {duration_hours} hours.

SCENARIO REQUIREMENTS:
- Realistic market dynamics for Meta/Google/TikTok advertising
- Multiple timed events that challenge the RL policy
- Specific numerical parameters for CPM changes, conversion rate shifts
- Clear learning objective

Design 3-5 major events throughout the scenario timeline.

RESPOND WITH JSON:
{{
    "name": "Scenario name",
    "description": "Detailed scenario narrative",
    "duration_hours": {duration_hours},
    "events": [
        {{
            "hour": 24,
            "type": "competitor_entry",
            "description": "New competitor enters market",
            "effects": {{
                "cpm_multiplier": 1.3,
                "conversion_rate_multiplier": 0.9,
                "channels_affected": ["meta", "google"]
            }}
        }}
    ],
    "base_conditions": {{
        "cpm_meta": 12.0,
        "cpm_google": 8.0,
        "cpm_tiktok": 6.0,
        "base_conversion_rate": 0.02
    }},
    "learning_objective": "What this scenario teaches the policy"
}}"""
        
        response = self._call_deepseek(prompt, temperature=0.8)
        
        try:
            # Extract JSON
            if "```json" in response:
                json_str = response.split("```json")[1].split("```")[0]
            elif "```" in response:
                json_str = response.split("```")[1].split("```")[0]
            else:
                json_str = response
            
            data = json.loads(json_str.strip())
            
            scenario = MarketScenario(
                scenario_id=f"ai_{scenario_type}_{difficulty}_{random.randint(1000, 9999)}",
                name=data["name"],
                description=data["description"],
                duration_hours=data["duration_hours"],
                events=data["events"],
                base_conditions=data["base_conditions"],
                difficulty=difficulty,
                learning_objective=data["learning_objective"]
            )
            
            self.scenario_history.append(scenario)
            return scenario
            
        except (json.JSONDecodeError, KeyError) as e:
            print(f"[DeepSeekScenario] Parse error: {e}")
            # Fallback to default scenario
            return self._default_scenario(difficulty, scenario_type)
    
    def _default_scenario(
        self,
        difficulty: str,
        scenario_type: str
    ) -> MarketScenario:
        """Fallback scenario when DeepSeek fails"""
        
        multipliers = {
            'easy': {'cpm': 0.9, 'conversion': 1.1},
            'medium': {'cpm': 1.0, 'conversion': 1.0},
            'hard': {'cpm': 1.3, 'conversion': 0.9},
            'extreme': {'cpm': 1.6, 'conversion': 0.75}
        }.get(difficulty, {'cpm': 1.0, 'conversion': 1.0})
        
        return MarketScenario(
            scenario_id=f"default_{difficulty}_{random.randint(1000, 9999)}",
            name=f"{difficulty.title()} {scenario_type.replace('_', ' ').title()}",
            description=f"Default scenario for {difficulty} training",
            duration_hours=168,
            events=[
                {
                    "hour": 48,
                    "type": "market_shift",
                    "description": f"CPM increases by {(multipliers['cpm']-1)*100:.0f}%",
                    "effects": {
                        "cpm_multiplier": multipliers['cpm'],
                        "conversion_rate_multiplier": multipliers['conversion']
                    }
                }
            ],
            base_conditions={
                "cpm_meta": 12.0,
                "cpm_google": 8.0,
                "cpm_tiktok": 6.0,
                "base_conversion_rate": 0.02
            },
            difficulty=difficulty,
            learning_objective=f"Handle {difficulty} market conditions"
        )
    
    def generate_curriculum(
        self,
        num_stages: int = 5,
        progression_type: str = "gradual"  # 'gradual', 'shock', 'cyclical'
    ) -> List[MarketScenario]:
        """
        Generate a full training curriculum with DeepSeek.
        
        Creates progressively harder scenarios for curriculum learning.
        """
        
        difficulties = ['easy', 'medium', 'medium', 'hard', 'extreme'][:num_stages]
        scenario_types = [
            'steady_market',
            'seasonal_variation', 
            'viral_competitor',
            'platform_change',
            'economic_downturn'
        ][:num_stages]
        
        prompt = f"""Design a {num_stages}-stage RL training curriculum.

Progression type: {progression_type}

Each stage should build on previous skills while introducing new challenges.

Stages: {', '.join(difficulties)}
Scenario types: {', '.join(scenario_types)}

For each stage, specify:
1. What skills should be mastered before progressing
2. What new challenge is introduced
3. Success criteria (min ROAS, max CAC, etc.)

Respond with JSON array of scenario descriptions."""
        
        response = self._call_deepseek(prompt, temperature=0.6)
        
        curriculum = []
        for i, (diff, stype) in enumerate(zip(difficulties, scenario_types)):
            scenario = self.generate_scenario(
                difficulty=diff,
                scenario_type=stype,
                duration_hours=72 + (i * 24)  # Increasing duration
            )
            curriculum.append(scenario)
        
        return curriculum
    
    def explain_policy_decision(
        self,
        scenario: MarketScenario,
        policy_action: Dict,
        outcome: Dict
    ) -> str:
        """
        Use DeepSeek to explain why a policy decision was good/bad.
        
        Useful for debugging and policy interpretation.
        """
        
        prompt = f"""Analyze this RL policy decision in a market simulation.

SCENARIO: {scenario.name}
{scenario.description}

POLICY ACTION:
- Budget allocation: ${policy_action.get('budget_allocation', 0)}
- Channel mix: {policy_action.get('channel_mix', {})}

OUTCOME:
- Revenue: ${outcome.get('revenue', 0):.2f}
- Cost: ${outcome.get('cost', 0):.2f}
- ROAS: {outcome.get('roas', 0):.2f}
- Conversions: {outcome.get('conversions', 0)}

Analyze:
1. Was this a good decision given the market conditions?
2. What would an optimal decision have looked like?
3. What does this reveal about the policy's current strategy?
4. What should the policy learn from this outcome?

Provide a concise analysis (3-5 bullet points)."""
        
        return self._call_deepseek(prompt, temperature=0.4, expect_json=False)
    
    def suggest_reward_shaping(
        self,
        training_history: List[Dict],
        current_reward_function: str
    ) -> Dict:
        """
        Analyze training history and suggest reward function improvements.
        
        DeepSeek identifies reward hacking or misalignment.
        """
        
        # Summarize training history
        avg_rewards = [ep.get('total_reward', 0) for ep in training_history[-100:]]
        avg_roas = [ep.get('roas', 0) for ep in training_history[-100:]]
        
        prompt = f"""Analyze this RL training history and suggest reward function improvements.

CURRENT REWARD FUNCTION:
{current_reward_function}

TRAINING METRICS (last 100 episodes):
- Average reward: {sum(avg_rewards)/len(avg_rewards):.2f}
- Reward trend: {'increasing' if avg_rewards[-1] > avg_rewards[0] else 'decreasing'}
- Average ROAS: {sum(avg_roas)/len(avg_roas):.2f}
- ROAS trend: {'increasing' if avg_roas[-1] > avg_roas[0] else 'decreasing'}

POTENTIAL ISSUES TO CHECK:
1. Is the policy optimizing reward but not actual profit?
2. Is there reward hacking (exploiting the reward function)?
3. Are short-term rewards prioritized over long-term?
4. Is the reward signal too sparse or noisy?

Suggest specific changes to the reward function weights or structure.

Respond with JSON:
{{
    "analysis": "Brief analysis of current situation",
    "issues_detected": ["issue 1", "issue 2"],
    "suggested_changes": [
        {{
            "component": "profit_weight|roas_weight|risk_weight",
            "current_value": 1.0,
            "suggested_value": 1.5,
            "rationale": "Why this change helps"
        }}
    ],
    "confidence": 0.8
}}"""
        
        response = self._call_deepseek(prompt, temperature=0.3)
        
        try:
            if "```json" in response:
                json_str = response.split("```json")[1].split("```")[0]
            elif "```" in response:
                json_str = response.split("```")[1].split("```")[0]
            else:
                json_str = response
            
            return json.loads(json_str.strip())
        except:
            return {
                "analysis": "Could not parse DeepSeek response",
                "issues_detected": [],
                "suggested_changes": [],
                "confidence": 0.0
            }
