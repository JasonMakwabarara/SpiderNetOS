"""
SpiderNetOS Load Testing Suite
Tests API endpoints under concurrent user load.
Run with: locust -f tests/load/locustfile.py --host=http://localhost:8000
"""
from locust import HttpUser, task, between
import random
import json


class SpiderNetUser(HttpUser):
    """
    Simulated user interacting with SpiderNetOS API.
    Weight: 70% training workload, 30% read operations.
    """
    
    wait_time = between(1, 5)  # Realistic think time
    weight = 1
    
    def on_start(self):
        """Login or initialize session before tasks."""
        # Would authenticate here in real scenario
        self.client.headers["Content-Type"] = "application/json"
    
    @task(3)
    def train_endpoint(self):
        """Simulate RL training job submission."""
        payload = {
            "episodes": random.randint(10, 100),
            "learning_rate": round(random.uniform(0.0001, 0.01), 4),
            "batch_size": random.choice([32, 64, 128])
        }
        
        with self.client.post("/train", json=payload, catch_response=True) as response:
            if response.status_code == 202:
                response.success()
                # Extract job_id for subsequent polling
                if "job_id" in response.json():
                    self.job_id = response.json()["job_id"]
            elif response.status_code == 429:
                response.failure("Rate limited")
            else:
                response.failure(f"Unexpected status: {response.status_code}")
    
    @task(2)
    def simulation_endpoint(self):
        """Simulate simulation scenario execution."""
        payload = {
            "scenario_id": f"load-test-{random.randint(1, 1000)}",
            "duration_days": random.randint(7, 30),
            "initial_budget": random.randint(1000, 50000)
        }
        
        with self.client.post("/simulation/start", json=payload, catch_response=True) as response:
            if response.status_code in [200, 202]:
                response.success()
            else:
                response.failure(f"Simulation failed: {response.status_code}")
    
    @task(1)
    def inference_endpoint(self):
        """Simulate inference/prediction requests."""
        payload = {
            "context": "test query for inference",
            "max_tokens": random.randint(50, 500)
        }
        
        with self.client.post("/inference", json=payload, catch_response=True) as response:
            if response.status_code == 200:
                response.success()
            else:
                response.failure(f"Inference failed: {response.status_code}")
    
    @task(1)
    def check_training_status(self):
        """Poll training job status."""
        if hasattr(self, 'job_id'):
            with self.client.get(f"/train/{self.job_id}/status", catch_response=True) as response:
                if response.status_code == 200:
                    response.success()
                else:
                    response.failure(f"Status check failed: {response.status_code}")
    
    @task(1)
    def health_check(self):
        """Check service health."""
        with self.client.get("/health", catch_response=True) as response:
            if response.status_code == 200:
                data = response.json()
                if data.get("status") == "healthy":
                    response.success()
                else:
                    response.failure(f"Service degraded: {data.get('status')}")
            else:
                response.failure(f"Health check failed: {response.status_code}")


class AdminUser(HttpUser):
    """
    Admin user performing heavier operations.
    Weight: 10% of total load.
    """
    
    wait_time = between(5, 15)
    weight = 0.1
    
    @task(1)
    def get_metrics(self):
        """Fetch system metrics."""
        with self.client.get("/admin/metrics", catch_response=True) as response:
            if response.status_code == 200:
                response.success()
            else:
                response.failure(f"Metrics fetch failed: {response.status_code}")
    
    @task(1)
    def list_active_simulations(self):
        """List all running simulations."""
        with self.client.get("/admin/simulations", catch_response=True) as response:
            if response.status_code == 200:
                response.success()
            else:
                response.failure(f"Simulations list failed: {response.status_code}")


class StressTestUser(HttpUser):
    """
    High-intensity stress testing user.
    Use with: --tags stress
    """
    
    wait_time = between(0.1, 0.5)  # Minimal think time
    weight = 0  # Disabled by default, enable with tags
    
    @task(10)
    def rapid_health_checks(self):
        """Rapid health check spam to test rate limiting."""
        self.client.get("/health")
    
    @task(5)
    def burst_training(self):
        """Burst of training requests."""
        payload = {"episodes": 10}
        self.client.post("/train", json=payload)
