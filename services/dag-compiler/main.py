from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from typing import Dict, Any
from compiler import DAGCompiler

app = FastAPI(title="SpiderNetOS DAG Compiler")
compiler = DAGCompiler(allowed_actions=["email", "slack", "webhook", "update_record", "create_task"])

class CompileRequest(BaseModel):
    dag: Dict[str, Any]

@app.post("/v2/compile")
async def compile_dag(req: CompileRequest):
    try:
        compiled = compiler.validate_and_compile(req.dag)
        return {"status": "valid", "dag": compiled.dict()}
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))

@app.get("/health")
async def health():
    return {"status": "healthy"}
