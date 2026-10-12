"""Unit tests for the simulation service's reward, calibration, campaign and
policy-registry modules.

main.py imported all four before any of them existed, so the service could not
boot. The hyphen in services/simulation-service means they are loaded by path.
"""

import asyncio
import math

import pytest

SIM = "services/simulation-service"


@pytest.fixture(scope="module")
def reward(service_module):
    return service_module(f"{SIM}/engine/economic_reward.py", "sim_economic_reward")


@pytest.fixture(scope="module")
def bridge_mod(service_module):
    return service_module(f"{SIM}/engine/sim_to_real.py", "sim_sim_to_real")


@pytest.fixture(scope="module")
def campaign(service_module):
    return service_module(f"{SIM}/agents/campaign_agent.py", "sim_campaign_agent")


@pytest.fixture(scope="module")
def registry_mod(service_module):
    return service_module(f"{SIM}/agents/policy_registry.py", "sim_policy_registry")


def run(coro):
    loop = asyncio.new_event_loop()
    try:
        return loop.run_until_complete(coro)
    finally:
        loop.close()


class TestEconomicRewardEngine:
    @pytest.mark.unit
    def test_outcome_arithmetic(self, reward):
        engine = reward.EconomicRewardEngine()
        outcome = engine.compute_outcome(conversions=4, total_spend=50.0, avg_order_value=50.0)

        assert outcome.revenue == 200.0
        assert outcome.gross_profit == pytest.approx(200.0 * 0.4 - 50.0)
        assert outcome.roas == pytest.approx(4.0)
        assert outcome.cac == pytest.approx(12.5)

    @pytest.mark.unit
    def test_spend_without_conversions_is_penalised_but_finite(self, reward):
        engine = reward.EconomicRewardEngine()
        outcome = engine.compute_outcome(conversions=0, total_spend=80.0)
        assert math.isinf(outcome.cac)

        total, parts = engine.compute_reward(outcome, {}, {'budget_allocation': 100.0})

        assert math.isfinite(total), "one dead hour must not sink the whole episode"
        assert parts['risk_penalty'] == 1.0
        assert total < 0

    @pytest.mark.unit
    def test_beating_target_roas_earns_a_bonus(self, reward):
        engine = reward.EconomicRewardEngine()
        good = engine.compute_outcome(conversions=10, total_spend=100.0)
        _, parts = engine.compute_reward(good, {}, {'budget_allocation': 100.0})
        assert parts['roas_bonus'] == pytest.approx(min(5.0 - 3.0, 2.0))

    @pytest.mark.unit
    def test_no_spend_no_risk(self, reward):
        engine = reward.EconomicRewardEngine()
        idle = engine.compute_outcome(conversions=0, total_spend=0.0)
        assert idle.cac == 0.0
        _, parts = engine.compute_reward(idle, {}, {})
        assert parts['risk_penalty'] == 0.0


class TestSimToRealBridge:
    @pytest.mark.unit
    def test_randomization_keeps_mix_and_bounds_budget(self, bridge_mod):
        bridge = bridge_mod.SimToRealBridge(seed=7)
        mix = {'meta': 0.4, 'google': 0.4, 'tiktok': 0.2}
        for _ in range(50):
            out = bridge.apply_domain_randomization({'budget_allocation': 100.0, 'channel_mix': mix})
            assert 80.0 <= out['budget_allocation'] <= 120.0
            assert out['channel_mix'] == mix

    @pytest.mark.unit
    def test_randomization_off_is_identity(self, bridge_mod):
        bridge = bridge_mod.SimToRealBridge()
        params = {'budget_allocation': 100.0}
        assert bridge.apply_domain_randomization(params, randomize=False) == params

    @pytest.mark.unit
    def test_calibration_learns_cpm_bias_and_reports_quality(self, bridge_mod):
        bridge = bridge_mod.SimToRealBridge(calibration_window=48, learning_rate=0.5)
        assert bridge.get_calibration_quality()['quality'] == 'insufficient_data'

        for _ in range(30):
            bridge.calibrate({'cpm': 10.0}, {'channel': 'meta', 'cpm': 12.0})

        assert bridge.cpm_bias['meta'] == pytest.approx(2.0, abs=1e-3)
        params = bridge.get_calibrated_params({'base_cpm_meta': 10.0})
        assert params['base_cpm_meta'] == pytest.approx(12.0, abs=1e-3)
        assert bridge.get_calibration_quality()['quality'] == 'building'

        for _ in range(30):
            bridge.calibrate({'cpm': 10.0}, {'channel': 'meta', 'cpm': 12.0})
        quality = bridge.get_calibration_quality()
        assert quality['quality'] == 'good'
        assert quality['data_points'] == 48, "history is capped at the window"


class _Recorder:
    def __init__(self, fail=False):
        self.calls = []
        self.fail = fail

    async def set_budget(self, campaign_id, amount):
        if self.fail:
            raise RuntimeError("platform down")
        self.calls.append((campaign_id, amount))

    async def send(self, topic, value):
        self.calls.append((topic, value))


class TestCampaignAgent:
    @pytest.mark.unit
    def test_unknown_campaign_raises(self, campaign):
        agent = campaign.CampaignAgent()
        with pytest.raises(KeyError):
            run(agent.execute_action('nope', {'budget_allocation': 1, 'channel_mix': {'meta': 1.0}}))

    @pytest.mark.unit
    def test_splits_budget_applies_where_a_client_exists(self, campaign):
        meta, producer = _Recorder(), _Recorder()
        agent = campaign.CampaignAgent(event_producer=producer, api_clients={'meta': meta})
        agent.register_campaign(campaign.CampaignConfig(campaign_id='c1', budget_daily=500.0))

        result = run(agent.execute_action('c1', {
            'budget_allocation': 200.0,
            'channel_mix': {'meta': 0.75, 'google': 0.25},
        }))

        assert meta.calls == [('c1', 150.0)]
        assert result['channels']['meta'] == {'budget': 150.0, 'status': 'applied'}
        assert result['channels']['google'] == {'budget': 50.0, 'status': 'planned'}
        assert producer.calls[0][0] == 'execution.outcome'

    @pytest.mark.unit
    def test_platform_failure_is_reported_not_hidden(self, campaign):
        agent = campaign.CampaignAgent(api_clients={'meta': _Recorder(fail=True)})
        agent.register_campaign(campaign.CampaignConfig(campaign_id='c1', budget_daily=500.0))
        result = run(agent.execute_action('c1', {'budget_allocation': 10.0, 'channel_mix': {'meta': 1.0}}))
        assert result['channels']['meta']['status'] == 'failed'

    @pytest.mark.unit
    @pytest.mark.parametrize('action', [
        {'budget_allocation': 600.0, 'channel_mix': {'meta': 1.0}},
        {'budget_allocation': 10.0, 'channel_mix': {'meta': 0.5}},
        {'budget_allocation': 10.0, 'channel_mix': {}},
        {'budget_allocation': 10.0, 'channel_mix': {'meta': 1.5, 'google': -0.5}},
    ])
    def test_rejects_actions_it_cannot_apply(self, campaign, action):
        agent = campaign.CampaignAgent()
        agent.register_campaign(campaign.CampaignConfig(campaign_id='c1', budget_daily=500.0))
        with pytest.raises(ValueError):
            run(agent.execute_action('c1', action))


class TestPolicyRegistry:
    @pytest.mark.unit
    def test_lifecycle_persists_across_instances(self, registry_mod, tmp_path):
        registry = registry_mod.PolicyRegistry(str(tmp_path))
        registry.register_version('v1', {'w': [1, 2]}, 'first', 10, {'roas': 3.1})
        registry.register_version('v2', {'w': [3]}, 'second', 20, {'roas': 3.4})

        assert registry.deploy_version('v1')
        assert registry.promote_version('v1')
        assert registry.promote_version('v2')
        assert not registry.deploy_version('missing')

        reopened = registry_mod.PolicyRegistry(str(tmp_path))
        assert reopened.active_version == 'v2'
        assert reopened.versions['v1'].status == 'superseded'
        assert reopened.versions['v2'].status == 'active'
        assert reopened.load_policy_state('v1') == {'w': [1, 2]}

    @pytest.mark.unit
    def test_path_from_env(self, registry_mod, tmp_path, monkeypatch):
        monkeypatch.setenv('POLICY_REGISTRY_PATH', str(tmp_path / 'p'))
        assert registry_mod.PolicyRegistry().registry_path == tmp_path / 'p'

    @pytest.mark.unit
    @pytest.mark.parametrize('bad', ['../escape', 'a/b', '', '.hidden'])
    def test_rejects_ids_that_are_not_file_names(self, registry_mod, tmp_path, bad):
        registry = registry_mod.PolicyRegistry(str(tmp_path))
        with pytest.raises(ValueError):
            registry.register_version(bad, {}, 'x', 1, {})

    @pytest.mark.unit
    def test_duplicate_id_rejected(self, registry_mod, tmp_path):
        registry = registry_mod.PolicyRegistry(str(tmp_path))
        registry.register_version('v1', {}, 'x', 1, {})
        with pytest.raises(ValueError):
            registry.register_version('v1', {}, 'x', 1, {})
