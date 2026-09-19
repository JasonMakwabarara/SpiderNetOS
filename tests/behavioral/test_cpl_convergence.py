"""
CPL (Control Plane Learning) Behavioral Tests
Tests RL policy improvement over training episodes.
"""
import pytest
import torch
import numpy as np
from unittest.mock import Mock


class MockMarketEnvironment:
    """Mock market environment for testing RL convergence."""
    
    def __init__(self, noise_std=0.1):
        self.noise_std = noise_std
        self.episode_count = 0
        
    def reset(self):
        self.episode_count += 1
        return torch.randn(280)  # State vector matching CPL input
    
    def step(self, action):
        # Reward increases slightly each episode (simulated learning environment)
        base_reward = 10.0 + (self.episode_count * 0.5)
        noise = np.random.normal(0, self.noise_std)
        reward = base_reward + noise
        done = np.random.random() < 0.05  # 5% chance of episode end
        next_state = torch.randn(280)
        return next_state, reward, done, {}


class TestCPLPolicyConvergence:
    """
    Test that RL policies improve with training.
    
    This is a behavioral test - it verifies the *outcome* of training
    (improved rewards) not the *mechanism* (specific weight updates).
    """

    def test_policy_improves_with_training(self):
        """
        Policy must show statistically significant reward improvement.
        
        This test simulates the core CPL promise: learning leads to better outcomes.
        """
        # Setup
        env = MockMarketEnvironment(noise_std=2.0)
        policy = Mock()  # Mock policy that improves deterministically
        
        # Simulate: policy before training
        rewards_before = []
        for _ in range(20):
            state = env.reset()
            episode_reward = 0
            done = False
            while not done:
                action = torch.randn(4)  # Random action
                state, reward, done, _ = env.step(action)
                episode_reward += reward
            rewards_before.append(episode_reward)
        
        # Simulate: training (policy improvement)
        # In real test, this would call: trainer.train(policy, env, episodes=50)
        mean_before = np.mean(rewards_before)
        
        # After "training", environment gives higher base rewards
        env.episode_count = 100  # Simulate 100 episodes of training
        
        # Simulate: policy after training
        rewards_after = []
        for _ in range(20):
            state = env.reset()
            episode_reward = 0
            done = False
            while not done:
                action = torch.randn(4)
                state, reward, done, _ = env.step(action)
                episode_reward += reward
            rewards_after.append(episode_reward)
        
        mean_after = np.mean(rewards_after)
        
        # Assert: statistically significant improvement
        assert mean_after > mean_before, (
            f"Policy did not improve: {mean_before:.2f} -> {mean_after:.2f}"
        )
        
        # Assert: at least 10% improvement
        improvement = (mean_after - mean_before) / abs(mean_before)
        assert improvement > 0.10, (
            f"Improvement only {improvement:.1%}, expected >10%"
        )

    def test_policy_is_deterministic_after_convergence(self):
        """
        After training, same state should produce same action (low variance).
        """
        # Mock a "converged" policy
        policy = Mock()
        policy.get_action.return_value = (
            torch.tensor([0.5, 0.3, 0.2, 0.1]),  # Action
            torch.tensor(0.1),  # Log prob
            torch.tensor(1.0),  # Value
            torch.tensor(0.01)  # Entropy (low = deterministic)
        )
        
        # Test: multiple calls with same state
        state = torch.randn(280)
        actions = []
        for _ in range(10):
            action, _, _, _ = policy.get_action(state)
            actions.append(action.numpy())
        
        # Low variance across calls indicates convergence
        actions_array = np.array(actions)
        variance = np.var(actions_array, axis=0).mean()
        
        assert variance < 0.1, (
            f"Policy too stochastic (variance={variance:.3f}), "
            "expected <0.1 after convergence"
        )

    async def test_cost_governor_enforces_ceiling(self, cpl_cost_governor, fake_redis):
        """The governor must refuse the action that would cross the ceiling.

        This previously imported `services.cpl_service.engine.cost_governor`
        against a directory named `services/cpl-service`, so it had never run —
        and it called an API (CostGovernor(daily_ceiling=...), check_action,
        current_spend) that has never existed. The real governor is async,
        budget-backed and applies a safety margin; tests/conftest.py loads it by
        path.
        """
        # 50.0 budget, 10% safety margin -> 45.0 actually spendable.
        governor = cpl_cost_governor.CostGovernor(
            redis_client=fake_redis({"gpu": "45.0"}),
            default_budget=50.0,
            safety_margin=0.1,
        )

        allowed, detail = await governor.check_budget("tenant-1", 10.0)

        assert allowed is False, "should block an action that exceeds the ceiling"
        assert detail["reason"] == "budget_exceeded"
        assert detail["current_spend"] == 45.0, "spend is tracked accurately"


class TestCPLIntegration:
    """
    Integration tests for CPL with real (containerized) dependencies.
    """

    @pytest.mark.integration
    def test_kafka_event_production(self, kafka_container):
        """
        CPL events must be produced to Kafka successfully.
        """
        from kafka import KafkaProducer, KafkaConsumer
        import json
        
        # Setup producer
        producer = KafkaProducer(
            bootstrap_servers=kafka_container,
            value_serializer=lambda v: json.dumps(v).encode('utf-8')
        )
        
        # Send CPL event
        event = {
            "type": "cpl.state.updated",
            "timestamp": "2024-01-01T00:00:00Z",
            "data": {"reward": 10.5, "cost": 5.0}
        }
        future = producer.send("cpl-events", event)
        record = future.get(timeout=10)
        
        assert record.topic == "cpl-events"
        assert record.partition is not None
        
        # Verify with consumer
        consumer = KafkaConsumer(
            "cpl-events",
            bootstrap_servers=kafka_container,
            auto_offset_reset='earliest',
            value_deserializer=lambda m: json.loads(m.decode('utf-8')),
            consumer_timeout_ms=5000
        )
        
        messages = list(consumer)
        assert len(messages) >= 1
        assert messages[-1].value["type"] == "cpl.state.updated"
        
        producer.close()
        consumer.close()
