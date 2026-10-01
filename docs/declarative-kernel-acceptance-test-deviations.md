# Declarative HTTP Kernel — Acceptance Test Validation (deviations report)

**Validated:** [docs/declarative-kernel-acceptance-test-plan.md](declarative-kernel-acceptance-test-plan.md) against its implementation [tests/Acceptance/KernelTest.php](../tests/Acceptance/KernelTest.php) (17 tests, one per plan AT-01–AT-17), cross-checked line-by-line against the vendored docs (system of record: [docs/repos/laravel/docs/middleware.md](repos/laravel/docs/middleware.md), corroborated by lifecycle.md, requests.md, routing.md, container.md) and the framework in `vendor/laravel/framework`.

**Method.** For each plan AT: every **Given** clause was compared token-by-token against the test's declared YAML and fixtures; every **Then** claim was mapped to a concrete Pest assertion and re-derived from the vendored docs; each assertion was then checked for *discriminating power* (would it fail if the corresponding `KernelDeclarationServiceProvider` wiring were broken or behaved contrary to the doc). Finally, seven stricter scratch probes (multi-item `Prepend`/`PrependTo` lists, two-member `appendToGroup`/`prependToGroup`, two-item anchored priority insertions, alias/group getters) were executed against the implementation — all passed — after which the two doc-backed group-order probes were folded into AT-09/AT-10 and the rest removed, since the plan's rule reserves one test per documented behavior.

## Verdict

**17/17 plan behaviors are implemented faithfully.** No test was found weakened, retargeted, or "fixed to the existing behavior": every plan **Then** claim maps to a present, discriminating assertion, and no assertion contradicts the vendored docs. Two deviations exist — **both in the plan document itself** (D-1, D-2). The tests already followed the plan's authoritative bodies (§3, corroborated by §4's and §10's cross-references), so the plan was corrected rather than the tests. Additionally, two tests were **strengthened toward the docs' own examples** (AT-09, AT-10 — the vendored `appendToGroup`/`prependToGroup` examples are two-member lists, but the tests declared only one member), which makes the plan header's "the `Prepend` and `PrependTo` values are called in reverse so the first declared item lands first" claim test-enforced; the plan's AT-09/AT-10 Givens/Then were updated to stay in sync.

## Deviations

### D-1 — plan §1 coverage map misnumbers the three §3 behaviors (cyclically shifted) — plan-side; tests already correct

The §1 coverage map assigns:

| §1 map row (behavior) | map says | §3/§4/§10 say | test comment says |
|---|---|---|---|
| "a global middleware can perform its task after the request is handled, on the outgoing response" | AT-05 | **AT-06** (§3 heading line 95) | AT-06 |
| "all middleware are resolved via the service container — constructor type-hints are injected" | AT-06 | **AT-07** (§3 heading line 105; §10 "§3 AT-07") | AT-07 |
| "a middleware inspects and filters the request: it rejects it (redirect) before the application handles it, or allows it to proceed" | AT-07 | **AT-05** (§3 heading line 85; §4 AT-08's Given cites "(AT-05's middleware)" = `EnsureTokenIsValid`) | AT-05 |

The three IDs are cyclically shifted by one position. Three independent plan-internal references (§3's headings, §4's `(AT-05's middleware)` cross-reference to `EnsureTokenIsValid`, §10's `§3 AT-07` cross-reference for container.md) agree with each other and with the tests — the map rows are the outlier and were corrected (rows reordered to match §3's order so map IDs ascend AT-05 → AT-06 → AT-07). **No test change:** renumbering the tests to the map's IDs would have broken consistency with §3, §4, and §10.

**Correction applied to:** [docs/declarative-kernel-acceptance-test-plan.md](declarative-kernel-acceptance-test-plan.md) §1, rows for the three §3 behaviors.

### D-2 — plan header: an uncommitted modification dropped the list-property application clause — plan-side; restored

The plan's working-tree modification rewrote the header's application sentence from "a list property is one call per item in declaration order (the `Prepend` lists are called in reverse …), a map property is one call per entry …, the `set*` keys are one bulk call …" to a version describing only map properties, `set*` keys and `whenRequestLifecycleIsLongerThan` — dropping how list properties (`pushMiddleware`, `prependMiddleware`, `prependToMiddlewarePriority`, `appendToMiddlewarePriority`) are applied, while keeping only a reversal parenthetical attached to map properties. Empirical probes verified the dropped claim true (a two-item `pushMiddleware` declaration runs in declaration order; a two-item `prependMiddleware` declaration reverses call order so the first declared item lands first). Restored the clause with the reversal parentheticals split per shape (`Prepend` values for list properties, `PrependTo` values for map properties).

**Correction applied to:** [docs/declarative-kernel-acceptance-test-plan.md](declarative-kernel-acceptance-test-plan.md) header.

## Per-AT validation (claim → assertion)

Every row: plan **Given/When/Then** vs [tests/Acceptance/KernelTest.php](../tests/Acceptance/KernelTest.php), fixtures under [tests/Fixtures/App/Acceptance/](../tests/Fixtures/App/Acceptance/).

| AT | Plan Then claims | Test assertions | Verdict |
|---|---|---|---|
| AT-01 | `Recorder` observed both requests | `MiddlewareLog::entries() === [Recorder, Recorder]` after requesting two routes with different paths/actions (`EchoController`, `EchoController@alternate`) | faithful |
| AT-02 | `First` untrimmed + empty still `''`; `Last` trimmed + converted to `null` | 4× `assertJsonPath` on `SnapshotEchoController`'s two request-attribute snapshots; bracket = `TrimStrings`/`ConvertEmptyStringsToNull` per requests.md | faithful |
| AT-03 | recorder ran; input trimmed and null-converted | log `[Recorder]`; `input.pad === 'pad'`; `input.empty === null` | faithful (test additionally asserts `getGlobalMiddleware()` equals the declared 8-entry stack — a direct restatement of "the provided stack is the global stack") |
| AT-04 | padded untrimmed; empty field not `null` | `input.pad === '  pad  '`; `input.empty === ''`; `getGlobalMiddleware()` equals the declared 5-entry stack ("the whole global middleware stack") | faithful |
| AT-05 | tokenless → redirect `/home`, never route output; tokened → route output | `assertRedirect('/home')`, `getContent()` excludes `route-output`, then `assertOk()->assertContent('route-output')` with `token=my-secret-token` | faithful |
| AT-06 | client's response carries the after-task | `X-After-Task: performed` header set by `AfterMiddleware` after `$next($request)` | faithful |
| AT-07 | response shows dependency-derived data | `X-Dependency-Report: container-resolved` from container-injected `ContainerReport` | faithful |
| AT-08 | still redirected to `/home` | `assertRedirect('/home')` + log `[EnsureTokenIsValid]` (route declares `withoutMiddleware: [EnsureTokenIsValid]`) | faithful (log assertion additionally proves the exclusion removed nothing) |
| AT-09 | both `First` and `Second` executed, in declared order | log `[GroupFirst, GroupSecond]` — the doc's two-member `appendToGroup` example (`[First, Second]`) declared onto a group defined empty via `setMiddlewareGroups` (required by §9 G-7), assigned `middleware: ['group-name']` | faithful (strengthened: doc's two-member example + declared order) |
| AT-10 | `Prepended` and `Second` executed as part of the group ahead of `First`, first declared landing first | log `[GroupPrepended, GroupSecond, GroupFirst]` — the doc's two-member `prependToGroup` example declared ahead of the group's set member; discriminates the `PrependTo` reversal (without it the order would be `[GroupSecond, GroupPrepended, GroupFirst]`) | faithful (strengthened: doc's two-member example + first-declared-lands-first) |
| AT-11 | exactly `RecorderA`+`RecorderB` ran; no session state | log `[RecorderA, RecorderB]`; `assertJsonPath('hasSession', false)` (`StartSession` absent from redefined `web`) | faithful (the `hasSession` probe discriminates merge-vs-replace: a merged group would still start the session) |
| AT-12 | `EnsureUserIsSubscribed` executed via short alias | `assertForbidden()` + log `[EnsureUserIsSubscribed]` on `middleware: [subscribed]` | faithful |
| AT-13 | `High` executed before `Low` | log `[High, Low]` with route listing `[Low, High]` | faithful |
| AT-14 | `PreBindings` recorded the raw segment | `PreBindings::$observed === '1'` (route lists anchor `SubstituteBindings` first; without the before-insertion the route order would expose the bound model) | faithful |
| AT-15 | `PostBindings` recorded the retrieved model | `PostBindings::$observed` is `User` with `email === 'ada@example.com'` (route lists anchor second) | faithful |
| AT-16 | `terminate` executed after response sent, receiving request and response; route output unaffected | `TerminatingMiddleware::$terminated` non-empty; `$terminateArguments === [[Request::class, Response::class]]`; `assertContent('route-output')` | faithful |
| AT-17 | recorded identities differ (fresh instance) | `$terminated[0] !== $handled[0]` (uniqid identity per instance) | faithful |

Assertions that go beyond the plan's **Then** (AT-03/AT-04 stack equality, AT-08 log, AT-12 status, AT-14/15 controller output, AT-16 argument classes) were each verified against the vendored docs and are consistent strengthenings, not deviations.

## Discriminating power (mutation analysis)

Each AT was checked against a hypothetical wiring failure of the corresponding `KernelDeclarationServiceProvider` branch; in every case at least one assertion fails, so no test can pass with the documented behavior broken: absent prepend/push (AT-01/02), merged instead of replaced `setGlobalMiddleware` (AT-03/04), non-global or non-filtering middleware (AT-05/06/07), exclusion leaking into globals (AT-08), unwired or inverted group mutation (AT-09/10 — the two-member declarations also catch a missing `array_reverse` on `PrependTo` values and dropped declaration order on `AppendTo` values), merged instead of replaced `setMiddlewareGroups` (AT-11), unresolved alias (AT-12), unsorted or uninserted priority (AT-13/14/15), unwired or reusing-instance `terminate` (AT-16/17).

## Empirical probes (verified; two folded in, five removed)

Seven scratch probes exercised plan claims beyond the single-item AT Givens — the plan header's "the `Prepend` and `PrependTo` values are called in reverse so the first declared item lands first" and two-member list forms shown in the vendored examples: two-item `prependMiddleware` order, two-item `pushMiddleware` order, two-member `appendMiddlewareToGroup` order, two-member `prependMiddlewareToGroup` order, two-item before-anchor and after-anchor priority insertions, and alias/group getter checks. **All passed**, confirming the implementation honors these behaviors. The two doc-backed group-order probes were then folded permanently into AT-09 and AT-10 (see the per-AT table); the remaining five cover claims the plan assigns no acceptance test (plan §1 header application mechanics, declarative-kernel.md §2.5 — "no tested behavior sourced from these"), so they were removed per the plan's one-test-per-documented-behavior rule.

## Plan §9 gaps (re-confirmed)

G-1 (`prependToMiddlewarePriority`/`appendToMiddlewarePriority`), G-2 (`whenRequestLifecycleIsLongerThan`), G-3–G-7 (inverse gaps) have no acceptance tests, exactly as the plan specifies; the test file's header comment documents the same exclusion list.
