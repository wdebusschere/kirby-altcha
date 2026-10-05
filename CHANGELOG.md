# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-05

### Added

- `altcha` snippet, `altcha()->render()`, `altcha()->widget()` and
  `altcha()->scriptTag()` that print the self-hosted ALTCHA widget (v3.3.0)
  with its stylesheet, workers and the translation of the current Kirby
  language.
- `GET /altcha/challenge` route that issues signed proof-of-work challenges
  (`PBKDF2/SHA-256|384|512`, `SHA-256|384|512` or `ARGON2ID`), with
  configurable cost, counter range and expiry.
- `altcha()->verify()` and the `altcha` validator for `invalid()`, with the
  reason of a failure in `altcha()->error()`.
- Replay protection: a solved challenge is accepted once.
- CSP nonce on the script and stylesheet tags: the `nonce` option, or
  `cspNonce()` from `akibeo/kirby-csp` when that plugin is installed. The
  widget runs without inline code or `blob:` workers.
- `enabled` option to switch the captcha off per environment.
- `akibeo.altcha.invalid` error message in English, Dutch, French, German,
  Portuguese and Spanish.
- Installable with Composer (`akibeo/kirby-altcha`), as a Git submodule or by
  copying the folder into `site/plugins/`.

[Unreleased]: https://github.com/wdebusschere/kirby-altcha/compare/1.0.0...HEAD
[1.0.0]: https://github.com/wdebusschere/kirby-altcha/releases/tag/1.0.0
