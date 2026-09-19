"""
SpiderNet OS - Streaming PPO Trainer
Real-time policy updates with GAE and cost constraints
Based on Schulman et al. (Proximal Policy Optimization)
"""

from dataclasses import dataclass
from typing import Dict, List, Optional, Tuple

import numpy as np
import torch
import torch.nn.functional as F


@dataclass
class Trajectory:
    """Single trajectory segment for PPO update"""
    states: torch.Tensor
    actions: torch.Tensor
    log_probs: torch.Tensor
    rewards: torch.Tensor
    values: torch.Tensor
    costs: torch.Tensor
    dones: torch.Tensor
    advantages: Optional[torch.Tensor] = None
    returns: Optional[torch.Tensor] = None


class StreamingPPOTrainer:
    """
    Streaming PPO trainer for continuous online learning.

    Features:
    - GAE (Generalized Advantage Estimation) for low-variance advantages
    - Clipped surrogate objective for policy stability
    - Twin value networks for pessimistic value estimation
    - Entropy regularization for exploration
    - Cost constraint via Lagrangian multiplier
    """

    def __init__(
        self,
        policy,
        lr_policy: float = 3e-4,
        lr_value: float = 1e-3,
        gamma: float = 0.99,
        gae_lambda: float = 0.95,
        clip_epsilon: float = 0.2,
        entropy_coef: float = 0.01,
        value_coef: float = 0.5,
        cost_coef: float = 0.1,
        max_grad_norm: float = 0.5,
        num_epochs: int = 10,
        batch_size: int = 64,
        device: str = 'cuda'
    ):
        self.policy = policy
        self.device = device

        # Hyperparameters
        self.gamma = gamma
        self.gae_lambda = gae_lambda
        self.clip_epsilon = clip_epsilon
        self.entropy_coef = entropy_coef
        self.value_coef = value_coef
        self.cost_coef = cost_coef
        self.max_grad_norm = max_grad_norm
        self.num_epochs = num_epochs
        self.batch_size = batch_size

        # Optimizers
        self.optimizer = torch.optim.Adam([
            {'params': self.policy.shared.parameters(), 'lr': lr_policy},
            {'params': self.policy.actor.parameters(), 'lr': lr_policy},
            {'params': self.policy.critic.parameters(), 'lr': lr_value},
            {'params': self.policy.cost_head.parameters(), 'lr': lr_value}
        ])

        # Lagrangian multiplier for cost constraint (adaptive)
        self.lagrangian_multiplier = torch.tensor(0.1, device=device, requires_grad=True)
        self.lagrangian_optimizer = torch.optim.Adam([self.lagrangian_multiplier], lr=1e-3)

        # Training metrics
        self.metrics = {
            'policy_loss': [],
            'value_loss': [],
            'entropy': [],
            'clip_fraction': [],
            'approx_kl': [],
            'lagrangian_multiplier': []
        }

    def compute_gae(
        self,
        rewards: torch.Tensor,
        values: torch.Tensor,
        dones: torch.Tensor,
        next_value: float = 0.0
    ) -> Tuple[torch.Tensor, torch.Tensor]:
        """
        Compute Generalized Advantage Estimation.

        Args:
            rewards: (batch,) reward sequence
            values: (batch,) value estimates
            dones: (batch,) episode termination flags
            next_value: Value of next state (for bootstrap)

        Returns:
            advantages, returns
        """
        batch_size = len(rewards)
        advantages = torch.zeros_like(rewards)
        last_gae = 0.0

        # Append next value for bootstrap
        values_ext = torch.cat([values, torch.tensor([next_value], device=self.device)])

        for t in reversed(range(batch_size)):
            if dones[t]:
                next_value_t = 0.0
                last_gae = 0.0
            else:
                next_value_t = values_ext[t + 1]

            delta = rewards[t] + self.gamma * next_value_t - values[t]
            last_gae = delta + self.gamma * self.gae_lambda * last_gae
            advantages[t] = last_gae

        returns = advantages + values

        return advantages, returns

    def update(self, trajectory: Trajectory, budget: float = 50.0) -> Dict[str, float]:
        """
        PPO policy update from trajectory.

        Args:
            trajectory: Collected trajectory
            budget: Cost budget for Lagrangian constraint

        Returns:
            Training metrics
        """
        # Compute GAE
        advantages, returns = self.compute_gae(
            trajectory.rewards,
            trajectory.values,
            trajectory.dones
        )

        # Normalize advantages
        advantages = (advantages - advantages.mean()) / (advantages.std() + 1e-8)

        # Store in trajectory
        trajectory.advantages = advantages
        trajectory.returns = returns

        # Multiple epochs over the data
        batch_size = len(trajectory.states)
        indices = np.arange(batch_size)

        total_policy_loss = 0
        total_value_loss = 0
        total_entropy = 0
        total_clip_fraction = 0
        total_kl = 0

        for _epoch in range(self.num_epochs):
            # Shuffle for mini-batch training
            np.random.shuffle(indices)

            for start in range(0, batch_size, self.batch_size):
                end = start + self.batch_size
                mb_indices = indices[start:end]

                # Get mini-batch
                mb_states = trajectory.states[mb_indices]
                mb_actions = trajectory.actions[mb_indices]
                mb_old_log_probs = trajectory.log_probs[mb_indices]
                mb_advantages = advantages[mb_indices]
                mb_returns = returns[mb_indices]
                mb_costs = trajectory.costs[mb_indices]

                # Evaluate actions with current policy
                new_log_probs, values, entropy, pred_costs = self.policy.evaluate_actions(
                    mb_states, mb_actions
                )

                # PPO Policy Loss (clipped surrogate)
                ratio = torch.exp(new_log_probs - mb_old_log_probs)
                surr1 = ratio * mb_advantages
                surr2 = torch.clamp(ratio, 1 - self.clip_epsilon, 1 + self.clip_epsilon) * mb_advantages
                policy_loss = -torch.min(surr1, surr2).mean()

                # Value Loss
                value_loss = F.mse_loss(values, mb_returns)

                # Cost Loss (constraint prediction)
                cost_loss = F.mse_loss(pred_costs, mb_costs)

                # Lagrangian penalty
                cost_violation = torch.clamp(pred_costs.mean() - budget, min=0.0)
                lagrangian_penalty = self.lagrangian_multiplier * cost_violation

                # Entropy bonus
                entropy_bonus = -entropy.mean()

                # Total loss
                loss = (
                    policy_loss
                    + self.value_coef * value_loss
                    + self.cost_coef * cost_loss
                    + lagrangian_penalty
                    + self.entropy_coef * entropy_bonus
                )

                # Optimization step
                self.optimizer.zero_grad()
                loss.backward()

                # Gradient clipping
                torch.nn.utils.clip_grad_norm_(self.policy.parameters(), self.max_grad_norm)

                self.optimizer.step()

                # Update Lagrangian multiplier (gradient ascent on constraint)
                with torch.no_grad():
                    self.lagrangian_multiplier += 0.01 * cost_violation
                    self.lagrangian_multiplier.clamp_(min=0.0, max=10.0)

                # Track metrics
                total_policy_loss += policy_loss.item()
                total_value_loss += value_loss.item()
                total_entropy += entropy.mean().item()

                clip_fraction = ((ratio - 1.0).abs() > self.clip_epsilon).float().mean()
                total_clip_fraction += clip_fraction.item()

                with torch.no_grad():
                    approx_kl = ((new_log_probs - mb_old_log_probs) ** 2).mean()
                    total_kl += approx_kl.item()

        num_updates = self.num_epochs * (batch_size // self.batch_size + 1)

        metrics = {
            'policy_loss': total_policy_loss / num_updates,
            'value_loss': total_value_loss / num_updates,
            'entropy': total_entropy / num_updates,
            'clip_fraction': total_clip_fraction / num_updates,
            'approx_kl': total_kl / num_updates,
            'lagrangian_multiplier': self.lagrangian_multiplier.item()
        }

        # Store metrics
        for key, value in metrics.items():
            self.metrics[key].append(value)

        return metrics

    def streaming_update(
        self,
        states: List[torch.Tensor],
        actions: List[torch.Tensor],
        log_probs: List[torch.Tensor],
        rewards: List[torch.Tensor],
        values: List[torch.Tensor],
        costs: List[torch.Tensor],
        dones: List[bool],
        budget: float = 50.0
    ) -> Optional[Dict[str, float]]:
        """
        Streaming update - accumulate and train when buffer is full.

        Args:
            states, actions, log_probs, rewards, values, costs: Trajectory components
            dones: Episode termination flags
            budget: Cost budget

        Returns:
            Metrics if update occurred, None otherwise
        """
        buffer_size = len(states)

        # Only update when buffer is reasonably full (streaming)
        if buffer_size < 64:  # Minimum batch size
            return None

        # Convert to tensors
        trajectory = Trajectory(
            states=torch.stack(states),
            actions=torch.tensor(actions, device=self.device),
            log_probs=torch.stack(log_probs),
            rewards=torch.tensor(rewards, device=self.device),
            values=torch.tensor(values, device=self.device),
            costs=torch.tensor(costs, device=self.device),
            dones=torch.tensor(dones, device=self.device, dtype=torch.float32)
        )

        # Perform update
        metrics = self.update(trajectory, budget)

        return metrics

    def save_checkpoint(self, path: str, episode: int = 0):
        """Save training checkpoint"""
        checkpoint = {
            'policy_state': self.policy.state_dict(),
            'optimizer_state': self.optimizer.state_dict(),
            'lagrangian_multiplier': self.lagrangian_multiplier.item(),
            'episode': episode,
            'metrics': self.metrics
        }
        torch.save(checkpoint, path)

    def load_checkpoint(self, path: str):
        """Load training checkpoint"""
        checkpoint = torch.load(path, map_location=self.device)
        self.policy.load_state_dict(checkpoint['policy_state'])
        self.optimizer.load_state_dict(checkpoint['optimizer_state'])
        self.lagrangian_multiplier = torch.tensor(
            checkpoint['lagrangian_multiplier'],
            device=self.device,
            requires_grad=True
        )
        self.metrics = checkpoint.get('metrics', self.metrics)
        return checkpoint.get('episode', 0)
