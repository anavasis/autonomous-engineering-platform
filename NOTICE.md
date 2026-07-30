# NOTICE

## Autonomous Engineering Platform (AEP)

This repository is the independent home of the **Autonomous Engineering Platform (AEP)** documentation baseline.

### Independence

- AEP is an **independent repository**.
- Bootstrap strategy: **Option D (Greenfield Repository)**.
- This tree was **not** created by importing historical P1 source.
- This tree was **not** created by copying StudyMentor source.
- This tree was **not** created by copying CIP source.
- No external repository was cloned into this workspace as part of bootstrap.

### Protected CIP references

**CIP** (when named in AEP documents) is treated strictly as an **external target repository**.

- CIP source must not be imported into AEP.
- CIP credentials and secrets must not be stored in this repository.
- References to CIP are protected: descriptive and boundary-oriented only.
- Future repository adapters may talk *to* CIP; they must not absorb CIP into AEP.

### WordPress Mission Control

Any future WordPress Mission Control integration is a **control / presentation boundary** only:

- No shell execution from WordPress
- No Git execution from WordPress
- No executor hosting inside WordPress

### Change control

Only explicitly approved engineering tasks may add tracked files beyond the documentation baseline. Inspection precedes implementation.
