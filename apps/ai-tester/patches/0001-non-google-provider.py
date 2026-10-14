#!/usr/bin/env python3
"""Point the agent's history-chunking model at the configured provider.

Upstream builds this one model with `get_google_llm(...)` regardless of the run's
provider, so an OpenAI-compatible endpoint fails at the first chunk. The operator
identity is the same endpoint the rest of the run uses; only the constructor
changes.

Run as: python 0001-non-google-provider.py <checkout-dir>

Every anchor must match exactly once, so a change upstream fails the image build
rather than producing an image that silently talks to the wrong provider.
"""

import sys
from pathlib import Path

BEFORE = """    def _get_llm(self):
        if self._llm is None:
            from artemis.services.llm import get_google_llm

            self._llm = get_google_llm(model_name=self._model_name, temperature=0.0)
        return self._llm

    def _get_fallback_llm(self):
        if self._fallback_llm is None and self._fallback_model_name:
            from artemis.services.llm import get_google_llm

            self._fallback_llm = get_google_llm(
                model_name=self._fallback_model_name, temperature=0.0
            )
        return self._fallback_llm"""

AFTER = """    def _get_llm(self):
        if self._llm is None:
            from artemis.services.llm import get_llm

            self._llm = get_llm(self._ctx, name="operator", temperature=0.0)
        return self._llm

    def _get_fallback_llm(self):
        if self._fallback_llm is None and self._fallback_model_name:
            from artemis.services.llm import get_llm

            self._fallback_llm = get_llm(self._ctx, name="operator", temperature=0.0)
        return self._fallback_llm"""


def main() -> int:
    if len(sys.argv) != 2:
        print("usage: 0001-non-google-provider.py <checkout-dir>", file=sys.stderr)
        return 2

    target = Path(sys.argv[1]) / "artemis" / "memory" / "chunking.py"
    source = target.read_text(encoding="utf-8")

    if AFTER in source:
        print("already applied")
        return 0

    if source.count(BEFORE) != 1:
        print(f"anchor not found exactly once in {target}", file=sys.stderr)
        return 1

    target.write_text(source.replace(BEFORE, AFTER), encoding="utf-8")
    print(f"patched {target}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
