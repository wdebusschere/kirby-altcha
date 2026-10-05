<?php

namespace Akibeo\Altcha;

use Kirby\Cms\App;
use Throwable;

/**
 * ALTCHA for Kirby: renders the self-hosted widget, issues proof-of-work
 * challenges and verifies the payload a form submits.
 *
 * Implements ALTCHA's PoW v2 (widget v3): the challenge names a key
 * derivation function and the first half of a derived key; the browser
 * counts up until it finds the counter that derives a key with that prefix.
 *
 * All settings live under the `akibeo.altcha` option namespace, see index.php
 * for the defaults and README.md for the description of each option.
 */
class Altcha
{
    /** Algorithm name => hash for PBKDF2/SHA, null for Argon2id */
    protected const ALGORITHMS = [
        'PBKDF2/SHA-256' => 'sha256',
        'PBKDF2/SHA-384' => 'sha384',
        'PBKDF2/SHA-512' => 'sha512',
        'SHA-256' => 'sha256',
        'SHA-384' => 'sha384',
        'SHA-512' => 'sha512',
        'ARGON2ID' => null,
    ];

    protected const KEY_LENGTH = 32; // bytes
    protected const MAX_COUNTER = 4294967295; // the counter is hashed as a uint32

    /** Kirby language code => ALTCHA translation, where they differ */
    protected const LANGUAGES = [
        'es' => 'es-es',
        'fr' => 'fr-fr',
        'pt' => 'pt-pt',
        'zh' => 'zh-cn',
    ];

    protected static ?Altcha $instance = null;

    protected ?string $error = null;
    protected bool $scriptsRendered = false;

    public function __construct(protected App $kirby)
    {
    }

    public static function instance(?App $kirby = null): static
    {
        $kirby ??= App::instance();

        // A new App (clone, tests) gets its own instance
        if (static::$instance === null || static::$instance->kirby !== $kirby) {
            static::$instance = new static($kirby);
        }

        return static::$instance;
    }

    /**
     * Reads one plugin option. Callables are resolved so options like the
     * nonce can be lazy.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        $value = $this->kirby->option('akibeo.altcha.' . $key, $default);

        return is_callable($value) && !is_string($value) ? $value($this->kirby) : $value;
    }

    /**
     * While disabled the widget is not printed and every verification
     * passes, so forms keep working (local development, automated tests).
     * Only an explicit "off" (false, 0, 'false', 'off', …) disables: a
     * value that cannot be read must not switch the captcha off.
     */
    public function isEnabled(): bool
    {
        $enabled = $this->option('enabled', true);

        return $enabled === '' || filter_var($enabled, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * Name of the form field the widget submits its payload in.
     */
    public function fieldName(): string
    {
        $name = trim((string)$this->option('name', 'altcha'));

        return $name !== '' ? $name : 'altcha';
    }

    /* ------------------------------------------------------------------
     * Widget
     * ---------------------------------------------------------------- */

    /**
     * Path of the challenge route, relative to the site URL.
     */
    public function route(): string
    {
        $route = trim((string)$this->option('route', 'altcha/challenge'), '/ ');

        return $route !== '' ? $route : 'altcha/challenge';
    }

    public function challengeUrl(): string
    {
        return rtrim($this->kirby->url(), '/') . '/' . $this->route();
    }

    /**
     * URL of a file in the plugin's assets/ folder.
     */
    public function assetUrl(string $path): string
    {
        $base = trim((string)$this->option('assetsUrl', ''));

        if ($base !== '') {
            return rtrim($base, '/') . '/' . $path;
        }

        return (string)$this->kirby->plugin('akibeo/altcha')?->asset($path)?->url();
    }

    /**
     * The ALTCHA translation for the current language, or null when the
     * widget should detect the language itself (<html lang>, then browser).
     */
    public function language(): ?string
    {
        $code = $this->option('language') ?? $this->kirby->language()?->code();
        $code = str_replace('_', '-', strtolower(trim((string)$code)));

        if ($code === '') {
            return null;
        }

        $base = explode('-', $code)[0];

        foreach ([$code, static::LANGUAGES[$code] ?? null, $base, static::LANGUAGES[$base] ?? null] as $candidate) {
            if ($candidate === 'en' || ($candidate !== null && $this->hasTranslation($candidate))) {
                return $candidate;
            }
        }

        return null;
    }

    protected function hasTranslation(string $language): bool
    {
        return preg_match('/^[a-z0-9-]+$/', $language) === 1
            && is_file(dirname(__DIR__) . '/assets/i18n/' . $language . '.js');
    }

    /**
     * The stylesheet and scripts the widget needs: the widget itself, its
     * workers (as plain files, so no `blob:` or inline code is needed under
     * a Content-Security-Policy) and the translation for the current
     * language. Empty while the plugin is disabled.
     */
    public function scriptTag(): string
    {
        if ($this->isEnabled() === false) {
            return '';
        }

        $this->scriptsRendered = true;

        $nonce = $this->nonce();
        $html = static::tag('link', ['rel' => 'stylesheet', 'href' => $this->assetUrl('altcha.css'), 'nonce' => $nonce]);

        // The widget and its translations are ES module builds: as classic
        // scripts their top-level names (`$`, `e`, `t`, …) would become
        // globals and collide with jQuery and the like. Module scripts are
        // deferred and run in document order, as does the init script.
        $html .= static::tag('script', ['type' => 'module', 'src' => $this->assetUrl('altcha.min.js'), 'nonce' => $nonce]) . '</script>';
        $html .= static::tag('script', [
            'defer' => true,
            'src' => $this->assetUrl('altcha.init.js'),
            'data-pbkdf2' => $this->assetUrl('workers/pbkdf2.js'),
            'data-sha' => $this->assetUrl('workers/sha.js'),
            'data-argon2id' => $this->assetUrl('workers/argon2id.js'),
            'nonce' => $nonce,
        ]) . '</script>';

        // English is built into the widget
        if (($language = $this->language()) !== null && $language !== 'en') {
            $html .= static::tag('script', ['type' => 'module', 'src' => $this->assetUrl('i18n/' . $language . '.js'), 'nonce' => $nonce]) . '</script>';
        }

        return $html;
    }

    /**
     * Attributes of the <altcha-widget> element: the plugin's own, then the
     * `widget` option, then `$attrs`.
     */
    public function widgetAttributes(array $attrs = []): array
    {
        $defaults = ['challenge' => $this->challengeUrl()];

        if ($this->fieldName() !== 'altcha') {
            $defaults['name'] = $this->fieldName();
        }

        if (($language = $this->language()) !== null) {
            $defaults['language'] = $language;
        }

        $widget = $this->option('widget', []);

        return array_merge($defaults, is_array($widget) ? $widget : [], $attrs);
    }

    /**
     * The <altcha-widget> element, to be placed inside a <form>. Empty
     * while the plugin is disabled.
     *
     * @param array $attrs Widget attributes for this instance, e.g.
     *                     `['auto' => 'onsubmit', 'display' => 'floating']`.
     *                     Arrays are JSON-encoded (`configuration`).
     */
    public function widget(array $attrs = []): string
    {
        if ($this->isEnabled() === false) {
            return '';
        }

        return static::tag('altcha-widget', $this->widgetAttributes($attrs)) . '</altcha-widget>';
    }

    /**
     * The widget, preceded by its scripts the first time it is rendered in
     * a request. This is what the `altcha` snippet prints.
     */
    public function render(array $attrs = []): string
    {
        return ($this->scriptsRendered ? '' : $this->scriptTag()) . $this->widget($attrs);
    }

    protected function nonce(): ?string
    {
        $nonce = $this->option('nonce');

        if ($nonce === null && function_exists('cspNonce')) {
            $nonce = cspNonce();
        }

        $nonce = trim((string)$nonce);

        return $nonce !== '' ? $nonce : null;
    }

    /**
     * Opening tag with escaped attributes: `true` prints a bare attribute,
     * `false`/`null` skips it, arrays are JSON-encoded.
     */
    protected static function tag(string $name, array $attrs): string
    {
        $html = '<' . $name;

        foreach ($attrs as $attr => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $html .= ' ' . $attr;
                continue;
            }

            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            $html .= ' ' . $attr . '="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return $html . '>';
    }

    /* ------------------------------------------------------------------
     * Challenge
     * ---------------------------------------------------------------- */

    /**
     * A new signed challenge, as the widget expects it from the challenge
     * URL: `['parameters' => [...], 'signature' => '...']`.
     *
     * The work is fixed by a secret random counter: the browser has to try
     * every counter below it, each costing one key derivation.
     */
    public function createChallenge(): array
    {
        $algorithm = strtoupper(trim((string)$this->option('algorithm', 'PBKDF2/SHA-256')));

        if (array_key_exists($algorithm, static::ALGORITHMS) === false) {
            throw new AltchaException('Unsupported ALTCHA algorithm "' . $algorithm . '", use one of: ' . implode(', ', array_keys(static::ALGORITHMS)));
        }

        $counter = $this->option('counter', [500, 1500]);
        $counter = is_array($counter) ? array_values($counter) : [$counter, $counter];
        $min = min(static::MAX_COUNTER, max(1, (int)($counter[0] ?? 500)));
        $max = min(static::MAX_COUNTER, max($min, (int)($counter[1] ?? $min)));

        $parameters = [
            'algorithm' => $algorithm,
            'cost' => max(1, (int)$this->option('cost', 5000)),
            'expiresAt' => time() + max(10, (int)$this->option('expires', 1200)),
            'keyLength' => static::KEY_LENGTH,
            'nonce' => bin2hex(random_bytes(16)),
            'salt' => bin2hex(random_bytes(16)),
        ];

        if ($algorithm === 'ARGON2ID') {
            $parameters['memoryCost'] = max(8, (int)$this->option('memoryCost', 32768));
        }

        // Signs the challenge, so check the secret before doing the work
        $secret = $this->secret();

        try {
            $key = static::deriveKey($parameters, random_int($min, $max));
        } catch (Throwable $e) {
            throw new AltchaException('Could not create an ALTCHA challenge: ' . $e->getMessage(), 0, $e);
        }

        $parameters['keyPrefix'] = bin2hex(substr($key, 0, intdiv(static::KEY_LENGTH, 2)));

        return [
            'parameters' => $parameters,
            'signature' => static::sign($parameters, $secret),
        ];
    }

    /* ------------------------------------------------------------------
     * Verification
     * ---------------------------------------------------------------- */

    /**
     * Verifies the payload the widget submitted with a form. Every payload
     * is accepted once. While the plugin is disabled this is always true.
     *
     * @param string|null $payload The value of the widget's form field;
     *                             defaults to that field of the current
     *                             request (`altcha`)
     */
    public function verify(?string $payload = null): bool
    {
        $this->error = null;

        if ($this->isEnabled() === false) {
            return true;
        }

        $payload ??= $this->kirby->request()->get($this->fieldName());
        $this->error = $this->check(is_string($payload) ? trim($payload) : '');

        return $this->error === null;
    }

    /**
     * Why the last verify() failed: `missing`, `malformed`, `signature`,
     * `expired`, `solution` or `replay`. Null after a successful one.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    protected function check(string $payload): ?string
    {
        // Fail on a missing secret even for an empty payload, so a
        // misconfigured site does not look like a failed captcha.
        $secret = $this->secret();

        if ($payload === '') {
            return 'missing';
        }

        $data = json_decode((string)base64_decode($payload, true), true);
        $parameters = $data['challenge']['parameters'] ?? null;
        $signature = $data['challenge']['signature'] ?? null;
        $counter = $data['solution']['counter'] ?? null;
        $derivedKey = $data['solution']['derivedKey'] ?? null;

        if (
            is_array($parameters) === false || is_string($signature) === false ||
            is_int($counter) === false || is_string($derivedKey) === false
        ) {
            return 'malformed';
        }

        // Everything below works on parameters this site has signed
        $expected = static::sign($parameters, $secret);

        if ($expected === null || hash_equals($expected, $signature) === false) {
            return 'signature';
        }

        if ((int)($parameters['expiresAt'] ?? 0) <= time()) {
            return 'expired';
        }

        if ($counter < 0 || $counter > static::MAX_COUNTER) {
            return 'solution';
        }

        try {
            $key = static::deriveKey($parameters, $counter);
        } catch (Throwable) {
            return 'solution';
        }

        if (
            hash_equals(bin2hex($key), strtolower($derivedKey)) === false ||
            str_starts_with(bin2hex($key), (string)($parameters['keyPrefix'] ?? '')) === false
        ) {
            return 'solution';
        }

        // A solved challenge stays valid until it expires; remember it so
        // it cannot be submitted a second time.
        $cache = $this->kirby->cache('akibeo.altcha');
        $cacheKey = 'used-' . hash('sha256', $signature);

        if ($cache->get($cacheKey) !== null) {
            return 'replay';
        }

        $cache->set($cacheKey, true, (int)ceil(($parameters['expiresAt'] - time()) / 60));

        return null;
    }

    protected function secret(): string
    {
        $secret = trim((string)$this->option('secret', ''));

        if ($secret === '') {
            throw new AltchaException('No ALTCHA secret configured: set akibeo.altcha.secret to a long random string');
        }

        return $secret;
    }

    /**
     * HMAC over the parameters as JSON with sorted keys, which is what the
     * official ALTCHA libraries sign. Only strings and integers are signed;
     * anything else is not a challenge of this plugin and has no signature.
     */
    protected static function sign(array $parameters, string $secret): ?string
    {
        foreach ($parameters as $value) {
            if (is_string($value) === false && is_int($value) === false) {
                return null;
            }
        }

        ksort($parameters, SORT_STRING);

        return hash_hmac('sha256', json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $secret);
    }

    /**
     * The key for one counter value, as raw bytes.
     */
    protected static function deriveKey(array $parameters, int $counter): string
    {
        $algorithm = (string)($parameters['algorithm'] ?? '');
        $cost = max(1, (int)($parameters['cost'] ?? 1));
        $length = max(1, (int)($parameters['keyLength'] ?? static::KEY_LENGTH));
        $salt = (string)hex2bin((string)($parameters['salt'] ?? ''));
        $password = hex2bin((string)($parameters['nonce'] ?? '')) . pack('N', $counter);

        if (array_key_exists($algorithm, static::ALGORITHMS) === false) {
            throw new AltchaException('Unsupported ALTCHA algorithm "' . $algorithm . '"');
        }

        if ($algorithm === 'ARGON2ID') {
            if (function_exists('sodium_crypto_pwhash') === false) {
                throw new AltchaException('The sodium PHP extension is required for ARGON2ID');
            }

            return sodium_crypto_pwhash(
                $length,
                $password,
                $salt,
                $cost,
                max(8, (int)($parameters['memoryCost'] ?? 32768)) * 1024,
                SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
            );
        }

        $hash = static::ALGORITHMS[$algorithm];

        if (str_starts_with($algorithm, 'PBKDF2/')) {
            return hash_pbkdf2($hash, $password, $salt, $cost, $length, true);
        }

        $key = hash($hash, $salt . $password, true);

        for ($i = 1; $i < $cost; $i++) {
            $key = hash($hash, $key, true);
        }

        return substr($key, 0, $length);
    }
}
