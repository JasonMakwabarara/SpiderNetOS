"""Unit tests for the CPL service.

These used to mock PolicyNetwork and then assert against the mock — the fixture
returned tensors of shape (1,) while the test asserted (32,), so the suite could
only fail. The header said why: "Mock the PolicyNetwork class since imports may
not work yet". The import did not work because services/cpl-service contains a
hyphen; tests/conftest.py loads it by path instead, and these now exercise the
real network.
"""

import pytest
import torch


class TestPolicyNetwork:
    """The real PolicyNetwork, not a stand-in for it."""

    @pytest.fixture
    def policy(self, cpl_policy_network):
        # Deterministic weights so a shape or range regression is the only way
        # this can fail.
        torch.manual_seed(0)
        return cpl_policy_network.PolicyNetwork(state_dim=280, action_dim=6, hidden_dim=64)

    @pytest.mark.unit
    def test_get_action_returns_action_log_prob_value_entropy(self, policy):
        state = torch.randn(280)
        action, log_prob, value, entropy = policy.get_action(state)

        for tensor in (action, log_prob, value, entropy):
            assert isinstance(tensor, torch.Tensor)
        assert 0 <= int(action.item()) < policy.action_dim, "action must index a real action"
        assert log_prob.item() <= 0.0, "a log probability is never positive"
        assert entropy.item() >= 0.0, "entropy is never negative"

    @pytest.mark.unit
    def test_deterministic_mode_is_deterministic_in_eval(self, policy):
        """The serving property: same state in, same action out.

        This failed when written, because nn.Module starts in train() and the
        trunk carries Dropout(0.1) — so `deterministic=True` returned a
        different action each call, through a randomly thinned network.
        torch.no_grad() at the call site does not help; it stops gradients, not
        dropout. services/cpl-service/main.py now calls .eval() on startup.
        """
        policy.eval()
        state = torch.randn(280)

        first, _, _, _ = policy.get_action(state, deterministic=True)
        second, _, _, _ = policy.get_action(state, deterministic=True)

        assert torch.equal(first, second), "deterministic=True must not sample"

    @pytest.mark.unit
    def test_dropout_still_active_in_train_mode(self, policy):
        """And the other half: eval() must not have disabled learning noise."""
        policy.train()
        state = torch.randn(1, 280)

        outputs = {policy.forward(state)['logits'].sum().item() for _ in range(12)}

        assert len(outputs) > 1, "train mode should still apply dropout"

    @pytest.mark.unit
    def test_evaluate_actions_returns_one_value_per_row(self, policy):
        batch_size = 32
        states = torch.randn(batch_size, 280)
        actions = torch.randint(0, policy.action_dim, (batch_size,))

        log_probs, values, entropy, costs = policy.evaluate_actions(states, actions)

        # The shape this file always asserted, now against the real network.
        assert log_probs.shape == (batch_size,)
        assert values.shape == (batch_size,)
        assert entropy.shape == (batch_size,)
        assert costs.shape == (batch_size,)

    @pytest.mark.unit
    def test_evaluate_actions_agrees_with_get_action(self, policy):
        """The log prob of an action must be the same however you ask for it."""
        policy.eval()
        state = torch.randn(1, 280)
        action, log_prob, _, _ = policy.get_action(state, deterministic=True)

        log_probs, _, _, _ = policy.evaluate_actions(state, action.reshape(1))

        assert torch.allclose(log_probs, log_prob.reshape(1), atol=1e-5)

    @pytest.mark.unit
    def test_predict_cost_returns_one_cost_per_row(self, policy):
        costs = policy.predict_cost(torch.randn(8, 280))

        assert costs.shape == (8,)
        assert torch.isfinite(costs).all()


class TestCostGovernorBudgetCeiling:
    """Hard Rule #4: the cost governor gates every action."""

    @pytest.mark.unit
    async def test_spend_under_the_ceiling_is_allowed(self, cpl_cost_governor, fake_redis):
        governor = cpl_cost_governor.CostGovernor(
            redis_client=fake_redis({"gpu": "10.0"}), default_budget=50.0, safety_margin=0.1
        )

        allowed, detail = await governor.check_budget("tenant-1", 5.0)

        assert allowed is True
        assert detail["current_spend"] == 10.0
        assert detail["status"] == "healthy"

    @pytest.mark.unit
    async def test_spend_over_the_ceiling_is_refused_with_the_violation(self, cpl_cost_governor, fake_redis):
        # 10% safety margin on a 50.0 budget leaves 45.0 spendable.
        governor = cpl_cost_governor.CostGovernor(
            redis_client=fake_redis({"gpu": "44.0"}), default_budget=50.0, safety_margin=0.1
        )

        allowed, detail = await governor.check_budget("tenant-1", 10.0)

        assert allowed is False
        assert detail["reason"] == "budget_exceeded"
        assert detail["violation"] == pytest.approx(9.0), "44 + 10 is 9 over the 45 ceiling"

    @pytest.mark.unit
    async def test_approaching_the_ceiling_warns_before_it_refuses(self, cpl_cost_governor, fake_redis):
        governor = cpl_cost_governor.CostGovernor(
            redis_client=fake_redis({"gpu": "35.0"}), default_budget=50.0, safety_margin=0.1
        )

        allowed, detail = await governor.check_budget("tenant-1", 1.0)

        assert allowed is True
        assert detail["status"] == "warning", "36 of 45 is past the 80% threshold"
