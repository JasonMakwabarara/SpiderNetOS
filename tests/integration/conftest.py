"""Container fixtures now live in tests/conftest.py.

They were here, which meant importing testcontainers at module scope: with
the package absent, collecting the whole tree failed outright, and a test in
tests/behavioral that asked for kafka_container errored rather than skipping.
The root conftest declares them with importorskip instead.
"""
