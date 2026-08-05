"""
SpiderNet OS - CPL Policy Network (Actor-Critic with Cost Head)
Streaming PPO with RTX 5090 optimization
"""

import torch
import torch.nn as nn
import torch.nn.functional as F
from typing import Tuple, Dict


class PolicyNetwork(nn.Module):
    """
    Actor-Critic policy network with cost prediction head.
    
    Architecture:
    - Shared trunk: 512-dim MLP with LayerNorm
    - Actor head: 6 discrete actions [spawn, kill, route, budget, temp, prioritize]
    - Critic head: State value estimation
    - Cost head: Predicts expected cost for Lagrangian penalty
    
    Input: 280-dim state vector
    - system_graph_gnn_embedding: 256-dim
    - cost_vector: 6-dim
    - performance_vector: 6-dim
    - agent_health: 8-dim
    - queue_depths: 4-dim
    """
    
    def __init__(
        self,
        state_dim: int = 280,
        action_dim: int = 6,
        hidden_dim: int = 512,
        use_fp16: bool = True
    ):
        super().__init__()
        
        self.state_dim = state_dim
        self.action_dim = action_dim
        self.use_fp16 = use_fp16
        
        # Shared trunk with LayerNorm for training stability
        self.shared = nn.Sequential(
            nn.Linear(state_dim, hidden_dim),
            nn.LayerNorm(hidden_dim),
            nn.ReLU(),
            nn.Dropout(0.1),
            
            nn.Linear(hidden_dim, hidden_dim),
            nn.LayerNorm(hidden_dim),
            nn.ReLU(),
            nn.Dropout(0.1),
        )
        
        # Actor head: action logits
        self.actor = nn.Linear(hidden_dim, action_dim)
        
        # Critic head: state value
        self.critic = nn.Linear(hidden_dim, 1)
        
        # Cost head: expected cost prediction
        self.cost_head = nn.Sequential(
            nn.Linear(hidden_dim, hidden_dim // 2),
            nn.ReLU(),
            nn.Linear(hidden_dim // 2, 1),
            nn.Softplus()  # Ensure positive cost
        )
        
        # Entropy temperature (learnable)
        self.log_entropy_coef = nn.Parameter(torch.zeros(1))
        
        self._init_weights()
        
    def _init_weights(self):
        """Orthogonal initialization for stable training"""
        for module in self.modules():
            if isinstance(module, nn.Linear):
                nn.init.orthogonal_(module.weight, gain=1.0)
                if module.bias is not None:
                    nn.init.constant_(module.bias, 0)
    
    def forward(self, state: torch.Tensor) -> Dict[str, torch.Tensor]:
        """
        Forward pass through all heads.
        
        Args:
            state: (batch, state_dim) state tensor
            
        Returns:
            Dict with logits, value, cost, entropy_coef
        """
        if self.use_fp16 and state.device.type == 'cuda':
            state = state.half()
            
        x = self.shared(state)
        
        logits = self.actor(x)
        value = self.critic(x)
        cost = self.cost_head(x)
        entropy_coef = torch.exp(self.log_entropy_coef)
        
        return {
            'logits': logits,
            'value': value,
            'cost': cost,
            'entropy_coef': entropy_coef
        }
    
    def get_action(
        self,
        state: torch.Tensor,
        deterministic: bool = False
    ) -> Tuple[torch.Tensor, torch.Tensor, torch.Tensor, torch.Tensor]:
        """
        Sample action from policy.
        
        Args:
            state: (batch, state_dim) or (state_dim,)
            deterministic: If True, return argmax action
            
        Returns:
            action, log_prob, value, entropy
        """
        if state.dim() == 1:
            state = state.unsqueeze(0)
            
        output = self.forward(state)
        logits = output['logits']
        value = output['value']
        
        probs = F.softmax(logits, dim=-1)
        dist = torch.distributions.Categorical(probs)
        
        if deterministic:
            action = torch.argmax(probs, dim=-1)
        else:
            action = dist.sample()
            
        log_prob = dist.log_prob(action)
        entropy = dist.entropy()
        
        return action, log_prob, value.squeeze(-1), entropy
    
    def evaluate_actions(
        self,
        state: torch.Tensor,
        actions: torch.Tensor
    ) -> Tuple[torch.Tensor, torch.Tensor, torch.Tensor, torch.Tensor]:
        """
        Evaluate actions for PPO update.
        
        Args:
            state: (batch, state_dim)
            actions: (batch,)
            
        Returns:
            log_probs, values, entropy, cost
        """
        output = self.forward(state)
        logits = output['logits']
        value = output['value']
        cost = output['cost']
        
        probs = F.softmax(logits, dim=-1)
        dist = torch.distributions.Categorical(probs)
        
        log_probs = dist.log_prob(actions)
        entropy = dist.entropy()
        
        return log_probs, value.squeeze(-1), entropy, cost.squeeze(-1)
    
    def predict_cost(self, state: torch.Tensor) -> torch.Tensor:
        """Predict expected cost for Lagrangian constraint"""
        output = self.forward(state)
        return output['cost'].squeeze(-1)
    
    def get_value(self, state: torch.Tensor) -> torch.Tensor:
        """Get state value estimate"""
        output = self.forward(state)
        return output['value'].squeeze(-1)
    
    def save_checkpoint(self, path: str, metadata: Dict = None):
        """Save model checkpoint with metadata"""
        checkpoint = {
            'model_state_dict': self.state_dict(),
            'state_dim': self.state_dim,
            'action_dim': self.action_dim,
            'metadata': metadata or {}
        }
        torch.save(checkpoint, path)
    
    @classmethod
    def load_checkpoint(cls, path: str, device: str = 'cuda'):
        """Load model from checkpoint"""
        checkpoint = torch.load(path, map_location=device)
        model = cls(
            state_dim=checkpoint['state_dim'],
            action_dim=checkpoint['action_dim']
        )
        model.load_state_dict(checkpoint['model_state_dict'])
        return model, checkpoint.get('metadata', {})


class StateEncoder:
    """
    Encodes system state into 280-dim vector for policy network.
    
    Handles:
    - GNN graph embedding (256-dim from Neo4j)
    - Cost vector (6-dim: GPU, tokens, latency, memory, storage, network)
    - Performance vector (6-dim: accuracy, revenue, success, throughput, efficiency, stability)
    - Agent health (8-dim: active agents status)
    - Queue depths (4-dim: GPU 0/1 queues, CPU queue, network queue)
    """
    
    def __init__(self, gnn_embedding_dim: int = 256):
        self.gnn_dim = gnn_embedding_dim
        self.state_dim = 280
        
    def encode(
        self,
        system_graph_embedding: torch.Tensor,
        cost_vector: torch.Tensor,
        performance_vector: torch.Tensor,
        agent_health: torch.Tensor,
        queue_depths: torch.Tensor
    ) -> torch.Tensor:
        """
        Encode all components into state vector.
        
        Args:
            system_graph_embedding: (256,) from GNN
            cost_vector: (6,) current costs
            performance_vector: (6,) performance metrics
            agent_health: (8,) agent status
            queue_depths: (4,) queue depths
            
        Returns:
            state: (280,) concatenated state vector
        """
        # Normalize inputs
        cost_norm = cost_vector / (torch.norm(cost_vector) + 1e-8)
        perf_norm = performance_vector / (torch.norm(performance_vector) + 1e-8)
        
        # Concatenate
        state = torch.cat([
            system_graph_embedding,
            cost_norm,
            perf_norm,
            agent_health,
            queue_depths
        ])
        
        return state
    
    def encode_batch(
        self,
        system_graph_embeddings: torch.Tensor,
        cost_vectors: torch.Tensor,
        performance_vectors: torch.Tensor,
        agent_healths: torch.Tensor,
        queue_depths: torch.Tensor
    ) -> torch.Tensor:
        """Batch encoding"""
        batch_size = system_graph_embeddings.shape[0]
        
        states = []
        for i in range(batch_size):
            state = self.encode(
                system_graph_embeddings[i],
                cost_vectors[i],
                performance_vectors[i],
                agent_healths[i],
                queue_depths[i]
            )
            states.append(state)
            
        return torch.stack(states)
