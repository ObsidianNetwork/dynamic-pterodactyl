# CLAUDE.md

Quick reference for implementing this extension. Read this first.

## Project Context

Paymenter extension enabling dynamic resource sliders (RAM, CPU, Disk) for Pterodactyl game server products with real-time pricing and availability.

**Pattern**: Companion Extension — enhances the built-in Pterodactyl extension without reimplementing server provisioning.

## Key Decisions (Already Settled)

These were debated and decided. Don't re-litigate without checking DECISIONS.md:

- ✅ Real-time Pterodactyl API — NO caching
- ✅ 15-minute reservation TTL with pessimistic locking
- ✅ Three pricing models only: linear, tiered, base+addon (now in Paymenter core)
- ✅ Filament for admin UI (matches Paymenter)
- ✅ Native `dynamic_slider` config option type for frontend (replaced noUiSlider)
- ✅ Best-fit node selection with weighted scoring (50% mem, 35% disk, 15% cpu)
- ✅ Extension focuses on reservations/availability only (pricing moved to core)

## Conventions

### Database
- Table prefix: `ptero_*`
- Money: `decimal(10,2)`
- Memory/Disk: stored in **MB** (display as GB in UI)
- CPU: stored as **percentage** (100 = 1 core, 400 = 4 cores)
- JSON columns use `snake_case` keys

### Code
- Services: one class per concern, in `Services/`
- Models: in `Models/`, match table name without prefix
- Controllers: thin, delegate to services
- Filament: Pages for dashboards, Resources for CRUD

### Naming
```
PricingConfig          (model)
ptero_pricing_configs  (table)
PricingConfigResource  (Filament resource)
```

## Documentation Map

| Need to... | Read |
|------------|------|
| Understand tables/models | 01-DATABASE.md |
| Implement business logic | 02-SERVICES.md |
| Build API endpoints | 03-API.md |
| Hook into Paymenter events | 04-EVENTS.md |
| Build admin interface | 05-ADMIN-UI.md |
| Build customer sliders | 06-FRONTEND.md |
| Understand pricing math | 07-PRICING-MODELS.md |
| Understand node selection | 08-ALGORITHMS.md |
| See roadmap/phases | 09-IMPLEMENTATION.md |

## Extension Location

```
extensions/Others/DynamicPterodactyl/
```

## Useful Commands

```bash
# Database
php artisan migrate
php artisan migrate:rollback --step=4

# Cache (clear after config changes)
php artisan cache:clear
php artisan config:clear
php artisan view:clear

# Scheduler (runs cleanup + alerts)
php artisan schedule:work

# Manual testing
php artisan tinker
>>> app(ResourceCalculationService::class)->testConnection()
```

## Paymenter Patterns to Follow

Before implementing, check how the built-in Pterodactyl extension does things:
```
app/Extensions/Servers/Pterodactyl/
```

Key files to reference:
- `Pterodactyl.php` — main extension class structure
- How it registers routes
- How it uses `ExtensionHelper::getConfig()`

## When Implementing

1. Check PROGRESS.md for current state
2. Read relevant doc section
3. Follow existing Paymenter patterns
4. Update PROGRESS.md when done
5. Note any decisions/blockers encountered

## Common Gotchas

- Paymenter uses Filament v4, not v3
- CSRF token required for API POST requests from frontend
- Cart item `properties` is JSON — merge, don't replace
- Pterodactyl API rate limit: 240/min (we use ~10)

---

## Context Compaction Protocol

**When nearing context limits, BEFORE compacting:**

1. **Update PROGRESS.md immediately** with:
   - Current task and exact stopping point
   - Any uncommitted decisions
   - What's working, what's broken
   - Next specific action to take

2. **If debugging**, add to PROGRESS.md:
   - The error/problem
   - What you've tried
   - Current hypothesis

3. **If mid-implementation**, note in PROGRESS.md:
   - Files created/modified this session
   - What's complete vs. partial
   - Any temporary code or TODOs added

4. **Flush any new decisions** to DECISIONS.md

5. **Tell the user** you're about to compact so they can save context if needed

### Quick Checkpoint Template

Copy this to PROGRESS.md "Current Session State" when checkpointing:

```markdown
### Checkpoint [timestamp]
**Working on**: [specific task]
**Status**: [working|stuck|almost done]
**Current file**: [path]
**Last completed**: [what]
**Next action**: [specific next step]
**Blockers**: [any issues]
**Notes**: [anything else important]
```

## Test Isolation Mandate (dp-13)
Extension phpunit MUST run with `DB_DATABASE=paymenter_test`. The phpunit.xml `<php>` block enforces this; tests/bootstrap.php aborts if violated. See DECISIONS.md for rationale.


## CodeRabbit Review Mandate (dp-process-audit, 2026-04-24; v2 2026-04-24)

Every PR against `dynamic-slider` (and any parent-repo branch) MUST follow `.sisyphus/templates/ralph-loop-contract.md`. This is non-negotiable after the 2026-04-24 incident where two consecutive PRs (#10, #11) merged within minutes of opening, zero CodeRabbit reviews submitted.

**`.coderabbit.yaml` deployed (v2, 2026-04-24)**

Both repos now carry a `.coderabbit.yaml` on their default branch:
- `Jordanmuss99/dynamic-pterodactyl` — auto-review on `dp-.*` and `dynamic-slider.*` branches; auto-pause after 10 commits; `request_changes_workflow: true`; `fail_commit_status: true`
- `ObsidianNetwork/Paymenter-Obsidian-Network` — auto-review on `dynamic-slider.*`; same settings

CodeRabbit reviews automatically on push. No manual `@coderabbitai review` mention needed unless review is absent after ~2 minutes.

**Required pre-PR local gate**: before `gh pr create`, run:

```bash
cr review --plain --type committed --base dynamic-slider
```

If the working branch is a `dp-*` branch that targets `dynamic-slider`, this local CLI pass is mandatory.

**Pre-merge gate** (mechanical, run immediately before `gh pr merge`):

```bash
bash .sisyphus/templates/ralph-loop-verify.sh \
  <PR_NUMBER> \
  --repo <owner/repo> \
  --expected-base <base-branch-regex>
```

Example for extension PR:

```bash
bash .sisyphus/templates/ralph-loop-verify.sh \
  <PR_NUMBER> \
  --repo Jordanmuss99/dynamic-pterodactyl \
  --expected-base '^dynamic-slider$'
```

Exit 0 = safe to merge. Non-zero = DO NOT merge. Do not bypass the script.
Use `--allow-actionable --reason "..."`, `--allow-direct-default --reason "..."`, or `--skip-quiet-period --reason "..."` only with explicit driver approval; reasons are audit-logged to `.sisyphus/notepads/ralph-loop-waivers.jsonl`.

**After APPROVED**: do not rush. Wait the Rule 8 quiet period before merge. Preferred command:

```bash
bash .sisyphus/templates/ralph-loop-verify.sh \
  <PR_NUMBER> \
  --repo Jordanmuss99/dynamic-pterodactyl \
  --expected-base '^dynamic-slider$' \
  --wait
```

**Template drift check**: before editing local contract/verifier files, or after syncing from outer Paymenter, run:

```bash
bash .sisyphus/templates/ralph-loop-verify.sh --check-sync
```

**PR author identity** (what CodeRabbit actually checks for entitlement):

CodeRabbit evaluates the GitHub login on the PR itself (whoever ran `gh pr create` / clicked "Open pull request"). The fork is Pro-entitled under `Jordanmuss99`. The 2026-04-24 incident root cause: PRs #10 and #11 were opened while `gh` was active as `ImStillBlue` (a different account logged in on this host), so CodeRabbit saw them as Free-tier and skipped review. PR #9 opened as `Jordanmuss99` received 3 reviews with the exact same commit authors.

Before `gh pr create` you MUST:

```bash
gh auth switch -u Jordanmuss99
login=$(gh api /user --jq .login)
[ "$login" = "Jordanmuss99" ] || { echo "ABORT: active gh user is $login"; exit 1; }
```

PR author on GitHub is immutable. If you open as the wrong user, close the PR and reopen — there is no way to reassign.

**Commit author email** (secondary — belt-and-braces):

```bash
git config user.name "Jordanmuss99"
git config user.email "164892154+Jordanmuss99@users.noreply.github.com"
```

`164892154+Jordanmuss99@users.noreply.github.com` is the default safe form — it avoids the GH007 push-rejection that blocks `jordanmuss@hotmail.com` (hotmail is marked private on the GitHub account). Use the hotmail address only if the Jordanmuss99 GitHub account's email-privacy setting is changed to allow public email pushes.

**Orchestrator rule**: when a subagent claims a PR is opened or merged, the driver MUST independently run:

```bash
gh pr view <N> --repo <owner/repo> --json author,createdAt,mergedAt,statusCheckRollup \
  --jq '{author: .author.login, createdAt, mergedAt, cr_status: ([.statusCheckRollup[] | select(.name=="CodeRabbit")] | first | .state)}'
```

If `author != "Jordanmuss99"`, OR `mergedAt - createdAt < 3 minutes`, OR `cr_status != "SUCCESS"`, treat as a contract violation. See `.sisyphus/notepads/dp-process-audit/incident-2026-04-24.md` for remediation.

## Enforceable rules (CodeRabbit reads these)

- FAIL when: Pterodactyl API responses are cached. Rationale: real-time queries are a settled decision (DECISIONS.md). Rate budget is ~10/min against the 240/min panel limit.
- FAIL when: pricing logic is added to this extension's admin interface or services. Rationale: pricing moved to Paymenter core per DECISIONS.md. The `ptero_pricing_configs` table was dropped in migration `2025_01_01_000005`.
- FAIL when: server provisioning is reimplemented here (createServer, suspendServer, terminateServer). Rationale: companion extension — delegate to `extensions/Servers/Pterodactyl/`.
- FAIL when: changes to this extension are committed from the outer Paymenter repo working tree. Rationale: this directory has its own `.git/`. Use `cd extensions/Others/DynamicPterodactyl && git commit`.
