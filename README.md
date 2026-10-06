# Kirby ALTCHA

[![Tests](https://github.com/wdebusschere/kirby-altcha/actions/workflows/php.yml/badge.svg)](https://github.com/wdebusschere/kirby-altcha/actions/workflows/php.yml) ![Kirby 4/5](https://img.shields.io/badge/Kirby-4%20%7C%205-green.svg) ![License MIT](https://img.shields.io/badge/license-MIT-blue.svg)

[ALTCHA](https://altcha.org) spam protection for [Kirby](https://getkirby.com) forms. ALTCHA is a proof-of-work captcha: the visitor's browser does a second of computing instead of the visitor solving a puzzle. It sets no cookies, tracks nobody and talks to no third party, so it needs no cookie banner and no consent gating.

- **Fully self-hosted** — the widget (v3), its workers and translations ship with the plugin; challenges are created and verified by your own site. No ALTCHA account, no API key, no external request.
- **Widget snippet** — `snippet('altcha')` inside a form prints the widget and, once per page, its scripts.
- **Verification** — `altcha()->verify()` in a controller, or the `altcha` rule in Kirby's `invalid()`.
- **Replay protection** — every solved challenge is accepted once and expires after 20 minutes.
- **Strict CSP** — no inline code, no `blob:` workers; scripts carry the nonce of [akibeo/kirby-csp](https://github.com/wdebusschere/kirby-csp) automatically.
- **Multi-language** — the widget follows the current Kirby language (60+ translations included).
- **No dependencies** — plain PHP (`hash`, optionally `sodium`), tested against the official ALTCHA PHP library.

## Installation

### Composer

```bash
composer require akibeo/kirby-altcha
```

### Download / Git submodule

Copy this repository into `site/plugins/kirby-altcha/`:

```bash
git submodule add https://github.com/wdebusschere/kirby-altcha.git site/plugins/kirby-altcha
```

No build step is required — Kirby autoloads plugins from `site/plugins/`. The plugin registers itself as `akibeo/altcha` and reads its options from the `akibeo.altcha` namespace.

## Configuration

Add to `site/config/config.php`. Only `secret` is required; the rest are
shown with their defaults.

```php
return [
    'akibeo.altcha' => [
        'secret' => 'a-long-random-string', // signs the challenges

        'enabled' => true,           // false: no widget, every verification passes

        // Proof of work, see "Difficulty"
        'algorithm' => 'PBKDF2/SHA-256', // PBKDF2/SHA-256|384|512, SHA-256|384|512 or ARGON2ID
        'cost' => 5000,              // iterations per key (ARGON2ID: time cost)
        'counter' => [500, 1500],    // [min, max] number of keys the browser has to derive
        'memoryCost' => 32768,       // ARGON2ID only: memory per key in KiB
        'expires' => 1200,           // seconds a challenge can be solved and submitted

        // Widget
        'route' => 'altcha/challenge', // where the widget fetches its challenge
        'name' => 'altcha',          // form field the widget submits its payload in
        'language' => null,          // widget language; defaults to the current Kirby language
        'widget' => [],              // default attributes of <altcha-widget>, see "Widget options"
        'assetsUrl' => null,         // base URL of the assets/ folder when you host it yourself; same origin only
        'nonce' => null,             // CSP nonce; defaults to cspNonce() when akibeo/kirby-csp is installed

        'cache' => true,             // remembers used challenges; false disables replay protection
    ],
];
```

Generate a secret with:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Keep it in a host config (`config.<host>.php`) or an environment variable
that is not committed. Anyone who knows the secret can sign their own
challenges, and since challenges and signatures are public a short secret
can be brute-forced offline, so the plugin refuses secrets of fewer than
32 characters and the placeholder above. Without a valid secret the
challenge route answers with HTTP 500 (the reason is in the PHP error log,
and in the response while Kirby's `debug` option is on) and
`altcha()->verify()` throws an `Akibeo\Altcha\AltchaException`: a
misconfigured site should not look like a visitor failing the captcha.

### Replay protection

A solved challenge is accepted once: `verify()` remembers its signature in
the `akibeo.altcha` cache (`site/cache/<host>/akibeo.altcha`) until the
challenge expires. With `'cache' => false` nothing is remembered and one
solved challenge can be submitted again and again until it expires, so
only switch the cache off when every submission is checked some other way.
The check is a read followed by a write, not a single atomic operation, so
a burst of simultaneous submissions of the same payload can slip through
as duplicates; it cannot be used to skip the proof of work.

### Per-environment overrides

`config.localhost.php` and friends can override any key:

```php
'akibeo.altcha' => [
    'enabled' => false, // no captcha while developing or in end-to-end tests
],
```

### Difficulty

The browser derives one key per counter value until it reaches the secret
counter the server picked from the `counter` range; each key costs `cost`
iterations. A desktop browser derives roughly 1000 `PBKDF2/SHA-256` keys of
cost 5000 per second, a phone a few hundred, so the defaults take about a
second on a desktop and a few seconds on a phone. Raise `counter` to make
bots work harder, lower it when visitors have to wait. ALTCHA itself
[recommends](https://altcha.org/docs/integration/proof-of-work-captcha/) a
counter of 5000 to 10000 at this cost, which is ten times the default here;
try it on a phone before going that high.

`ARGON2ID` is memory-hard, which takes away most of the advantage of GPUs.
It needs the `sodium` PHP extension and much smaller numbers, for example:

```php
'algorithm' => 'ARGON2ID',
'cost' => 2,
'memoryCost' => 32768, // 32 MiB
'counter' => [20, 40],
```

Creating a challenge costs the server one key derivation, and the
challenge route is open to everyone: with the PBKDF2 defaults that is a few
milliseconds per request, with `ARGON2ID` it is `memoryCost` of RAM (32 MiB
above) per request, which makes the route a cheap way to load the server.
If you use `ARGON2ID` on a public site, rate-limit `/altcha/challenge` in
the web server or a reverse proxy.

### Widget options

`widget` holds default [attributes](https://github.com/altcha-org/altcha#configuration)
for every `<altcha-widget>`; an array is JSON-encoded, which is how the
`configuration` attribute takes the options that have no attribute of
their own:

```php
'widget' => [
    'auto' => 'onsubmit',      // off, onfocus, onload, onsubmit
    'display' => 'standard',   // standard, bar, floating, overlay, invisible
    'configuration' => ['hideFooter' => true, 'hideLogo' => true],
],
```

The look is set with [CSS custom properties](https://altcha.org/docs/integration/widget-customization/)
(`--altcha-border-radius`, `--altcha-color-base`, …) in your own stylesheet.

### Content-Security-Policy

The plugin loads the widget's stylesheet, script and workers as plain files
from your own domain (`/media/plugins/akibeo/altcha/…`), and the challenge
from `/altcha/challenge`. A policy of `script-src 'self'` (or a nonce),
`style-src 'self'`, `worker-src 'self'` and `connect-src 'self'` is enough;
no `'unsafe-inline'`, `blob:` or external host is needed.

With [akibeo/kirby-csp](https://github.com/wdebusschere/kirby-csp) the tags
carry the per-request nonce. Without it, set `'nonce' => fn () => myNonce()`
(a callable is resolved per request) or leave it `null`.

## Usage

### Widget

Inside a `<form>`, in a plain PHP template:

```php
<form method="post" action="<?= $page->url() ?>">
    …
    <?php snippet('altcha') ?>
    <button type="submit">Send</button>
</form>
```

In a Blade layout:

```blade
@snippet('altcha')
```

Attributes for one widget:

```php
<?php snippet('altcha', ['attrs' => ['auto' => 'onsubmit', 'display' => 'floating']]) ?>
```

The snippet prints the stylesheet and scripts in front of the first widget
of a page. To put them in the `<head>` instead, print the parts yourself:

```php
<?= altcha()->scriptTag() ?>   <!-- in the <head> -->
<?= altcha()->widget() ?>      <!-- in the form -->
```

The widget adds a hidden `altcha` field to the form once the challenge is
solved. It fetches the challenge with JavaScript, so the page itself can be
served from the pages cache.

### Verification

In a controller:

```php
return function ($kirby) {
    if ($kirby->request()->is('POST')) {
        if (altcha()->verify() === false) {
            $alert = ['altcha' => t('akibeo.altcha.invalid')];
        } else {
            // send the email …
        }
    }

    return ['alert' => $alert ?? null];
};
```

`verify()` reads the `altcha` field of the current request; pass the payload
yourself when it comes from somewhere else (`altcha()->verify($data['altcha'])`).
After a failure `altcha()->error()` tells why: `missing`, `malformed`,
`signature`, `expired`, `solution` or `replay`.

With Kirby's `invalid()` helper, as a rule next to the other fields. Add
`required`, or an empty field is not checked at all:

```php
$invalid = invalid($data, [
    'email' => ['required', 'email'],
    'altcha' => ['required', 'altcha'],
], [
    'email' => 'Please enter a valid email address',
    'altcha' => t('akibeo.altcha.invalid'),
]);
```

`akibeo.altcha.invalid` is translated in English, Dutch, French, German,
Portuguese and Spanish.

A payload is accepted **once**: verify it at the point where the form is
otherwise valid and about to be processed, not before. When the form is
shown again after a server-side error, the widget starts over with a new
challenge.

### JSON endpoints

For a form submitted with `fetch()`, send the field along (it is part of
`new FormData(form)`) and call `altcha()->verify()` in the route. Reset the
widget after a failed submission so it fetches a new challenge:

```js
document.querySelector('altcha-widget').reset();
```

### Challenge endpoint

`GET /altcha/challenge` returns a new challenge, never cached:

```json
{
  "parameters": {
    "algorithm": "PBKDF2/SHA-256",
    "cost": 5000,
    "expiresAt": 1791203960,
    "keyLength": 32,
    "nonce": "bd691f089793ea78f3861ff7170447ff",
    "salt": "8c41f119481bc35d37706077a460ff3c",
    "keyPrefix": "ae3f3c5e635e2c8827b51b63bd419392"
  },
  "signature": "045af8ec…"
}
```

## Updating the widget

`assets/` holds the files of the [altcha](https://www.npmjs.com/package/altcha)
npm package (currently 3.3.0): `dist/external/altcha.min.js` and
`altcha.css`, `dist/workers/{pbkdf2,sha,argon2id}.js` and the per-language
files of `dist/i18n/`. `assets/altcha.init.js` is the plugin's own and
registers the workers.

## Development

```bash
composer install
composer test      # vendor/bin/phpunit
```

The logic lives in `Akibeo\Altcha\Altcha` (`src/Altcha.php`). The test suite
in `tests/` runs it against a bare Kirby app and against the official
[altcha-org/altcha](https://github.com/altcha-org/altcha-lib-php) library,
which solves the plugin's challenges and creates challenges for the plugin
to verify.

## License

[MIT](LICENSE) — © [E-xperience LAB](https://e-xperience.pt)

The bundled ALTCHA widget is MIT licensed, © Daniel Regeci, BAU Software
s.r.o. (`assets/LICENSE-altcha.txt`).

## Credits

- Wannes Debusschere
- [ALTCHA](https://altcha.org) by Daniel Regeci
