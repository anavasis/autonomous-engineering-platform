# Autonomous Engineering Platform (AEP)

Independent control-plane repository for **controlled engineering missions** against external target repositories.

This repository is a **greenfield** documentation baseline (Option D). It does **not** contain runtime code, executors, WordPress plugins, Composer packages, CI workflows, or imported source from any other project.

## Identity

| Attribute | Value |
| --- | --- |
| Product | Autonomous Engineering Platform (AEP) |
| Repository | `anavasis/autonomous-engineering-platform` |
| Origin decision | **Option D — Greenfield Repository** |
| Relationship to CIP | CIP is an **external target only** (protected references; no source import) |
| Relationship to P1 | **No historical P1 import** |
| Relationship to StudyMentor | **No StudyMentor source import** |

## What lives here (now)

Documentation and agent context only:

- Repository identity, provenance, and notices
- Agent operating instructions (`AGENTS.md`, Cursor rules, agent-context docs)
- Approved architecture and ORCH release roadmap

## What does **not** live here (now)

- Runtime application code
- PHP / WordPress / Composer
- GitHub Actions workflows
- Tests, databases, migrations
- Executors or ORCH-R1 implementation
- Imported P1, CIP, or StudyMentor source trees

## WordPress Mission Control boundary

WordPress may serve as a **Mission Control** surface (UI / status / human approval) in a future phase. Hard boundaries:

- **No shell execution** from WordPress
- **No Git execution** from WordPress
- Mission Control must not become an executor host

## Next approved engineering task

**ORCH-R1 INSPECTION** — inspect only; do not implement ORCH-R1 until a separate, approved change request authorizes implementation.

## Agent entrypoint

Every agent **must** read the repository context before any work. Start with [`AGENTS.md`](./AGENTS.md).
