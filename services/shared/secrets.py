"""
SpiderNetOS Secrets Loader
Unified interface for loading secrets from Docker Secrets or environment variables.

Security Rule: Docker secrets are preferred in production.
Environment variables are only for local development.
"""
import os
from typing import Optional


def load_secret(name: str, default: Optional[str] = None, required: bool = False) -> Optional[str]:
    """
    Load a secret from Docker secrets (production) or environment (development).

    Priority:
    1. /run/secrets/{name} (Docker secrets)
    2. Environment variable {name.upper()}
    3. default parameter (if provided and not required)
    4. Raise error if required=True

    Args:
        name: Secret name (lowercase, underscores)
        default: Default value if not found (only for non-required secrets)
        required: If True, raise error when secret not found

    Returns:
        Secret value or default

    Raises:
        RuntimeError: If required=True and secret not found
    """
    secret_path = f"/run/secrets/{name}"
    env_name = name.upper()

    # Try Docker secret first (production)
    if os.path.exists(secret_path):
        try:
            with open(secret_path, 'r') as f:
                return f.read().strip()
        except (IOError, PermissionError):
            pass  # Fall through to env var

    # Try environment variable (development fallback)
    env_value = os.getenv(env_name)
    if env_value is not None:
        return env_value

    # Use default if provided
    if default is not None:
        return default

    # Fail if required
    if required:
        raise RuntimeError(
            f"Required secret '{name}' not found. "
            f"Provide it via Docker secret at {secret_path} "
            f"or environment variable {env_name}"
        )

    return None


def load_required_secret(name: str) -> str:
    """Load a required secret. Raises error if not found."""
    value = load_secret(name, required=True)
    if value is None:
        raise RuntimeError(f"Required secret '{name}' is None")
    return value


# Convenience functions for common secrets
def db_password() -> str:
    """Load database password."""
    return load_required_secret('db_password')


def db_repl_password() -> str:
    """Load database replication password."""
    return load_required_secret('db_repl_password')


def db_encryption_key() -> str:
    """Load database encryption key."""
    return load_required_secret('db_encryption_key')


def redis_password() -> str:
    """Load Redis password."""
    return load_required_secret('redis_password')


def pusher_app_secret() -> str:
    """Load Pusher app secret."""
    return load_required_secret('pusher_app_secret')


def openai_api_key() -> Optional[str]:
    """Load OpenAI API key (optional)."""
    return load_secret('openai_api_key')
