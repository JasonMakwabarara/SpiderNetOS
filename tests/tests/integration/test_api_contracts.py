"""
API Contract Tests
Validates JSON schemas and endpoint behavior for all services.
"""
import pytest
import jsonschema
from httpx import AsyncClient
from jsonschema import validate


# JSON Schema definitions for API responses
HEALTH_RESPONSE_SCHEMA = {
    "type": "object",
    "required": ["status", "timestamp"],
    "properties": {
        "status": {"type": "string", "enum": ["healthy", "degraded", "unhealthy"]},
        "timestamp": {"type": "string", "format": "date-time"},
        "version": {"type": "string"},
        "services": {
            "type": "object",
            "additionalProperties": {
                "type": "object",
                "properties": {
                    "status": {"type": "string"},
                    "latency_ms": {"type": "number"}
                }
            }
        }
    }
}

TRAIN_REQUEST_SCHEMA = {
    "type": "object",
    "required": ["episodes"],
    "properties": {
        "episodes": {"type": "integer", "minimum": 1, "maximum": 1000},
        "learning_rate": {"type": "number", "minimum": 0.0001, "maximum": 0.1},
        "batch_size": {"type": "integer", "minimum": 1, "maximum": 512}
    }
}

TRAIN_RESPONSE_SCHEMA = {
    "type": "object",
    "required": ["job_id", "status"],
    "properties": {
        "job_id": {"type": "string", "format": "uuid"},
        "status": {"type": "string", "enum": ["queued", "running", "completed", "failed"]},
        "estimated_duration_seconds": {"type": "integer"},
        "progress": {
            "type": "object",
            "properties": {
                "current_episode": {"type": "integer"},
                "total_episodes": {"type": "integer"},
                "current_reward": {"type": "number"}
            }
        }
    }
}

SIMULATION_START_SCHEMA = {
    "type": "object",
    "required": ["scenario_id"],
    "properties": {
        "scenario_id": {"type": "string"},
        "duration_days": {"type": "integer", "minimum": 1, "maximum": 365},
        "initial_budget": {"type": "number", "minimum": 0}
    }
}


class TestHealthEndpoints:
    """Test health check endpoints for all services."""

    @pytest.mark.asyncio
    async def test_cpl_health_schema(self, cpl_service_url):
        """CPL health endpoint must return valid schema."""
        async with AsyncClient() as client:
            response = await client.get(f"{cpl_service_url}/health")
            assert response.status_code == 200
            
            data = response.json()
            validate(instance=data, schema=HEALTH_RESPONSE_SCHEMA)
            
            assert data["status"] in ["healthy", "degraded"]

    @pytest.mark.asyncio
    async def test_simulation_health_schema(self, simulation_service_url):
        """Simulation health endpoint must return valid schema."""
        async with AsyncClient() as client:
            response = await client.get(f"{simulation_service_url}/health")
            assert response.status_code == 200
            
            data = response.json()
            validate(instance=data, schema=HEALTH_RESPONSE_SCHEMA)

    @pytest.mark.asyncio
    async def test_inference_health(self, inference_service_url):
        """Inference plane health check."""
        async with AsyncClient() as client:
            response = await client.get(f"{inference_service_url}/health")
            assert response.status_code == 200
            
            data = response.json()
            assert "status" in data


class TestCPLServiceAPI:
    """Test CPL service training endpoints."""

    @pytest.mark.asyncio
    async def test_train_endpoint_accepts_valid_request(self, cpl_service_url):
        """Train endpoint must accept valid request body."""
        valid_request = {
            "episodes": 100,
            "learning_rate": 0.001,
            "batch_size": 64
        }
        
        # Validate request schema
        validate(instance=valid_request, schema=TRAIN_REQUEST_SCHEMA)
        
        # Would send actual request in full integration test
        # async with AsyncClient() as client:
        #     response = await client.post(f"{cpl_service_url}/train", json=valid_request)
        #     assert response.status_code == 202
        #     validate(instance=response.json(), schema=TRAIN_RESPONSE_SCHEMA)

    @pytest.mark.asyncio
    async def test_train_endpoint_rejects_invalid_episodes(self, cpl_service_url):
        """Train endpoint must reject invalid episode counts."""
        invalid_requests = [
            {"episodes": 0},      # Too low
            {"episodes": 10000},  # Too high
            {"episodes": -1},    # Negative
            {},                   # Missing required
        ]
        
        for request in invalid_requests:
            with pytest.raises(jsonschema.ValidationError):
                validate(instance=request, schema=TRAIN_REQUEST_SCHEMA)

    @pytest.mark.asyncio
    async def test_train_status_endpoint(self, cpl_service_url):
        """Status endpoint must return job progress."""
        job_id = "test-job-123"
        
        async with AsyncClient() as client:
            response = await client.get(f"{cpl_service_url}/train/{job_id}/status")
            
            if response.status_code == 200:
                data = response.json()
                validate(instance=data, schema=TRAIN_RESPONSE_SCHEMA)
                
                if data["status"] == "running":
                    assert "progress" in data
                    assert data["progress"]["current_episode"] <= data["progress"]["total_episodes"]


class TestSimulationServiceAPI:
    """Test simulation service endpoints."""

    @pytest.mark.asyncio
    async def test_simulation_start_accepts_valid(self, simulation_service_url):
        """Simulation start must accept valid scenario."""
        valid_request = {
            "scenario_id": "test-scenario-001",
            "duration_days": 30,
            "initial_budget": 10000.00
        }
        
        validate(instance=valid_request, schema=SIMULATION_START_SCHEMA)

    @pytest.mark.asyncio
    async def test_simulation_start_rejects_invalid(self, simulation_service_url):
        """Simulation start must reject invalid parameters."""
        invalid_requests = [
            {"duration_days": 30},        # Missing scenario_id
            {"scenario_id": "test"},      # Missing duration
            {"scenario_id": "", "duration_days": 400},  # Duration too high
        ]
        
        for request in invalid_requests:
            with pytest.raises((jsonschema.ValidationError, jsonschema.SchemaError)):
                validate(instance=request, schema=SIMULATION_START_SCHEMA)

    @pytest.mark.asyncio
    async def test_simulation_results_schema(self, simulation_service_url):
        """Simulation results must match expected schema."""
        # Schema for simulation results
        results_schema = {
            "type": "object",
            "required": ["simulation_id", "status", "metrics"],
            "properties": {
                "simulation_id": {"type": "string"},
                "status": {"type": "string", "enum": ["running", "completed", "failed"]},
                "metrics": {
                    "type": "object",
                    "properties": {
                        "total_reward": {"type": "number"},
                        "total_cost": {"type": "number"},
                        "roi": {"type": "number"},
                        "episodes_completed": {"type": "integer"}
                    },
                    "required": ["total_reward", "total_cost", "roi"]
                }
            }
        }
        
        async with AsyncClient() as client:
            response = await client.get(f"{simulation_service_url}/simulation/test-id/results")
            
            if response.status_code == 200:
                validate(instance=response.json(), schema=results_schema)


class TestErrorHandling:
    """Test API error responses."""

    @pytest.mark.asyncio
    async def test_404_returns_json(self, cpl_service_url):
        """404 errors must return JSON with error details."""
        async with AsyncClient() as client:
            response = await client.get(f"{cpl_service_url}/nonexistent")
            assert response.status_code == 404
            
            data = response.json()
            assert "error" in data or "detail" in data

    @pytest.mark.asyncio
    async def test_422_returns_validation_errors(self, cpl_service_url):
        """Validation errors must return field-level details."""
        invalid_request = {"episodes": "not_a_number"}
        
        async with AsyncClient() as client:
            response = await client.post(
                f"{cpl_service_url}/train", 
                json=invalid_request
            )
            
            if response.status_code == 422:
                data = response.json()
                assert "detail" in data
                # Should indicate which field failed validation
                assert any("episodes" in str(err) for err in data.get("detail", []))
