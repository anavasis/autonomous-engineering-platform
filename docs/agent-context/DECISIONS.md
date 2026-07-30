# DECISIONS.md — approved decisions

Record of decisions approved for the AEP documentation baseline. Append new decisions; do not silently rewrite history.

## D-001 — Independent AEP repository

- **Decision:** AEP lives in `anavasis/autonomous-engineering-platform` as an independent repository.
- **Status:** Approved
- **Consequences:** Sibling products are not merged into this tree. Cross-repo work uses remote references and future adapters.

## D-002 — Option D (Greenfield Repository)

- **Decision:** Bootstrap via **Option D — Greenfield Repository**.
- **Status:** Approved
- **Consequences:** Empty/docs-first start. No fork-based inheritance. Provenance is greenfield, not migrated history.

## D-003 — No historical P1 import

- **Decision:** Do not import historical P1 source, history, or runtime trees into AEP.
- **Status:** Approved
- **Consequences:** AEP concepts may be described in docs; P1 code must not appear as tracked source here.

## D-004 — Protected CIP references; CIP as external target

- **Decision:** CIP may be named only as a **protected external target**. CIP source must not be copied or imported.
- **Status:** Approved
- **Consequences:** Future repository adapters may integrate *with* CIP remotely. AEP must not become a CIP mirror.

## D-005 — No StudyMentor source copy

- **Decision:** Do not copy StudyMentor code into AEP.
- **Status:** Approved

## D-006 — No templates / no sibling clones for bootstrap

- **Decision:** Bootstrap without project templates and without cloning other repositories into this workspace for content.
- **Status:** Approved

## D-007 — Documentation baseline only at bootstrap

- **Decision:** `AEP-REPOSITORY-BOOTSTRAP-001` may create only the ten approved tracked documentation/context files.
- **Status:** Approved
- **Consequences:** No runtime code, PHP, Composer, GitHub Actions, tests, databases, migrations, WordPress code, or executors in the baseline.

## D-008 — Inspection-first workflow

- **Decision:** Engineering work proceeds inspect → report/approve → implement → validate.
- **Status:** Approved
- **Consequences:** Implementation without inspection is out of policy. Next ORCH step after bootstrap is **ORCH-R1 INSPECTION**, not ORCH-R1 implementation.

## D-009 — Strict change-control policy

- **Decision:** Agents may only create/modify artifacts authorized by the current approved task. Out-of-scope work must **STOP** and be reported.
- **Status:** Approved

## D-010 — Secrets policy

- **Decision:** Secrets, tokens, private keys, and environment credentials must never be committed. Docs use placeholders only.
- **Status:** Approved

## D-011 — WordPress Mission Control boundary

- **Decision:** WordPress may act as Mission Control (UI / status / human approval) in future approved work, with hard forbids:
  - no shell execution from WordPress
  - no Git execution from WordPress
  - WordPress is not an executor host
- **Status:** Approved

## D-012 — Approved architecture & ORCH roadmap

- **Decision:** Architecture and ORCH release roadmap in `docs/architecture/AEP-ARCHITECTURE.md` are the approved baseline for planning.
- **Status:** Approved
- **Consequences:** Future repository adapters, executor abstraction, and validation pipeline are planned capabilities—not present in bootstrap.

## D-013 — ORCH-R1 not implemented at bootstrap

- **Decision:** Bootstrap must not implement ORCH-R1. Next approved engineering task is **ORCH-R1 INSPECTION**.
- **Status:** Approved
