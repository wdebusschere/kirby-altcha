<?php

use Akibeo\Altcha\Altcha;
use Akibeo\Altcha\AltchaException;
use Kirby\Cms\App as Kirby;
use Kirby\Cms\Response;

// The plugin has no dependencies: plain requires work for a Composer
// install and for a folder dropped straight into site/plugins/ alike.
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/AltchaException.php';
require_once __DIR__ . '/src/Altcha.php';

Kirby::plugin('akibeo/altcha', [
    'options' => [
        'enabled' => true,           // false: no widget, every verification passes (local development, tests)
        'secret' => '',              // required: long random string that signs the challenges

        // Proof of work. The browser derives one key of `cost` iterations
        // for every counter up to a random one in the `counter` range; a
        // desktop browser does about 1000 PBKDF2 keys of cost 5000 a second.
        'algorithm' => 'PBKDF2/SHA-256', // PBKDF2/SHA-256|384|512, SHA-256|384|512 or ARGON2ID
        'cost' => 5000,              // iterations per key (ARGON2ID: time cost)
        'counter' => [500, 1500],    // [min, max] number of keys the browser has to derive
        'memoryCost' => 32768,       // ARGON2ID only: memory per key in KiB
        'expires' => 1200,           // seconds a challenge can be solved and submitted

        // Widget
        'route' => 'altcha/challenge', // where the widget fetches its challenge
        'name' => 'altcha',          // form field the widget submits its payload in
        'language' => null,          // widget language; defaults to the current Kirby language
        'widget' => [],              // default attributes of <altcha-widget>, e.g. ['auto' => 'onsubmit']
        'assetsUrl' => null,         // base URL of the assets/ folder when you host it yourself (same origin: workers)
        'nonce' => null,             // CSP nonce; defaults to cspNonce() from akibeo/kirby-csp when present

        // Cache for the challenges that were already used (site/cache/…/akibeo.altcha)
        'cache' => true,
    ],

    'snippets' => [
        'altcha' => __DIR__ . '/snippets/altcha.php',
    ],

    'siteMethods' => [
        'altcha' => fn () => Altcha::instance(),
    ],

    // invalid($data, ['altcha' => ['required', 'altcha']])
    'validators' => [
        'altcha' => fn ($value = null) => Altcha::instance()->verify(is_string($value) ? $value : ''),
    ],

    'translations' => [
        'en' => ['akibeo.altcha.invalid' => 'The verification failed. Please try again.'],
        'de' => ['akibeo.altcha.invalid' => 'Die Überprüfung ist fehlgeschlagen. Bitte versuchen Sie es erneut.'],
        'es' => ['akibeo.altcha.invalid' => 'La verificación ha fallado. Inténtelo de nuevo.'],
        'fr' => ['akibeo.altcha.invalid' => 'La vérification a échoué. Veuillez réessayer.'],
        'nl' => ['akibeo.altcha.invalid' => 'De verificatie is mislukt. Probeer het opnieuw.'],
        'pt' => ['akibeo.altcha.invalid' => 'A verificação falhou. Tente novamente.'],
    ],

    'routes' => fn (Kirby $kirby) => [
        [
            'pattern' => Altcha::instance($kirby)->route(),
            'method' => 'GET',
            'action' => function () {
                $altcha = Altcha::instance();
                $headers = ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'];

                if ($altcha->isEnabled() === false) {
                    return Response::json(['error' => 'ALTCHA is disabled'], 404, false, $headers);
                }

                try {
                    return Response::json($altcha->createChallenge(), 200, false, $headers);
                } catch (AltchaException $e) {
                    // The message names the misconfiguration (missing or
                    // short secret), which is for the developer, not for
                    // every visitor: it goes to the error log, and to the
                    // response only while Kirby's debug mode is on.
                    error_log('akibeo/altcha: ' . $e->getMessage());

                    $error = Kirby::instance()->option('debug') === true ? $e->getMessage() : 'ALTCHA is not configured';

                    return Response::json(['error' => $error], 500, false, $headers);
                }
            },
        ],
    ],
]);
