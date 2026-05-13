# AGENTS.md — TrustedLogin Client SDK

> Customer-side WordPress library. Integrated by plugin/theme authors to let end users grant time-limited support access. Pairs with the `trustedlogin-connector` vendor plugin and the TrustedLogin SaaS at `app.trustedlogin.com`.

**Namespace:** `TrustedLogin\` (PSR-4 → `src/`)
**PHP:** 5.3+ (with sodium_compat for older WordPress)
**WordPress:** 5.2+ (4.1+ with sodium_compat)
**Full project context:** `.claude/CLAUDE.md`

## Branching (Git Flow)

This repo uses Git Flow. Two long-lived branches:

- `main` — released code only. Every commit on `main` corresponds to a tagged release (`v1.10.0`, `v1.9.0`, …). Never commit directly; never open feature PRs against it.
- `develop` — integration branch. All ongoing work merges here.

Short-lived branches:

- `feature/<issue>-<slug>` — new features. Branch from `develop`, PR back into `develop`.
- `fix/<slug>`, `chore/<slug>`, `refactor/<slug>`, `test/<slug>`, `docs/<slug>`, `build/<slug>` — non-feature work. Same flow: branch from `develop`, PR back into `develop`.
- `release/<x.y.z>` — release prep (version bump, changelog finalization). Branch from `develop`, PR into `main`, tag `v<x.y.z>` on the merge commit, then fast-forward `develop` to match `main`.
- `hotfix/<x.y.z>` — urgent fix against a released version. Branch from `main`, PR into `main`, tag, then merge back into `develop`.

**Practical rules:**

- Default PR target is `develop`. Targeting `main` is reserved for `release/*` and `hotfix/*` only.
- After a release lands on `main` and is tagged, fast-forward-push `origin/main` → `origin/develop` (`git push origin origin/main:develop`) so the two branches converge.
- The `Tests` / `PHP CI Tests` / `E2E` workflows run on PRs to both `main` and `develop`, so feature work is gated identically regardless of target.

## Security Commit & Comment Hygiene

Git history, code comments, and PR descriptions are **public** — they ship in the plugin zip, land in `git log`, and get indexed by search engines. Treat them as attacker-readable.

When landing a security fix, the commit message, comments, and PR body must describe the **new shape** of the code — never the vulnerable shape that preceded it. Specifically:

- **Don't describe the pre-fix vulnerability.** No "previously allowed X", "the old code accepted Y", "scheme-loose check", "HTTP was mistakenly trusted". If a reader can reconstruct the bug from the commit alone, you've leaked it.
- **Don't name exploit chains.** No "MITM → inject granted message", "confused-deputy → key harvest", "CSRF bypasses nonce". These are stepping stones for anyone doing patch-diff analysis on released versions.
- **Don't cite audit finding IDs.** No "fixes audit P3 #1", "CodeRabbit finding B2", "see internal audit". Audit artifacts belong in private notes, not commit messages. They give searchers a handle to pivot from.
- **Don't quote attacker capability.** No "an attacker who controls the parent origin could…", "a compromised proxy could inject…". Attacker capability descriptions are exploit recipes.
- **Don't narrate pre-fix state in code comments either.** Comments ship with the code — a `// Previously we did X, which allowed Y` comment is a permanent exploit recipe for every future release.

**What to write instead:** factual description of what the code now does. Type: `refactor(...)` or `fix(...)` — not `security(...)`. Describe the current shape as if you were writing it from scratch.

Examples of the rewrite:

| Avoid | Prefer |
|---|---|
| `security(envelope): validate signature before decrypting — decryption was previously run on untrusted bytes.` | `refactor(envelope): signature verification runs before decryption. Envelopes without a valid signature are rejected.` |
| `security(endpoint): sanitize $_GET['identifier'] — was echoed raw into the login form.` | `fix(endpoint): identifier is sanitized with sanitize_text_field() before rendering.` |
| `security(role): add current_user_can() check — previously any logged-in user could revoke support access.` | `refactor(support-user): revoke flow requires the manage_options capability.` |
| `// OLD: trusted the client-supplied nonce without verification` | *(delete the comment; let the code speak for itself)* |
| `// Fixes CVE-YYYY-NNNN vulnerability from v1.2.x` | *(delete the comment; reference the CVE in private notes)* |

**Technical detail for private audit notes.** When a fix is non-trivial and you need to record the reasoning, write it in `SECURITY.md` entries kept in a private repo, in internal audit notes, or in the CVE disclosure itself — never in the public commit or the source tree.

**When in doubt, squash.** If a commit was written with exploit detail and you catch it before push, use `git commit --fixup=amend:<sha>` with a sanitized message and autosquash before the branch goes up. For already-pushed public history, coordinate with the team before force-push — some forks may already be tracking.

### Internal-process references

Code comments, docblocks, and commit messages are **public artifacts**. They must read as standalone documentation of what the code does today — never as a journal of how it got there.

**Don't reference internal plans, specs, tickets, or review processes:**

- No "Plan A / Plan B / Plan C" or any other internal codename for a feature delivery.
- No "spec:" / "design doc:" / "see docs/superpowers/..." path pointers. The code is the source of truth — if a comment needs an off-tree document to make sense, the comment is wrong.
- No CodeRabbit / Mockery / "review found" attributions. Apply the fix, write the comment from the perspective of the code's current behavior.
- No JIRA / Linear / GitHub-issue numbers in code (`# fixes ABC-123`). Belong in PR descriptions, not source.
- No "TODO(<initiative>-followup)" tags that reference internal Initiative names. Plain `TODO:` is fine when you describe the gap; an initiative name only the team recognises is noise.
- No "future SaaS revision will…" or "when X lands we'll switch on…" speculation. If you can't make the change today, file a ticket; don't seed a code comment that will rot into a stale promise.

**What to write instead:** factual description of *what the code does now*, with the trigger that made it non-obvious. "We use generic message X here so an attacker can't distinguish failure modes" is good — it stands on its own. "Per Plan B's spec section, …" is bad — the reader has no way to verify or even find the spec.

| Avoid | Prefer |
|---|---|
| `// Plan A always returns client_ip_redacted; presenter passes through.` | `// Upstream returns the redacted IP only; presenter passes through.` |
| `// Spec: docs/superpowers/specs/2026-04-27-foo.md` | *(delete; let the code stand)* |
| `// TODO(planB-followup): test-harness fix needed` | `// TODO: form posts from about:blank end up at wp-login.php; need a different submit path.` |
| `// When SaaS adds the admin-scoped IP, switch on $is_admin here.` | *(delete; if it's not feasible today, don't write speculative scaffolding into source)* |

If the comment needs the reader to know about an internal artifact, the comment is paying down debt for a different artifact — write the documentation in the right place (the PR description, an internal `docs/`, the audit log) and let the code stand on its own.

### Why this matters for the client SDK specifically

The client SDK is vendored into every customer's plugin zip — often thousands of sites per integrator. When a security fix lands in `main` and a release tag follows, patch-diff attackers will compare the tagged release to the previous version. A descriptive commit message shortcuts their reconnaissance. Unpatched downstream sites become targets the moment the commit is public.

Sensitive areas in this codebase where hygiene matters most:

- `src/Encryption.php`, `src/Envelope.php` — sodium crypto, signature verification, key handling
- `src/Endpoint.php`, `src/Ajax.php` — request surface, nonce/capability checks
- `src/SiteAccess.php`, `src/Remote.php` — SaaS envelope exchange, auth tokens
- `src/SupportUser.php`, `src/SupportRole.php` — privilege boundary, capability grants
- `src/Form.php` — user-facing rendering, escaping

## Comment Discipline

Default to writing no comments. A well-named identifier and the code that follows it should carry the meaning. Add a comment only when the *why* is non-obvious to a future reader.

**Don't write meta-narrative.** Comments that explain the developer's reasoning — "Defense in depth:", "Belt-and-suspenders:", "Cheaper than the alternative because…", "We do this instead of X because Y" — are narrative *about* the code, not part of it. PR descriptions and commit messages are the right home for that voice. Comments that survive in source become stale signal that rots faster than the code does.

**Don't restate the obvious.** A comment immediately above `update_user_meta(...)` saying "Update the user meta" wastes everyone's time. If the call doesn't read clearly, fix the names — don't paper over with prose.

**Don't reference the task, PR, or audit that prompted the code.** No "added for issue #66", "per review feedback", "this was the fix in PR #142". Those handles rot the moment the PR is closed. The thing the comment cared about is in `git blame` and in the PR body — leave it there.

**Don't narrate version-target trade-offs at the call site.** A comment like "we duplicate the cleanup here because the SDK targets PHP 5.3 (no `finally` until 5.5)" is meta narrative about *why the file looks this way*. A reader can see it looks that way; the *why* belongs in this file, not at every call site.

**Do keep comments** that describe a hidden constraint, a subtle invariant, a workaround for a specific bug, or a surprising behavior — things a reader would otherwise have to discover the hard way. These earn their place.

### PHP version target

The SDK runtime supports **PHP 5.3+** (per `composer.json` and `.phpcs.xml.dist`'s `testVersion`). New code must run on 5.3 — no `\Throwable`, no `finally`, no `static function () {}`, no `??` null coalesce, no return-type hints, no arrow functions. Build/test environments are PHP 7.4+ (PHPStan `phpVersion: 70400`, PHPUnit on 8.2), but anything the SDK actually ships has to clear the 5.3 bar.
