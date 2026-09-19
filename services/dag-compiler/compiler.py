from typing import Any, Dict, List

from pydantic import BaseModel, Field


class WorkflowStep(BaseModel):
    id: str
    type: str
    config: Dict[str, Any]
    depends_on: List[str] = Field(default_factory=list)

class ProposedDAG(BaseModel):
    trigger: str
    steps: List[WorkflowStep]

class DAGCompiler:
    def __init__(self, allowed_actions: List[str]):
        self.allowed_actions = allowed_actions
        self.max_depth = 50
        self.max_nodes = 100

    def validate_and_compile(self, payload: Dict[str, Any]) -> ProposedDAG:
        dag = ProposedDAG(**payload)

        if len(dag.steps) > self.max_nodes:
            raise ValueError(f"Graph rejection: Node limit exceeding {self.max_nodes}")

        graph = {step.id: step.depends_on for step in dag.steps}
        if self.has_circular_dependencies(graph):
            raise ValueError("Graph rejection: Malformed schema topology (Circular Loop Caught)")

        for step in dag.steps:
            if step.type not in self.allowed_actions:
                raise ValueError(f"Security validation breach: Action variant unauthorized: {step.type}")

        return dag

    def has_circular_dependencies(self, graph: Dict[str, List[str]]) -> bool:
        visited = set()
        rec_stack = set()

        def dfs(node):
            if node in rec_stack:
                return True
            if node in visited:
                return False
            rec_stack.add(node)
            for neighbor in graph.get(node, []):
                if dfs(neighbor):
                    return True
            rec_stack.remove(node)
            visited.add(node)
            return False

        for node in graph:
            if dfs(node):
                return True
        return False
