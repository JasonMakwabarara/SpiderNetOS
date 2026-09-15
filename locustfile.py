from locust import HttpUser, task, between

class AgentUser(HttpUser):
    wait_time = between(1, 3)

    @task
    def run_agent(self):
        self.client.post("/api/agent/dispatch", json={
            "query": "What is the status of my deployment?"
        })
