# PROJECT.md — Autonomous Engineering Platform

## Mission

Build and operate an **Autonomous Engineering Platform (AEP)** that plans, inspects, and (in future approved releases) executes **controlled engineering missions** against **external** repositories—without absorbing those repositories’ source into AEP.

## Repository posture

| Topic | Decision |
| --- | --- |
| Independence | AEP is its own repository |
| Bootstrap | **Option D — Greenfield Repository** |
| Historical P1 | Not imported |
| StudyMentor | Not copied |
| CIP | External target only; protected references; no source import |
| Templates | Not used for bootstrap |

## Roles (logical)

| Role | Responsibility | Present in bootstrap? |
| --- | --- | --- |
| Documentation / agent context | Identity, decisions, architecture, state | **Yes** |
| Mission Control (WordPress) | Human-facing status / approval UI (future) | No — boundary documented only |
| Repository adapters | Talk to external targets (e.g. CIP) remotely (future) | No — planned |
| Executor abstraction | Run approved engineering actions outside WordPress (future) | No — planned |
| Validation pipeline | Verify mission outcomes before merge/release (future) | No — planned |

## WordPress Mission Control boundary

WordPress is **not** the execution engine.

- Allowed (future, when approved): presentation, status, human gates.
- Forbidden: shell execution from WordPress; Git execution from WordPress; embedding executors in WordPress.

## Secrets

Credentials for Git hosts, CIP, WordPress, or cloud providers must never be stored in this repository. Documentation uses placeholders only.

## Change control

All engineering work follows **inspection-first** workflow and the ORCH release roadmap in `docs/architecture/AEP-ARCHITECTURE.md`. Agents must not expand scope beyond the task recorded in `STATE.md`.

## Current phase

Documentation baseline complete after `AEP-REPOSITORY-BOOTSTRAP-001`. Next approved engineering task: **ORCH-R1 INSPECTION** (see `STATE.md`).
