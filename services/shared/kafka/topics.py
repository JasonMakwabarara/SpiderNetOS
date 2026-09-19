"""
SpiderNet OS - Kafka Topics Definition
Event-driven architecture with replay capability
"""

from typing import Any, Dict


class KafkaTopics:
    """
    Centralized Kafka topic definitions with configuration.

    All topics follow Event Sourcing pattern (Fowler):
    - Immutable events
    - Replayable from log
    - Source of truth
    """

    # CPL (Control Plane Learning) Topics
    CPL_STATE_UPDATED = "cpl.state.updated"
    CPL_ACTION_EXECUTED = "cpl.action.executed"
    CPL_POLICY_UPDATED = "cpl.policy.updated"

    # MetaPlanner Topics
    METAPLANNER_PLAN_SCORED = "metaplanner.plan.scored"
    METAPLANNER_PLAN_SELECTED = "metaplanner.plan.selected"
    METAPLANNER_PLAN_REJECTED = "metaplanner.plan.rejected"

    # Friction Miner Topics
    FRICTION_OPPORTUNITY_DETECTED = "friction.opportunity.detected"
    FRICTION_OPPORTUNITY_GATED = "friction.opportunity.gated"  # Passed gate
    FRICTION_OPPORTUNITY_FILTERED = "friction.opportunity.filtered"  # Rejected by gate
    FRICTION_OPPORTUNITY_EXECUTED = "friction.opportunity.executed"

    # Execution Topics
    EXECUTION_OUTCOME = "execution.outcome"
    EXECUTION_STARTED = "execution.started"
    EXECUTION_FAILED = "execution.failed"

    # Cost & Reward Topics
    COST_EVENT = "cost.event"
    REWARD_FEEDBACK = "reward.feedback"
    COST_VIOLATION = "cost.violation"

    # Memory Topics
    MEMORY_STORED = "memory.stored"
    MEMORY_RETRIEVED = "memory.retrieved"

    # Simulation Topics (RL Training Environment)
    SIMULATION_EPISODE_START = "simulation.episode.start"
    SIMULATION_EPISODE_END = "simulation.episode.end"
    SIMULATION_STATE = "simulation.state"
    SIMULATION_ACTION = "simulation.action"
    SIMULATION_REWARD = "simulation.reward"
    SIMULATION_CALIBRATION_UPDATE = "simulation.calibration.update"
    SIMULATION_DEPLOYMENT_REQUEST = "simulation.deployment.request"
    SIMULATION_DEPLOYMENT_APPROVED = "simulation.deployment.approved"
    POLICY_VERSION_REGISTERED = "policy.version.registered"
    POLICY_ROLLOUT_STARTED = "policy.rollout.started"
    POLICY_PROMOTED = "policy.promoted"
    POLICY_ROLLED_BACK = "policy.rolled.back"

    @classmethod
    def get_all_topics(cls) -> list:
        """Get list of all topic names"""
        return [
            cls.CPL_STATE_UPDATED,
            cls.CPL_ACTION_EXECUTED,
            cls.CPL_POLICY_UPDATED,
            cls.METAPLANNER_PLAN_SCORED,
            cls.METAPLANNER_PLAN_SELECTED,
            cls.METAPLANNER_PLAN_REJECTED,
            cls.FRICTION_OPPORTUNITY_DETECTED,
            cls.FRICTION_OPPORTUNITY_GATED,
            cls.FRICTION_OPPORTUNITY_FILTERED,
            cls.FRICTION_OPPORTUNITY_EXECUTED,
            cls.EXECUTION_OUTCOME,
            cls.EXECUTION_STARTED,
            cls.EXECUTION_FAILED,
            cls.COST_EVENT,
            cls.REWARD_FEEDBACK,
            cls.COST_VIOLATION,
            cls.MEMORY_STORED,
            cls.MEMORY_RETRIEVED,
            # Simulation topics
            cls.SIMULATION_EPISODE_START,
            cls.SIMULATION_EPISODE_END,
            cls.SIMULATION_STATE,
            cls.SIMULATION_ACTION,
            cls.SIMULATION_REWARD,
            cls.SIMULATION_CALIBRATION_UPDATE,
            cls.SIMULATION_DEPLOYMENT_REQUEST,
            cls.SIMULATION_DEPLOYMENT_APPROVED,
            cls.POLICY_VERSION_REGISTERED,
            cls.POLICY_ROLLOUT_STARTED,
            cls.POLICY_PROMOTED,
            cls.POLICY_ROLLED_BACK,
        ]

    @classmethod
    def get_topic_config(cls, topic: str) -> Dict[str, Any]:
        """Get Kafka topic configuration"""
        configs = {
            # High-throughput topics (CPL state updates)
            cls.CPL_STATE_UPDATED: {
                'partitions': 6,
                'replication': 1,
                'retention_ms': 86400000,  # 24 hours
                'cleanup_policy': 'delete',
                'compression': 'lz4'
            },

            # Critical events (opportunities)
            cls.FRICTION_OPPORTUNITY_DETECTED: {
                'partitions': 3,
                'replication': 1,
                'retention_ms': 604800000,  # 7 days
                'cleanup_policy': 'compact,delete',
                'compression': 'snappy'
            },

            # Decision events (plans)
            cls.METAPLANNER_PLAN_SELECTED: {
                'partitions': 3,
                'replication': 1,
                'retention_ms': 2592000000,  # 30 days (audit)
                'cleanup_policy': 'compact',
                'compression': 'snappy'
            },

            # Execution outcomes (training data)
            cls.EXECUTION_OUTCOME: {
                'partitions': 6,
                'replication': 1,
                'retention_ms': 604800000,  # 7 days
                'cleanup_policy': 'delete',
                'compression': 'lz4'
            },

            # Cost events (financial audit)
            cls.COST_EVENT: {
                'partitions': 3,
                'replication': 1,
                'retention_ms': 31536000000,  # 1 year
                'cleanup_policy': 'compact',
                'compression': 'gzip'
            },

            # Default config
            'default': {
                'partitions': 3,
                'replication': 1,
                'retention_ms': 86400000,
                'cleanup_policy': 'delete',
                'compression': 'snappy'
            }
        }

        return configs.get(topic, configs['default'])

    @classmethod
    def get_consumer_groups(cls) -> Dict[str, list]:
        """Define consumer groups and their subscribed topics"""
        return {
            'cpl-service': [
                cls.CPL_STATE_UPDATED,
                cls.EXECUTION_OUTCOME,
                cls.REWARD_FEEDBACK,
                cls.COST_VIOLATION
            ],
            'metaplanner-service': [
                cls.FRICTION_OPPORTUNITY_GATED,
                cls.CPL_STATE_UPDATED,
                cls.EXECUTION_OUTCOME
            ],
            'friction-miner': [
                cls.EXECUTION_OUTCOME,
                cls.COST_EVENT
            ],
            'cost-governor': [
                cls.COST_EVENT,
                cls.COST_VIOLATION
            ],
            'memory-service': [
                cls.MEMORY_STORED,
                cls.MEMORY_RETRIEVED,
                cls.EXECUTION_OUTCOME
            ],
            'simulation-service': [
                cls.SIMULATION_EPISODE_START,
                cls.SIMULATION_EPISODE_END,
                cls.SIMULATION_STATE,
                cls.SIMULATION_ACTION,
                cls.SIMULATION_REWARD,
                cls.EXECUTION_OUTCOME,
                cls.POLICY_VERSION_REGISTERED,
                cls.POLICY_ROLLOUT_STARTED
            ],
            'cpl-training': [
                cls.SIMULATION_EPISODE_END,
                cls.SIMULATION_CALIBRATION_UPDATE,
                cls.POLICY_PROMOTED,
                cls.POLICY_ROLLED_BACK
            ]
        }
