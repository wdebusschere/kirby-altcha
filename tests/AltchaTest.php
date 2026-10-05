<?php

namespace Akibeo\Altcha\Tests;

use Akibeo\Altcha\AltchaException;

class AltchaTest extends TestCase
{
    public function testWidgetRendersTheChallengeUrl(): void
    {
        $this->assertSame(
            '<altcha-widget challenge="https://example.com/altcha/challenge"></altcha-widget>',
            $this->altcha()->widget()
        );
    }

    public function testWidgetAttributesMergeOptionAndArgument(): void
    {
        $altcha = $this->altcha([
            'route' => '/captcha/',
            'name' => 'captcha',
            'language' => 'nl',
            'widget' => ['auto' => 'onsubmit', 'display' => 'bar'],
        ]);

        $this->assertSame([
            'challenge' => 'https://example.com/captcha',
            'name' => 'captcha',
            'language' => 'nl',
            'auto' => 'onsubmit',
            'display' => 'floating',
        ], $altcha->widgetAttributes(['display' => 'floating']));
    }

    public function testWidgetEscapesAndEncodesAttributes(): void
    {
        $html = $this->altcha()->widget([
            'theme' => 'a"b',
            'configuration' => ['hideFooter' => true],
            'workers' => 2,
            'auto' => null,
        ]);

        $this->assertStringContainsString('theme="a&quot;b"', $html);
        $this->assertStringContainsString('configuration="{&quot;hideFooter&quot;:true}"', $html);
        $this->assertStringContainsString('workers="2"', $html);
        $this->assertStringNotContainsString('auto', $html);
    }

    public function testScriptTagLoadsTheWidgetAndItsWorkers(): void
    {
        $this->assertSame(
            '<link rel="stylesheet" href="/assets/altcha.css">' .
            '<script type="module" src="/assets/altcha.min.js"></script>' .
            '<script defer src="/assets/altcha.init.js" data-pbkdf2="/assets/workers/pbkdf2.js" data-sha="/assets/workers/sha.js" data-argon2id="/assets/workers/argon2id.js"></script>',
            $this->altcha()->scriptTag()
        );
    }

    public function testScriptTagAddsNonceAndTranslation(): void
    {
        $html = $this->altcha(['nonce' => fn () => 'abc"', 'language' => 'fr'])->scriptTag();

        $this->assertSame(4, substr_count($html, ' nonce="abc&quot;"'));
        $this->assertStringContainsString('<script type="module" src="/assets/i18n/fr-fr.js"', $html);
    }

    public function testRenderPrintsTheScriptsOnce(): void
    {
        $altcha = $this->altcha();

        $this->assertStringContainsString('<script', $first = $altcha->render());
        $this->assertStringEndsWith($altcha->widget(), $first);
        $this->assertSame($altcha->widget(), $altcha->render());
    }

    public function testLanguageResolvesToAnAltchaTranslation(): void
    {
        $this->assertNull($this->altcha()->language());
        $this->assertSame('nl', $this->altcha(['language' => 'nl'])->language());
        $this->assertSame('en', $this->altcha(['language' => 'en'])->language());
        $this->assertSame('pt-pt', $this->altcha(['language' => 'pt'])->language());
        $this->assertSame('pt-br', $this->altcha(['language' => 'pt_BR'])->language());
        $this->assertSame('de', $this->altcha(['language' => 'de-CH'])->language());
        $this->assertNull($this->altcha(['language' => 'xx'])->language());
        $this->assertNull($this->altcha(['language' => '../../index'])->language());
    }

    public function testDisabledPrintsNothingAndVerifiesEverything(): void
    {
        $altcha = $this->altcha(['enabled' => false, 'secret' => '']);

        $this->assertSame('', $altcha->widget());
        $this->assertSame('', $altcha->scriptTag());
        $this->assertSame('', $altcha->render());
        $this->assertTrue($altcha->verify());
        $this->assertTrue($altcha->verify('nonsense'));
    }

    public function testOnlyAnExplicitOffDisables(): void
    {
        foreach ([true, 1, '1', 'true', 'on', 'yes', 'nonsense', '', null] as $value) {
            $this->assertTrue($this->altcha(['enabled' => $value])->isEnabled());
        }

        foreach ([false, 0, '0', 'false', 'off'] as $value) {
            $this->assertFalse($this->altcha(['enabled' => $value])->isEnabled());
        }
    }

    public function testCreateChallengeReturnsSignedParameters(): void
    {
        $challenge = $this->altcha(['expires' => 60])->createChallenge();
        $parameters = $challenge['parameters'];

        $this->assertSame(['algorithm', 'cost', 'expiresAt', 'keyLength', 'nonce', 'salt', 'keyPrefix'], array_keys($parameters));
        $this->assertSame('PBKDF2/SHA-256', $parameters['algorithm']);
        $this->assertSame(10, $parameters['cost']);
        $this->assertSame(32, $parameters['keyLength']);
        $this->assertEqualsWithDelta(time() + 60, $parameters['expiresAt'], 2);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $parameters['nonce']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $parameters['salt']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $parameters['keyPrefix']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $challenge['signature']);
    }

    public function testCreateChallengeNeedsASecret(): void
    {
        $this->expectException(AltchaException::class);
        $this->expectExceptionMessage('akibeo.altcha.secret');

        $this->altcha(['secret' => ' '])->createChallenge();
    }

    public function testCreateChallengeRejectsUnknownAlgorithms(): void
    {
        $this->expectException(AltchaException::class);

        $this->altcha(['algorithm' => 'MD5'])->createChallenge();
    }

    public function testVerifyNeedsASecret(): void
    {
        $this->expectException(AltchaException::class);

        $this->altcha(['secret' => ''])->verify('');
    }

    public function testVerifyRejectsMissingAndMalformedPayloads(): void
    {
        $altcha = $this->altcha();

        $this->assertFalse($altcha->verify());
        $this->assertSame('missing', $altcha->error());

        foreach (['not base64!', base64_encode('not json'), base64_encode('{"challenge":{}}'), base64_encode('[1]')] as $payload) {
            $this->assertFalse($altcha->verify($payload));
            $this->assertSame('malformed', $altcha->error());
        }
    }

    public function testVerifyReadsThePayloadFromTheRequest(): void
    {
        $altcha = new \Akibeo\Altcha\Altcha(new \Kirby\Cms\App([
            'roots' => ['index' => $this->tmp],
            'options' => ['akibeo.altcha' => ['secret' => static::SECRET, 'name' => 'captcha']],
            'request' => ['method' => 'POST', 'body' => ['captcha' => base64_encode('{}')]],
        ]));

        $this->assertFalse($altcha->verify());
        $this->assertSame('malformed', $altcha->error());
    }
}
