"""Make the inference plane modules importable as top-level modules."""
import os
import sys

_INFERENCE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _INFERENCE_DIR not in sys.path:
    sys.path.insert(0, _INFERENCE_DIR)
