"""Deterministic checks for AO Quill, run on the AO server as `type: deterministic`
agent providers. No model is involved, and the worker calls them as fixed pipeline
stages, so they cannot be skipped.

Entry points (module:callable, called by AO with text, context, mcp_tool_results_or_handles):
- aoq_ao_checks.quotes:verify_quotes
- aoq_ao_checks.textchecks:text_checks
"""

__version__ = "0.1.0"
