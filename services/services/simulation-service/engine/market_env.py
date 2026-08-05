"""
SpiderNet OS - Market Environment
Stochastic market simulator for safe RL training
Based on Ornstein-Uhlenbeck with jumps for realistic market dynamics
"""

import numpy as np
import torch
from typing import Dict, List, Tuple, Optional
from dataclasses import dataclass
from datetime import datetime, timedelta


@dataclass
class MarketState:
    """Market state at a point in time"""
    timestamp: datetime
    cpm_meta: float      # Cost per 1000 impressions (Meta)
    cpm_google: float    # Cost per 1000 impressions (Google)
    cpm_tiktok: float    # Cost per 1000 impressions (TikTok)
    competition_index: float  # 0-1, higher = more competition
    market_trend: float  # -1 to 1, negative = bearish
    day_of_week: int     # 0-6
    hour: int            # 0-23
    budget_remaining: float
    spend_rate: float    # Current spend velocity


class MarketEnv:
    """
    Stochastic market environment for training RL policies.
    
    Models:
    - Ornstein-Uhlenbeck process for CPM mean reversion
    - Jump-diffusion for viral/competitor shocks
    - Seasonal patterns (day-of-week, hour-of-day)
    """
    
    def __init__(
        self,
        base_cpm_meta: float = 12.0,
        base_cpm_google: float = 8.0,
        base_cpm_tiktok: float = 6.0,
        ou_theta: float = 0.1,      # Mean reversion speed
        ou_sigma: float = 0.3,      # Volatility
        jump_intensity: float = 0.05,  # Probability of shock per step
        jump_mean: float = 0.0,      # Mean of shock
        jump_std: float = 0.5,       # Std of shock
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
        self.jump_mean = jump_mean
        self.jump_std = jump_std
        
        self.rng = np.random.RandomState(seed)
        self.current_state: Optional[MarketState] = None
        self.history: List[MarketState] = []
        
    def reset(self, initial_budget: float = 1000.0) -> MarketState:
        """Reset environment to initial state"""
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
        """
        Advance market by one time step (1 hour).
        
        Args:
            action: Dict with 'budget_allocation' and 'channel_mix'
            
        Returns:
            (next_state, reward, done, info)
        """
        # Extract current state
        cs = self.current_state
        
        # Time evolution
        next_time = cs.timestamp + timedelta(hours=1)
        next_dow = next_time.weekday()
        next_hour = next_time.hour
        
        # Apply Ornstein-Uhlenbeck process to CPMs
        def evolve_cpm(current, base):
            # Mean reversion
            drift = self.ou_theta * (base - current)
            # Random shock
            diffusion = self.ou_sigma * self.rng.randn()
            # Jump process (competitor/viral events)
            jump = 0.0
            if self.rng.rand() < self.jump_intensity:
                jump = self.rng.normal(self.jump_mean, self.jump_std)
            
            new_cpm = current + drift + diffusion + jump
            return max(new_cpm, base * 0.3)  # Floor at 30% of base
        
        next_cpm_meta = evolve_cpm(cs.cpm_meta, self.base_cpm['meta'])
        next_cpm_google = evolve_cpm(cs.cpm_google, self.base_cpm['google'])
        next_cpm_tiktok = evolve_cpm(cs.cpm_tiktok, self.base_cpm['tiktok'])
        
        # Competition index evolves with mean reversion
        next_competition = 0.5 + 0.5 * np.sin(2 * np.pi * next_dow / 7)
        next_competition += 0.1 * self.rng.randn()
        next_competition = np.clip(next_competition, 0, 1)
        
        # Market trend (momentum)
        next_trend = 0.9 * cs.market_trend + 0.1 * self.rng.randn()
        next_trend = np.clip(next_trend, -1, 1)
        
        # Calculate spend based on action
        budget_spent = self._calculate_spend(action, {
            'meta': next_cpm_meta,
            'google': next_cpm_google,
            'tiktok': next_cpm_tiktok
        })
        
        next_budget = cs.budget_remaining - budget_spent
        
        # Create next state
        next_state = MarketState(
            timestamp=next_time,
            cpm_meta=next_cpm_meta,
            cpm_google=next_cpm_google,
            cpm_tiktok=next_cpm_tiktok,
            competition_index=next_competition,
            market_trend=next_trend,
            day_of_week=next_dow,
            hour=next_hour,
            budget_remaining=next_budget,
            spend_rate=budget_spent
        )
        
        self.current_state = next_state
        self.history.append(next_state)
        
        # Done if budget exhausted or 30 days passed
        done = next_budget <= 0 or len(self.history) >= 30 * 24
        
        info = {
            'cpm_meta': next_cpm_meta,
            'cpm_google': next_cpm_google,
            'cpm_tiktok': next_cpm_tiktok,
            'spend': budget_spent,
            'hours_elapsed': len(self.history)
        }
        
        return next_state, 0.0, done, info  # Reward computed externally
    
    def _calculate_spend(self, action: Dict, cpms: Dict[str, float]) -> float:
        """Calculate budget spend based on action and CPMs"""
        allocation = action.get('budget_allocation', 100.0)
        channel_mix = action.get('channel_mix', {
            'meta': 0.4,
            'google': 0.4,
            'tiktok': 0.2
        })
        
        # Weighted average CPM
        avg_cpm = sum(
            channel_mix[ch] * cpms[ch] 
            for ch in ['meta', 'google', 'tiktok']
        )
        
        # Spend scales with allocation (assumes 1000 impressions per $CPM)
        spend = allocation * (avg_cpm / 12.0)  # Normalize to meta baseline
        
        return min(spend, self.current_state.budget_remaining * 0.1)  # Max 10% per hour
    
    def to_tensor(self, state: Optional[MarketState] = None) -> torch.Tensor:
        """Convert state to tensor for policy network"""
        s = state or self.current_state
        if s is None:
            raise ValueError("No state available")
        
        # State vector: [cpm_meta, cpm_google, cpm_tiktok, competition, 
        #               trend, dow, hour, budget_frac, spend_rate]
        return torch.tensor([
            s.cpm_meta / 20.0,           # Normalize
            s.cpm_google / 20.0,
            s.cpm_tiktok / 20.0,
            s.competition_index,
            s.market_trend,
            s.day_of_week / 6.0,
            s.hour / 23.0,
            s.budget_remaining / 1000.0,  # Assume 1000 budget
            s.spend_rate / 100.0
        ], dtype=torch.float32)
