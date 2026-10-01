# Declarative Validator — Acceptance Test Plan (`src/Validator.php`)

**Subject under test:** [src/Validator.php](../src/Validator.php) (`ZeroToProd\LaravelDeclaration\Validator`) — the `DataModel` hydrated from the manifest's `validator:` key ([Manifest.php](../src/Manifest.php) `?Validator $validator`). Its four properties are `Illuminate\Validation\Factory` registry-method names; they are applied by [ValidatorDeclarationServiceProvider.php](../src/Providers/ValidatorDeclarationServiceProvider.php) as identically-named `Factory` calls when Laravel first resolves the shared `validator` service (`callAfterResolving`, queued in `boot()`): one call per entry — `extend`/`extendImplicit`/`extendDependent` receive the rule name and the extension reference (a string, or the map form `{extension, message}` whose keys are the native parameter names), `replacer` receives the rule name and the replacer reference.

**Source documentation:** [docs/repos/laravel/docs/validation.md](repos/laravel/docs/validation.md) — the vendored Laravel docs are the **system of record** for every behavior below (upstream equivalents in §5). The vendored docs document the user-facing custom-validation-rule surface (§ Custom Validation Rules, § Implicit Rules); the mapping of that surface onto the four `Factory` registries is the in-repo contract [declarative-validator.md](declarative-validator.md) (framework-verified) — cited for the declared-surface mapping only, never as the source of a tested behavior.

**Rule:** one **Given / When / Then** test per unique documented behavior. A test exists only where the vendored docs document the behavior; declared surface the docs do not cover is inventoried in §4 (gaps, G-1–G-4), and documented behavior the declared surface cannot express is likewise inventoried in §4 (G-5). Tests are not implemented here.

---

## 1. Coverage map

| `Validator` property | Documented behavior | Test |
|---|---|---|
| `extend` | the application may specify its own validation rules | AT-01 |
| `extend` | a custom rule verifies its attribute's value — an invalid value fails, a valid one passes | AT-01 |
| `extend` | custom rules are not run when the attribute is not present or contains an empty string | AT-02 |
| `extendImplicit` | a custom rule that implies the attribute is required runs even when the attribute is empty | AT-03 |
| `extendImplicit` | an implicit rule only implies required — whether it invalidates a missing or empty attribute is up to the rule | AT-04 |
| `extendDependent` | not documented in the vendored docs | §4 gaps |
| `replacer` | not documented in the vendored docs | §4 gaps |
| `extension` / `message` (the `extend*` map form) | not documented in the vendored docs | §4 gaps |

---

## 2. Application-specified custom validation rules ([validation.md — Custom Validation Rules](repos/laravel/docs/validation.md#custom-validation-rules))

### AT-01 — a declared custom rule verifies its attribute (an invalid value fails, a valid one passes)

**Doc says:** "Laravel provides a variety of helpful validation rules; however, you may wish to specify some of your own." The doc's example is "a rule that verifies a string is uppercase" whose single method (`validate`) "receives the attribute name, its value, and a callback that should be invoked on failure with the validation error message" — [structure.md](repos/laravel/docs/structure.md#the-rules-directory) adds that "Rules are used to encapsulate complicated validation logic in a simple object."

- **Given** the manifest declares `validator.extend: {slug: 'App\Validation\Slug@check'}` — a custom rule named `slug` whose `check` verifies the value against `/^[a-z0-9-]+$/` (`slug` is not a built-in rule; the doc's `uppercase` example name collides with the built-in [uppercase](repos/laravel/docs/validation.md#rule-uppercase) rule) — and a request rule `slug: [required, slug]`.
- **When** a value violating the rule is validated (`Not OK`), and then a value satisfying it (`ok-slug`).
- **Then** the invalid value produces a validation error for `slug` and the valid value passes — the application-specified custom rule verified its attribute's value.

Sources: [validation.md — Custom Validation Rules](repos/laravel/docs/validation.md#custom-validation-rules), [Using Rule Objects](repos/laravel/docs/validation.md#using-rule-objects); [structure.md — The Rules Directory](repos/laravel/docs/structure.md#the-rules-directory). (The extension reference dispatch — how the string reaches the `check` method — is not vendored-documented; see G-1.)

### AT-02 — a custom rule is not run when its attribute is absent or contains an empty string

**Doc says:** "By default, when an attribute being validated is not present or contains an empty string, normal validation rules, including custom rules, are not run." The doc observes this from the outside with the built-in [unique](repos/laravel/docs/validation.md#rule-unique) rule: `$rules = ['name' => ['unique:users,name']]` against `$input = ['name' => '']` yields `Validator::make($input, $rules)->passes(); // true` — the rule was not run against the empty string.

- **Given** the manifest of AT-01 — a declared `extend` rule that fails the empty string (AT-01's `slug` rejects it: the empty string does not match `/^[a-z0-9-]+$/`) — with the request rule `slug: slug` (no `required`).
- **When** data whose `slug` is an empty string is validated, and then data with no `slug` field at all.
- **Then** both validations pass — the custom rule ran against neither input (an empty string would have failed it, so a pass proves the rule was skipped).

Sources: [validation.md — Implicit Rules](repos/laravel/docs/validation.md#implicit-rules).

---

## 3. Implicit custom rules ([validation.md — Implicit Rules](repos/laravel/docs/validation.md#implicit-rules))

### AT-03 — a custom rule that implies required runs even when the attribute is empty or absent

**Doc says:** "For a custom rule to run even when an attribute is empty, the rule must imply that the attribute is required." The declared surface's registry for such rules is `extendImplicit` — the schema extends the doc's empty-string phrasing to "the rule runs even when the field is absent or empty" ([manifest.schema.json](../manifest.schema.json) `definitions.validator.extendImplicit`), matching the framework-verified mapping in [declarative-validator.md](declarative-validator.md) §5.

- **Given** the manifest declares `validator.extendImplicit: {phone: 'App\Validation\Phone'}` — an implicit custom rule that fails every value it is run against — and the request rule `phone: phone`.
- **When** data with no `phone` field is validated, and then data whose `phone` is an empty string.
- **Then** both validations fail on `phone` — the implicit custom rule ran where normal custom rules are skipped (AT-02's behavior).

Sources: [validation.md — Implicit Rules](repos/laravel/docs/validation.md#implicit-rules) (the empty-string case); [manifest.schema.json](../manifest.schema.json) `extendImplicit` description and [declarative-validator.md](declarative-validator.md) §5 (the absent case — declared-surface mapping, framework-verified, not vendored-documented).

### AT-04 — invalidating a missing or empty attribute is the implicit rule's own choice

**Doc says (warning):** An "implicit" rule only _implies_ that the attribute is required. Whether it actually invalidates a missing or empty attribute is up to you.

- **Given** the manifest declares `validator.extendImplicit: {optionalPhone: 'App\Validation\OptionalPhone'}` — an implicit custom rule that passes absent and empty values — and the request rule `phone: [optionalPhone]`.
- **When** data with no `phone` field is validated, and then data whose `phone` is an empty string.
- **Then** both validations pass — implying required did not itself invalidate anything; the rule chose not to fail the missing or empty attribute.

Sources: [validation.md — Implicit Rules](repos/laravel/docs/validation.md#implicit-rules) (the warning blockquote) — the doc states the missing and empty cases jointly; the two When branches observe each.

---

## 4. Documentation gaps — declared surface with no backing docs, and documented behavior with no declarable surface

G-1–G-4: no acceptance test can be written from `docs/repos/laravel/docs/` for the following; each lists the nearest non-backing documentation. These need either an upstream doc reference or a source-derived test (out of scope for this plan). G-5 is the inverse: documented behavior the declared surface cannot express.

| # | Declared surface | Why no doc-backed test |
|---|---|---|
| G-1 | `extend`/`extendImplicit`/`extendDependent` — the reference-string dispatch | `Validator::extend` has zero matches in the vendored docs. The docs register custom rules only as rule objects attached by instance or as inline closures; the declared string-reference dispatch (`Class@method`, a bare class-string → its `validate` method, `Class@__invoke`, a namespaced function) and the callback contract (invoked positionally `($attribute, $value, $parameters, $validator)`; a falsy return fails the attribute) are documented only in the framework source. Nearest non-backing docs: [validation.md — Custom Validation Rules](repos/laravel/docs/validation.md#custom-validation-rules), [Using Closures](repos/laravel/docs/validation.md#using-closures). In-repo mapping (framework-verified): [declarative-validator.md](declarative-validator.md) §1.3, §5. |
| G-2 | `extendDependent` | Dependent extensions have zero matches in the vendored docs; the docs' only absent/empty-attribute documentation for custom rules is [Implicit Rules](repos/laravel/docs/validation.md#implicit-rules) — the behavior `extendDependent` does *not* have (it is validated like an ordinary rule and skipped for absent fields; its native effect is wildcard-parameter rewriting for array attributes). In-repo mapping: [declarative-validator.md](declarative-validator.md) §2.7 ("`extendDependent` is not `extendImplicit`"), §5. |
| G-3 | `replacer` | Replacers have zero matches in the vendored docs; the docs document placeholder replacement only for built-in messages (`:attribute` — [Customizing the Error Messages](repos/laravel/docs/validation.md#manual-customizing-the-error-messages); `:value` — [Specifying Values in Language Files](repos/laravel/docs/validation.md#specifying-values-in-language-files)), not application-defined replacers. In-repo mapping: [declarative-validator.md](declarative-validator.md) §1.3 (Replacers), §5. |
| G-4 | `message` (the `extend*` map form) | Factory-wide fallback messages (`Factory::$fallbackMessages`) are undocumented; the docs document only per-validator custom messages (the third argument to `Validator::make` — [Customizing the Error Messages](repos/laravel/docs/validation.md#manual-customizing-the-error-messages)) and lang-file messages ([Specifying Custom Messages in Language Files](repos/laravel/docs/validation.md#specifying-custom-messages-in-language-files)). In-repo mapping: [declarative-validator.md](declarative-validator.md) §1.2, §2.2, §2.7 (Messages). |
| G-5 | documented custom-rule forms with no declared expression (inverse) | The docs' `ValidationRule` objects (`$fail(...)`), inline closures ([Using Closures](repos/laravel/docs/validation.md#using-closures)), `$fail(...)->translate()` ([Translating Validation Messages](repos/laravel/docs/validation.md#translating-validation-messages)) and `DataAwareRule::setData`/`ValidatorAwareRule::setValidator` ([Accessing Additional Data](repos/laravel/docs/validation.md#accessing-additional-data)) cannot be declared in `validator:`: the declared value is a string reference dispatched as an extend callback — YAML cannot express a closure, and a class reference resolves through the framework's class-based dispatch, whose `($attribute, $value, $parameters, $validator)` call would bind the parsed-parameters array to a `ValidationRule::validate(string $attribute, mixed $value, Closure $fail)` signature's `$fail`. The docs' rule-object form remains declarable as a `requests.rules` class reference — a different surface ([declarative-requests.md](declarative-requests.md) §2.2, "Value or reference, per rule": a class reference is `make()`d and the instance *is* the rule, ≙ `new Uppercase`). |

---

## 5. Sources

Vendored docs (**system of record**, relative to `docs/repos/laravel/docs/`) with upstream equivalents:

1. [validation.md](repos/laravel/docs/validation.md) — https://laravel.com/docs/validation — Custom Validation Rules incl. Using Rule Objects (§2), Implicit Rules (§3, §4 nearest-non-backing), Using Closures / Translating Validation Messages / Accessing Additional Data (§4 gaps only).
2. [structure.md](repos/laravel/docs/structure.md) — https://laravel.com/docs/structure — The Rules Directory (§2 corroboration only; no additional behavior).

In-repo subject/mapping context (no tested behavior sourced from these):

3. [declarative-requests.md](declarative-requests.md) — cited for the `requests.rules` class-reference mapping in G-5 only (§2.2).
4. [manifest.schema.json](../manifest.schema.json) — the declared `validator`/`extension` definitions; the "absent or empty" phrasing cited in AT-03.
5. [declarative-validator.md](declarative-validator.md) — the framework-verified mapping contract; cited for declared-surface mapping only.
6. [src/Validator.php](../src/Validator.php), [src/Providers/ValidatorDeclarationServiceProvider.php](../src/Providers/ValidatorDeclarationServiceProvider.php) — subject-under-test context only.