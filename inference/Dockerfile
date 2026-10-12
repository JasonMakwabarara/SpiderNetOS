FROM python:3.11-slim

RUN apt-get update && apt-get install -y \
    gcc \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# POST /voice/agent runs the voice agent and its tool registry, and
# POST /ste/simulate runs the Monte Carlo simulator; all three live in
# intelligence/. Compose passes that folder as the named build context
# `intelligence`; a bare `docker build inference/` needs
# `--build-context intelligence=intelligence`.
COPY --from=intelligence agents/voice_agent.py ./agents/voice_agent.py
COPY --from=intelligence tools/ ./tools/
COPY --from=intelligence atlas/mc_simulator.py ./intelligence/atlas/mc_simulator.py

EXPOSE 9000

# Drop root. Everything above this line needs it (apt, pip); nothing below does.
# The service binds a port above 1024, so no capability is given up, and /app is
# handed over so uvicorn can read what was copied in.
RUN useradd --create-home --uid 10001 appuser && chown -R appuser:appuser /app
USER appuser

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "9000", "--reload"]
