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
- ralph-loop-contract.md: 4ca8a12bf985857600ff0115fbceb3d07432448e4b2faa57a711a61167821413
- ralph-loop-verify.sh: 95e352175e1bfccd7495f6202cbb453844305ad38fa922e547e38ced4fb56518
