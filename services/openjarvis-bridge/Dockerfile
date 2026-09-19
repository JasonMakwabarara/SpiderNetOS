FROM python:3.11-slim
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt --index-url https://pypi.org/simple --trusted-host pypi.org --trusted-host files.pythonhosted.org
COPY . .
# Drop root. Everything above this line needs it (apt, pip); nothing below does.
# The service binds a port above 1024, so no capability is given up, and /app is
# handed over so uvicorn can read what was copied in.
RUN useradd --create-home --uid 10001 appuser && chown -R appuser:appuser /app
USER appuser

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]
