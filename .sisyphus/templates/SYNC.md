# Sync contract

These files are copies of `/var/www/paymenter/.sisyphus/templates/`.

Run:

```bash
bash .sisyphus/templates/ralph-loop-verify.sh --check-sync
```

Update only via a `dp-process-NN` plan that changes both copies in lockstep.

## Hashes

# Note: `ralph-loop-verify.sh` is hashed in its normalized `--check-sync` form,
# after removing the extension-only wrapper block. Do not use the raw on-disk
# file hash for that entry.
- ralph-loop-contract.md: 0f126b2594fc58d9e5961ab0e024fa4fa98238e877a496b119ea5e6d6ca48028
- ralph-loop-verify.sh: f37b6ed553def750a4743838bbcd65cdfe19b88b991da6ff7ea1caf5db05ebb9
