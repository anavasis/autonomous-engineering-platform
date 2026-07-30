# AEP Architecture (approved baseline)

**Document:** `AEP-ARCHITECTURE.md`  
**Repository:** `anavasis/autonomous-engineering-platform`  
**Status:** Approved documentation baseline (Option D greenfield)  
**Runtime:** Not present in this repository yet

## 1. Purpose

The Autonomous Engineering Platform (AEP) coordinates **controlled engineering missions** against **external** repositories. AEP is the independent control and (future) orchestration home. External products such as **CIP** remain separate targets.

## 2. System context

```text
┌─────────────────────────────────────────────────────────┐
│  AEP (this repository)                                  │
│  - Agent context & architecture (now)                   │
│  - Future: adapters, executor abstraction, validation   │
└───────────────┬─────────────────────────────┬───────────┘
                │                             │
                │ (future remote I/O)         │ (future UI/approvals)
                ▼                             ▼
     ┌──────────────────┐          ┌────────────────────────┐
     │ External targets │          │ WordPress Mission      │
     │ e.g. CIP         │          │ Control (boundary only)│
     │ (no source import│          │ NO shell / NO Git exec │
     │  into AEP)       │          │ NOT an executor host   │
     └──────────────────┘          └────────────────────────┘
```

### 2.1 Independence

- AEP is an **independent repository**.
- Bootstrap strategy: **Option D (Greenfield Repository)**.
- **No historical P1 import.**
- **No StudyMentor or CIP source import.**

### 2.2 CIP

CIP is referenced only as a **protected external target**. Adapters (future) may operate against CIP remotely. AEP must not vendor CIP.

## 3. Logical components (approved direction)

| Component | Role | Bootstrap status |
| --- | --- | --- |
| Agent context & docs | Identity, state, decisions, architecture | **Present** |
| WordPress Mission Control | Human status / approval UI | Boundary only; not implemented here |
| Repository adapters | Authenticated remote operations on external repos | **Future** |
| Executor abstraction | Run approved engineering actions outside WordPress | **Future** |
| Validation pipeline | Verify mission results before promotion | **Future** |

### 3.1 WordPress Mission Control boundary

Mission Control is a **presentation and approval** surface:

- **Must not** execute shell commands.
- **Must not** execute Git.
- **Must not** host executors.

Execution belongs to future executor abstraction outside WordPress.

### 3.2 Repository adapters (future)

Adapters isolate provider/repo-specific APIs so AEP can target CIP and other remotes without importing their trees. Adapters are outbound integrations, not content mirrors.

### 3.3 Executor abstraction (future)

Executors perform approved actions in controlled environments. They are distinct from Mission Control and from documentation. **ORCH-R1 must not be implemented** until after **ORCH-R1 INSPECTION** and an explicit implementation approval.

### 3.4 Validation pipeline (future)

Validation gates mission outcomes (policy checks, review evidence, non-secret hygiene) before merge or release actions. Details are deferred past inspection.

## 4. Control policies (architecture-level)

### 4.1 Inspection-first workflow

1. Inspect approved task and repository state.
2. Report impact and risks.
3. Proceed only within authorized scope.
4. Implement only when the task authorizes implementation.
5. Validate against the task’s acceptance rules.

### 4.2 Strict change-control policy

- Tracked artifacts must match the approved task allow-list.
- Out-of-scope creation/modification → **STOP** and report.
- Bootstrap allow-list is exactly the ten documentation baseline files (see `PROVENANCE.md`).

### 4.3 Secrets policy

- No credentials in git.
- No CIP/WordPress/host tokens in docs except placeholders.
- `.gitignore` excludes common secret and runtime paths.

## 5. ORCH release roadmap (approved)

| Release / gate | Intent | Implementation in this repo? |
| --- | --- | --- |
| **AEP-REPOSITORY-BOOTSTRAP-001** | Documentation & agent-context baseline | Yes (docs only) |
| **ORCH-R1 INSPECTION** | Inspect ORCH-R1 readiness and constraints | Next approved task (**inspection only**) |
| **ORCH-R1** (implementation) | First orchestration increment — only after inspection + explicit approval | **Not authorized at bootstrap** |
| **Later ORCH releases** | Repository adapters, executor abstraction, validation pipeline increments | Future — gated by inspection and change control |

### 5.1 ORCH-R1 gate

- **Now:** Next approved engineering task = **ORCH-R1 INSPECTION**.
- **Forbidden now:** Implementing ORCH-R1, shipping executors, adding PHP/WordPress/Composer/workflows/tests/DBs/migrations, or importing external product source.

## 6. Non-goals (bootstrap)

- Runtime application code
- PHP, Composer, WordPress plugins/themes
- GitHub Actions
- Automated test suites
- Database files or migrations
- Executors
- Cloning or templating from other repositories

## 7. Evolution

When a later approved task authorizes runtime work, update `docs/agent-context/STATE.md` and append decisions to `DECISIONS.md` before expanding the tree. Architecture changes require explicit approval—do not silently diverge from this baseline.
