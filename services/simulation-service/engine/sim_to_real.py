"""
SpiderNet OS - Sim-to-Real Bridge
Tracks how far the simulator's predictions drift from real campaign results,
corrects simulator parameters for that drift, and randomises actions during
training so policies do not overfit one simulator.
"""

from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Dict, List, Optional

import numpy as np


@dataclass
class CalibrationData:
    """One paired observation: what the simulator said vs what happened."""
    timestamp: datetime
    channel: str
    predicted_cpm: float
    actual_cpm: float
    predicted_conversions: float
    actual_conversions: float
    budget_spent: float


class SimToRealBridge:
    MIN_POINTS = 24

    def __init__(
        self,
        calibration_window: int = 168,
        learning_rate: float = 0.01,
        noise_scale: float = 0.2,
        seed: Optional[int] = None,
    ):
        self.calibration_window = calibration_window
        self.learning_rate = learning_rate
        self.noise_scale = noise_scale
        self.calibration_history: List[CalibrationData] = []
        self.cpm_bias = {'meta': 0.0, 'google': 0.0, 'tiktok': 0.0}
        self.conversion_bias = 0.0
        self._rng = np.random.default_rng(seed)

    def calibrate(self, sim_outcome: Dict, real_outcome: Dict) -> Dict:
        """Record one sim/real pair and nudge the biases toward the gap."""
        channel = real_outcome.get('channel', 'meta')
        point = CalibrationData(
            timestamp=datetime.now(timezone.utc),
            channel=channel,
            predicted_cpm=float(sim_outcome.get('cpm', 0.0)),
            actual_cpm=float(real_outcome.get('cpm', 0.0)),
            predicted_conversions=float(sim_outcome.get('conversions', 0.0)),
            actual_conversions=float(real_outcome.get('conversions', 0.0)),
            budget_spent=float(real_outcome.get('spend', 0.0)),
        )
        self.calibration_history.append(point)
        if len(self.calibration_history) > self.calibration_window:
            self.calibration_history = self.calibration_history[-self.calibration_window:]

        if channel in self.cpm_bias:
            gap = point.actual_cpm - point.predicted_cpm
            self.cpm_bias[channel] += self.learning_rate * (gap - self.cpm_bias[channel])

        conversion_gap = point.actual_conversions - point.predicted_conversions
        self.conversion_bias += self.learning_rate * (conversion_gap - self.conversion_bias)

        return {'cpm_bias': dict(self.cpm_bias), 'conversion_bias': self.conversion_bias}

    def get_calibrated_params(self, base_params: Dict) -> Dict:
        """Simulator parameters with the learned CPM bias applied."""
        calibrated = dict(base_params)
        for channel, bias in self.cpm_bias.items():
            key = f'base_cpm_{channel}'
            if key in calibrated:
                calibrated[key] = max(0.1, calibrated[key] + bias)
        return calibrated

    def apply_domain_randomization(self, base_params: Dict, randomize: bool = True) -> Dict:
        """
        Copy of base_params with every top-level number scaled by up to
        +/- noise_scale. Nested values (the channel mix) are left alone so
        they still sum to 1.
        """
        if not randomize:
            return dict(base_params)

        randomized = {}
        for key, value in base_params.items():
            if isinstance(value, (int, float)) and not isinstance(value, bool):
                factor = self._rng.uniform(1 - self.noise_scale, 1 + self.noise_scale)
                randomized[key] = float(value * factor)
            else:
                randomized[key] = value
        return randomized

    def get_calibration_quality(self) -> Dict:
        points = len(self.calibration_history)
        if points < self.MIN_POINTS:
            quality = 'insufficient_data'
        elif points < self.calibration_window:
            quality = 'building'
        else:
            quality = 'good'

        if points:
            cpm_errors = [
                abs(p.actual_cpm - p.predicted_cpm) / p.actual_cpm
                for p in self.calibration_history
                if p.actual_cpm > 0
            ]
            mean_cpm_error = float(np.mean(cpm_errors)) if cpm_errors else None
        else:
            mean_cpm_error = None

        return {
            'quality': quality,
            'data_points': points,
            'mean_cpm_error': mean_cpm_error,
            'cpm_bias': dict(self.cpm_bias),
            'conversion_bias': self.conversion_bias,
        }
