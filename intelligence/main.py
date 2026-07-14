from fastapi import FastAPI
app = FastAPI(title="SpiderNetOS Intelligence")
@app.get("/health")
async def health():
    return {"status": "intelligence healthy"}
@app.post("/process")
async def process(data: dict):
    return {"status": "processed", "input": data}
