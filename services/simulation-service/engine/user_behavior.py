"""
SpiderNet OS - User Behavior Model
Logistic response curves for conversion prediction
Based on marketing mix modeling (MMM) principles
"""

import numpy as np
import torch
from typing import Dict, List, Tuple, Optional
from dataclasses import dataclass


@dataclass
class UserSegment:
    """User segment characteristics"""
    segment_id: str
    base_conversion_rate: float  # 0-1
    price_sensitivity: float     # 0-1, higher = more sensitive
    channel_affinity: Dict[str, float]  # meta, google, tiktok
    fatigue_rate: float          # How quickly they tire of ads


class UserBehaviorModel:
    """
    Models user response to advertising using:
    - Logistic response curves (diminishing returns)
    - Ad fatigue (exponential decay)
    - Channel-specific affinities
    - Seasonal effects
    """
    
    def __init__(
        self,
        segments: Optional[List[UserSegment]] = None
    ):
        # Default segments if none provided
        self.segments = segments or [
            UserSegment(
                segment_id='early_adopters',
                base_conversion_rate=0.05,
                price_sensitivity=0.3,
                channel_affinity={'meta': 0.5, 'google': 0.3, 'tiktok': 0.2},
                fatigue_rate=0.1
            ),
            UserSegment(
                segment_id='bargain_hunters',
                base_conversion_rate=0.03,
                price_sensitivity=0.8,
                channel_affinity={'meta': 0.3, 'google': 0.4, 'tiktok': 0.3},
                fatigue_rate=0.15
            ),
            UserSegment(
                segment_id='mainstream',
                base_conversion_rate=0.02,
                price_sensitivity=0.5,
                channel_affinity={'meta': 0.4, 'google': 0.4, 'tiktok': 0.2},
                fatigue_rate=0.2
            )
        ]
        
        # Ad exposure tracking per segment
        self.exposures: Dict[str, int] = {s.segment_id: 0 for s in self.segments}
    
    def predict_conversions(
        self,
        impressions: Dict[str, int],  # per channel
        segment_distribution: Optional[Dict[str, float]] = None
    ) -> Tuple[int, Dict]:
        """
        Predict conversions given impression allocation.
        
        Args:
            impressions: {'meta': 1000, 'google': 2000, ...}
            segment_distribution: {'early_adopters': 0.2, ...}
            
        Returns:
            (total_conversions, breakdown_by_segment)
        """
        if segment_distribution is None:
            # Equal distribution if not specified
            segment_distribution = {
                s.segment_id: 1.0 / len(self.segments) 
                for s in self.segments
            }
        
        total_conversions = 0
        breakdown = {}
        
        for segment in self.segments:
            seg_weight = segment_distribution.get(segment.segment_id, 0)
            if seg_weight == 0:
                continue
            
            # Calculate effective impressions (channel affinity weighted)
            effective_impressions = sum(
                impressions.get(ch, 0) * segment.channel_affinity.get(ch, 0)
                for ch in ['meta', 'google', 'tiktok']
            )
            
            # Logistic response curve with saturation
            # y = L / (1 + e^(-k(x - x0)))
            L = segment.base_conversion_rate * effective_impressions
            k = 0.001  # Steepness
            x0 = 1000  # Midpoint
            
            saturated_response = L / (1 + np.exp(-k * (effective_impressions - x0)))
            
            # Apply ad fatigue
            fatigue_factor = np.exp(
                -segment.fatigue_rate * self.exposures[segment.segment_id]
            )
            
            # Apply price sensitivity (affected by CPM indirectly through budget)
            # Lower CPM = more impressions = higher conversion
            
            segment_conversions = saturated_response * fatigue_factor * seg_weight
            total_conversions += segment_conversions
            
            breakdown[segment.segment_id] = {
                'conversions': segment_conversions,
                'effective_impressions': effective_impressions,
                'fatigue_factor': fatigue_factor
            }
            
            # Update exposure count
            self.exposures[segment.segment_id] += effective_impressions
        
        return int(total_conversions), breakdown
    
    def calculate_revenue(
        self,
        conversions: int,
        avg_order_value: float = 50.0,
        segment_distribution: Optional[Dict[str, float]] = None
    ) -> float:
        """Calculate revenue from conversions"""
        return conversions * avg_order_value
    
    def reset_fatigue(self):
        """Reset ad fatigue counters (new campaign)"""
        self.exposures = {s.segment_id: 0 for s in self.segments}
    
    def get_segment_insights(self) -> Dict:
        """Get insights on segment performance"""
        return {
            'exposures': self.exposures.copy(),
            'segments': [
                {
                    'id': s.segment_id,
                    'base_rate': s.base_conversion_rate,
                    'fatigue_rate': s.fatigue_rate
                }
                for s in self.segments
            ]
        }
