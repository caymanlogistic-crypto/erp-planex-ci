# Data safety

- Prefer preset synthetic data because it is deterministic, reviewable and safe.
- A snapshot must be an OWNER-approved local HTML export with no credentials, session tokens, personal data beyond what is necessary, or production secrets. The tool sanitizes known markers but does not replace human data minimization.
- Never configure a production DB DSN, production API token, VPN tunnel, write-capable proxy or remote form endpoint for DESIGN LAB.
- If realistic live behavior is required, build a local synthetic fixture or a minimal sanitized snapshot. Treat missing safe local data as a blocker, not permission to use production.
- Screenshots and checkpoints inherit the sensitivity of their visible data. Keep them in the task-local session and do not commit them unless the OWNER explicitly requires sanitized evidence.
