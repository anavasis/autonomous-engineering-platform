# AGENTS.md — Autonomous Engineering Platform

Mandatory instructions for every agent (human-directed or autonomous) working in this repository.

## Before any work

1. Read this file (`AGENTS.md`) in full.
2. Read the always-applied Cursor rule: `.cursor/rules/aep-context.mdc`.
3. Read agent context in order:
   - `docs/agent-context/PROJECT.md`
   - `docs/agent-context/STATE.md`
   - `docs/agent-context/DECISIONS.md`
4. Read architecture: `docs/architecture/AEP-ARCHITECTURE.md`.
5. Read `NOTICE.md` and `PROVENANCE.md` when provenance, CIP boundaries, or import policy may apply.
6. Confirm the **current approved task** in `STATE.md` before making changes.

**Do not** implement, scaffold runtime code, or expand scope until the approved task explicitly authorizes that work.

## Repository identity (non-negotiable)

- This is the **Autonomous Engineering Platform (AEP)** independent repository.
- Origin: **Option D (Greenfield Repository)**.
- **No historical P1 import.**
- **No StudyMentor source copy.**
- **No CIP source copy.** CIP is an **external target** only.
- Do **not** clone other repositories into this tree unless an approved task explicitly requires a read-only remote reference and still forbids source import.

## Inspection-first workflow

1. **Inspect** the approved task and current tree.
2. **Report** findings and proposed file impact against change control.
3. Wait for / respect approval gates documented in `STATE.md` and architecture.
4. **Implement** only what the approved task authorizes.
5. **Validate** against the allowed file / artifact policy for that task.

## Change-control policy

- Prefer the smallest change that satisfies the approved task.
- Do not create files outside the task’s allowed set.
- Do not introduce runtime code, PHP, Composer, GitHub Actions, tests, databases, migrations, WordPress code, or executors unless a later approved task explicitly authorizes them.
- Do not implement **ORCH-R1** until a task after **ORCH-R1 INSPECTION** authorizes implementation.
- If work would modify or create anything outside the approved scope: **STOP** and report instead of continuing.

## Secrets policy

- Never commit secrets, tokens, private keys, `.env` files with credentials, or CIP/WordPress credentials.
- Never embed production credentials in docs or examples.
- Use placeholders in documentation (e.g. `<REDACTED>`, `$AEP_TOKEN`).
- Treat any discovered secret as an incident: do not copy it into the repo; report it.

## WordPress Mission Control boundary

- WordPress may be referenced as a future Mission Control UI / approval surface.
- **Forbidden:** shell execution from WordPress.
- **Forbidden:** Git execution from WordPress.
- **Forbidden:** hosting executors inside WordPress.
- Mission Control must remain outside the execution path.

## CIP boundary

- CIP is a **protected external target**.
- Adapters may target CIP remotely in future approved work.
- Never import CIP source, history, or secrets into AEP.

## Future (authorized only by later releases)

Documented in architecture; not present in this bootstrap:

- Repository adapters
- Executor abstraction
- Validation pipeline

## Current next task

See `docs/agent-context/STATE.md`: **ORCH-R1 INSPECTION**.
