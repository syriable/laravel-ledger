# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability within this package, please report it
responsibly. Do **not** open a public issue.

Instead, email the maintainers directly. All security vulnerabilities will be
addressed promptly.

Because this is a financial package, please treat any of the following as
security-sensitive and report them privately:

- Any path that allows a financial model to be written outside the
  `TransactionRecorder`.
- Any way to record an imbalanced transaction.
- Any way to reverse a transaction more than once, or to mutate a recorded
  transaction or entry.
- Any way to bypass the idempotency reference and double-post.

Thank you for helping keep `laravel-ledger` and its users safe.
