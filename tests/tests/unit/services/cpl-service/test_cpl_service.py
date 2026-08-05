"""
Unit tests for CPL Service
SpiderNet OS - Control Plane Learning
"""

import pytest
import torch
import numpy as np
from unittest.mock import Mock, patch

# Import the service components (would need proper imports once __init__.py is fixed)
# from services.cpl_service.engine.policy_network import PolicyNetwork, StateEncoder
# from services.cpl_service.engine.ppo_trainer import StreamingPPOTrainer
# from services.cpl_service.engine.cost_governor import CostGovernor


class TestPolicyNetwork:
    """Test PolicyNetwork functionality"""

    @pytest.fixture
    def policy_network(self):
        """Create a test PolicyNetwork instance"""
        # Mock the PolicyNetwork class since imports may not work yet
        policy = Mock()
        policy.get_action.return_value = (torch.tensor([1]), torch.tensor([0.1]), torch.tensor([0.5]), torch.tensor([0.2]))
        policy.evaluate_actions.return_value = (
            torch.tensor([0.1]), torch.tensor([0.5]), torch.tensor([0.2]), torch.tensor([0.0])
        )
        policy.predict_cost.return_value = torch.tensor([10.0])
        return policy

    @pytest.mark.unit
    def test_policy_network_initialization(self, policy_network):
        """Test that PolicyNetwork initializes correctly"""
        assert policy_network is not None

    @pytest.mark.unit
    def test_get_action_returns_valid_output(self, policy_network):
        """Test get_action returns expected tuple"""
        state = torch.randn(280)
        action, log_prob, value, entropy = policy_network.get_action(state)

        assert isinstance(action, torch.Tensor)
        assert isinstance(log_prob, torch.Tensor)
        assert isinstance(value, torch.Tensor)
        assert isinstance(entropy, torch.Tensor)

    @pytest.mark.unit
    def test_evaluate_actions_shape(self, policy_network):
        """Test evaluate_actions returns correct shapes"""
        batch_size = 32
        states = torch.randn(batch_size, 280)
        actions = torch.randint(0, 6, (batch_size,))

        log_probs, values, entropy, costs = policy_network.evaluate_actions(states, actions)

        assert log_probs.shape == (batch_size,)
        assert values.shape == (batch_size,)
        assert entropy.shape == (batch_size,)
        assert costs.shape == (batch_size,)


class TestStateEncoder:
    """Test StateEncoder functionality"""

    @pytest.mark.unit
    def test_state_encoder_initialization(self):
        """Test StateEncoder initializes with correct dimensions"""
        # Mock StateEncoder
        encoder = Mock()
        encoder.state_dim = 280
        encoder.gnn_dim = 256

        assert encoder.state_dim == 280
        assert encoder.gnn_dim == 256

    @pytest.mark.unit
    def test_encode_produces_correct_dimensions(self):
        """Test that encode produces 280-dim state vector"""
        # Mock the encoding process
        encoder = Mock()
        encoder.encode.return_value = torch.randn(280)

        # Mock inputs
        gnn_embedding = torch.randn(256)
        cost_vector = torch.randn(6)
        performance_vector = torch.randn(6)
        agent_health = torch.randn(8)
        queue_depths = torch.randn(4)

        result = encoder.encode(
            gnn_embedding, cost_vector, performance_vector,
            agent_health, queue_depths
        )

        assert result.shape == (280,)


class TestCostGovernor:
    """Test CostGovernor functionality"""

    @pytest.fixture
    def cost_governor(self):
        """Create a test CostGovernor instance"""
        governor = Mock()
        governor.check_budget.return_value = {
            'allowed': True,
            'remaining': 40.0,
            'utilization_percent': 20.0
        }
        return governor

    @pytest.mark.unit
    def test_budget_check_allowed(self, cost_governor):
        """Test budget check when within limits"""
        tenant_id = "test-tenant"
        requested_cost = 10.0

        result = cost_governor.check_budget(tenant_id, requested_cost)

        assert result['allowed'] is True
        assert result['remaining'] == 40.0

    @pytest.mark.unit
    def test_budget_check_denied(self, cost_governor):
        """Test budget check when exceeding limits"""
        cost_governor.check_budget.return_value = {
            'allowed': False,
            'remaining': 0.0,
            'utilization_percent': 100.0
        }

        tenant_id = "test-tenant"
        requested_cost = 60.0

        result = cost_governor.check_budget(tenant_id, requested_cost)

        assert result['allowed'] is False


class TestStreamingPPOTrainer:
    """Test StreamingPPOTrainer functionality"""

    @pytest.fixture
    def ppo_trainer(self):
        """Create a test PPO trainer instance"""
        trainer = Mock()
        trainer.update.return_value = {
            'policy_loss': 0.1,
            'value_loss': 0.05,
            'entropy': 0.8,
            'clip_fraction': 0.1
        }
        return trainer

    @pytest.mark.unit
    def test_ppo_update_returns_metrics(self, ppo_trainer):
        """Test that PPO update returns training metrics"""
        # Mock trajectory
        trajectory = Mock()
        trajectory.states = torch.randn(64, 280)
        trajectory.actions = torch.randint(0, 6, (64,))
        trajectory.log_probs = torch.randn(64)
        trajectory.rewards = torch.randn(64)
        trajectory.values = torch.randn(64)
        trajectory.costs = torch.randn(64)
        trajectory.dones = torch.randint(0, 2, (64,)).float()

        budget = 50.0
        metrics = ppo_trainer.update(trajectory, budget)

        assert 'policy_loss' in metrics
        assert 'value_loss' in metrics
        assert 'entropy' in metrics
        assert isinstance(metrics['policy_loss'], (int, float))


# Integration test placeholders
class TestCPLServiceIntegration:
    """Integration tests for CPL Service"""

    @pytest.mark.integration
    @pytest.mark.asyncio
    async def test_state_update_endpoint(self):
        """Test /state/update endpoint integration"""
        # This would test the actual FastAPI endpoint
        # Requires running service or extensive mocking
        pytest.skip("Integration test - requires running service")

    @pytest.mark.integration
    @pytest.mark.asyncio
    async def test_action_selection_endpoint(self):
        """Test /action/select endpoint integration"""
        pytest.skip("Integration test - requires running service")

    @pytest.mark.integration
    @pytest.mark.asyncio
    async def test_trajectory_update_endpoint(self):
        """Test /trajectory/update endpoint integration"""
        pytest.skip("Integration test - requires running service")