"""Make ``intelligence.services.business_launch`` importable however pytest is invoked.

The repository root also has a regular ``services`` package, which would
shadow ``intelligence/services`` if these tests imported ``services.*``; they
import through the ``intelligence`` package instead, so the repository root
must be on ``sys.path``. The service modules use relative imports, so the
container import path (``services.business_launch``) keeps working. Everything
in these tests is offline.
"""

from __future__ import annotations

import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[3]
if str(REPO_ROOT) not in sys.path:
    sys.path.insert(0, str(REPO_ROOT))
