from typing import Any, Dict, List

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field

app = FastAPI(title="DAG Compiler Service")

class WorkflowStep(BaseModel):
    id: str
    type: str
    config: Dict[str, Any]
    depends_on: List[str] = Field(default_factory=list)

class ProposedDAG(BaseModel):
    trigger: str
    steps: List[WorkflowStep]

# Production configuration
ALLOWED_ACTIONS = [
    "update_record", "create_record", "delete_record", "send_email",
    "webhook_call", "wait_timer", "condition_check", "loop_iteration"
]
MAX_NODES = 50
MAX_DEPTH = 20

@app.post("/validate")
async def validate_dag(dag: ProposedDAG):
    # 1. Check node limit
    if len(dag.steps) > MAX_NODES:
        raise HTTPException(
            status_code=400,
            detail=f"Graph rejection: Node limit exceeded ({len(dag.steps)} > {MAX_NODES})"
        )

    # 2. Check allowed action types
    for step in dag.steps:
        if step.type not in ALLOWED_ACTIONS:
            raise HTTPException(
                status_code=400,
                detail=f"Security validation breach: Action variant unauthorized: {step.type}"
            )

    # 3. Topological sort and cycle detection
    graph = {step.id: step.depends_on for step in dag.steps}
    visited = set()
    rec_stack = set()

    def dfs(node, depth=0):
        if depth > MAX_DEPTH:
            return True  # Cycle or too deep
        if node in rec_stack:
            return True
        if node in visited:
            return False
        rec_stack.add(node)
        for neighbor in graph.get(node, []):
            if dfs(neighbor, depth + 1):
                return True
        rec_stack.remove(node)
        visited.add(node)
        return False

    for node in graph:
        if dfs(node):
            raise HTTPException(status_code=400, detail="Circular dependency detected or max depth exceeded")

    return {
        "status": "valid",
        "message": "DAG is acyclic and within limits",
        "node_count": len(dag.steps),
        "allowed_actions": ALLOWED_ACTIONS
    }

@app.get("/health")
async def health():
    return {"status": "healthy"}
