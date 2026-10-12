"""
SpiderNet OS - Campaign Agent
Applies a policy action (a budget and a channel mix) to a real campaign.

Per-channel ad platform clients are injected as `api_clients`; each needs an
async `set_budget(campaign_id, amount)`. A channel without a client is recorded
as `planned` rather than pretending it was applied.
"""

import logging
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any, Dict, Optional

logger = logging.getLogger(__name__)

MIX_TOLERANCE = 0.01


@dataclass
class CampaignConfig:
    campaign_id: str
    budget_daily: float
    target_roas: float = 3.0
    target_cac: float = 30.0
    channel_mix: Dict[str, float] = field(default_factory=dict)


class CampaignAgent:
    def __init__(
        self,
        event_producer: Optional[Any] = None,
        api_clients: Optional[Dict[str, Any]] = None,
        outcome_topic: str = "execution.outcome",
    ):
        self.event_producer = event_producer
        self.api_clients = api_clients or {}
        self.outcome_topic = outcome_topic
        self.campaigns: Dict[str, CampaignConfig] = {}

    def register_campaign(self, config: CampaignConfig) -> CampaignConfig:
        self.campaigns[config.campaign_id] = config
        return config

    async def execute_action(self, campaign_id: str, action: Dict) -> Dict:
        """
        Split the action's budget across channels and push it to each
        platform. Raises KeyError for an unknown campaign and ValueError for
        an action that cannot be applied as given.
        """
        config = self.campaigns.get(campaign_id)
        if config is None:
            raise KeyError(campaign_id)

        budget = float(action.get('budget_allocation', 0.0))
        channel_mix = action.get('channel_mix') or {}

        if budget < 0:
            raise ValueError("budget_allocation must not be negative")
        if budget > config.budget_daily:
            raise ValueError(
                f"budget_allocation {budget:.2f} exceeds the campaign's daily budget "
                f"{config.budget_daily:.2f}"
            )
        if not channel_mix:
            raise ValueError("channel_mix must name at least one channel")
        if any(weight < 0 for weight in channel_mix.values()):
            raise ValueError("channel_mix weights must not be negative")
        if abs(sum(channel_mix.values()) - 1.0) > MIX_TOLERANCE:
            raise ValueError("channel_mix weights must sum to 1.0")

        channels = {}
        for channel, weight in channel_mix.items():
            amount = round(budget * weight, 2)
            client = self.api_clients.get(channel)
            if client is None:
                channels[channel] = {'budget': amount, 'status': 'planned'}
                continue
            try:
                await client.set_budget(campaign_id, amount)
                channels[channel] = {'budget': amount, 'status': 'applied'}
            except Exception as e:
                logger.error("Failed to set %s budget for %s: %s", channel, campaign_id, e)
                channels[channel] = {'budget': amount, 'status': 'failed', 'error': str(e)}

        config.channel_mix = dict(channel_mix)
        result = {
            'campaign_id': campaign_id,
            'budget_allocation': budget,
            'channels': channels,
            'executed_at': datetime.now(timezone.utc).isoformat(),
        }

        if self.event_producer is not None:
            await self.event_producer.send(topic=self.outcome_topic, value=result)

        return result
