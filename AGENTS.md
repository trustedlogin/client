# AGENTS.md — TrustedLogin Client SDK

> Customer-side WordPress library. Integrated by plugin/theme authors to let end users grant time-limited support access. Pairs with the `trustedlogin-connector` vendor plugin and the TrustedLogin SaaS at `app.trustedlogin.com`.

**Namespace:** `TrustedLogin\` (PSR-4 → `src/`)
**PHP:** 5.3+ (with sodium_compat for older WordPress)
**WordPress:** 5.2+ (4.1+ with sodium_compat)
**Full project context:** `.claude/CLAUDE.md`

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

### Why this matters for the client SDK specifically

The client SDK is vendored into every customer's plugin zip — often thousands of sites per integrator. When a security fix lands in `main` and a release tag follows, patch-diff attackers will compare the tagged release to the previous version. A descriptive commit message shortcuts their reconnaissance. Unpatched downstream sites become targets the moment the commit is public.

Sensitive areas in this codebase where hygiene matters most:

- `src/Encryption.php`, `src/Envelope.php` — sodium crypto, signature verification, key handling
- `src/Endpoint.php`, `src/Ajax.php` — request surface, nonce/capability checks
- `src/SiteAccess.php`, `src/Remote.php` — SaaS envelope exchange, auth tokens
- `src/SupportUser.php`, `src/SupportRole.php` — privilege boundary, capability grants
- `src/Form.php` — user-facing rendering, escaping
