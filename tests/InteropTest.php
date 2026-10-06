<?php

namespace Akibeo\Altcha\Tests;

use AltchaOrg\Altcha\Algorithm\Argon2id;
use AltchaOrg\Altcha\Algorithm\DeriveKeyInterface;
use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Algorithm\Sha;
use AltchaOrg\Altcha\Algorithm\ShaAlgorithm;
use AltchaOrg\Altcha\Altcha as Official;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\HmacAlgorithm;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use AltchaOrg\Altcha\VerifySolutionOptions;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runs the plugin against the official ALTCHA PHP library, which stands in
 * for the widget: it solves the plugin's challenges and the plugin verifies
 * the library's.
 */
class InteropTest extends TestCase
{
    public static function algorithms(): array
    {
        return [
            'PBKDF2/SHA-256' => ['PBKDF2/SHA-256', new Pbkdf2()],
            'PBKDF2/SHA-384' => ['PBKDF2/SHA-384', new Pbkdf2(HmacAlgorithm::SHA384)],
            'PBKDF2/SHA-512' => ['PBKDF2/SHA-512', new Pbkdf2(HmacAlgorithm::SHA512)],
            'SHA-256' => ['SHA-256', new Sha()],
            'SHA-384' => ['SHA-384', new Sha(ShaAlgorithm::SHA384)],
            'SHA-512' => ['SHA-512', new Sha(ShaAlgorithm::SHA512)],
            'ARGON2ID' => ['ARGON2ID', new Argon2id()],
        ];
    }

    protected function official(): Official
    {
        return new Official(hmacSignatureSecret: static::SECRET);
    }

    /**
     * The payload the widget would submit for a challenge of the plugin.
     */
    protected function solve(array $challenge, DeriveKeyInterface $algorithm = new Pbkdf2()): Payload
    {
        $challenge = Challenge::fromArray($challenge);
        $solution = $this->official()->solveChallenge(new SolveChallengeOptions(algorithm: $algorithm, challenge: $challenge));

        $this->assertNotNull($solution);

        return new Payload($challenge, $solution);
    }

    protected function options(string $algorithm): array
    {
        return $algorithm === 'ARGON2ID'
            ? ['algorithm' => $algorithm, 'cost' => 1, 'memoryCost' => 64, 'counter' => [1, 3]]
            : ['algorithm' => $algorithm];
    }

    #[DataProvider('algorithms')]
    public function testTheLibrarySolvesAndVerifiesChallengesOfThePlugin(string $name, DeriveKeyInterface $algorithm): void
    {
        if ($name === 'ARGON2ID' && function_exists('sodium_crypto_pwhash') === false) {
            $this->markTestSkipped('ext-sodium is not available');
        }

        $altcha = $this->altcha($this->options($name));
        $payload = $this->solve($altcha->createChallenge(), $algorithm);

        $this->assertGreaterThan(0, $payload->solution->counter);
        $this->assertTrue($this->official()->verifySolution(new VerifySolutionOptions(payload: $payload, algorithm: $algorithm))->verified);

        $this->assertTrue($altcha->verify($payload->toBase64()));
        $this->assertNull($altcha->error());
    }

    #[DataProvider('algorithms')]
    public function testThePluginVerifiesChallengesOfTheLibrary(string $name, DeriveKeyInterface $algorithm): void
    {
        if ($name === 'ARGON2ID' && function_exists('sodium_crypto_pwhash') === false) {
            $this->markTestSkipped('ext-sodium is not available');
        }

        $challenge = $this->official()->createChallenge(new CreateChallengeOptions(
            algorithm: $algorithm,
            cost: $name === 'ARGON2ID' ? 1 : 10,
            counter: 5,
            memoryCost: $name === 'ARGON2ID' ? 64 : null,
            expiresAt: time() + 60,
        ));

        $payload = $this->solve($challenge->toArray(), $algorithm);

        $this->assertSame(5, $payload->solution->counter);
        $this->assertTrue($this->altcha()->verify($payload->toBase64()));
    }

    public function testAPayloadIsAcceptedOnce(): void
    {
        $altcha = $this->altcha();
        $payload = $this->solve($altcha->createChallenge())->toBase64();

        $this->assertTrue($altcha->verify($payload));
        $this->assertFalse($altcha->verify($payload));
        $this->assertSame('replay', $altcha->error());

        // A new challenge is fine again
        $this->assertTrue($altcha->verify($this->solve($altcha->createChallenge())->toBase64()));
    }

    public function testChangedParametersBreakTheSignature(): void
    {
        $altcha = $this->altcha();
        $payload = $this->solve($altcha->createChallenge())->toArray();

        foreach ([['cost' => 1], ['expiresAt' => time() + 9999], ['extra' => 'x'], ['cost' => 10.5], ['cost' => [10]]] as $change) {
            $changed = $payload;
            $changed['challenge']['parameters'] = $change + $changed['challenge']['parameters'];

            $this->assertFalse($altcha->verify(base64_encode(json_encode($changed))));
            $this->assertSame('signature', $altcha->error());
        }
    }

    public function testUnsignableParametersNeverMatchAnEmptySignature(): void
    {
        // No secret and no work needed: parameters the plugin refuses to
        // sign, an empty signature and a key the attacker derived himself.
        $parameters = [
            'algorithm' => 'SHA-256',
            'cost' => 1,
            'expiresAt' => time() + 60,
            'keyLength' => 32,
            'keyPrefix' => '',
            'nonce' => '00',
            'salt' => '00',
        ];
        $key = hash('sha256', "\0\0" . pack('N', 0));

        $altcha = $this->altcha();

        foreach ([['x' => null], ['x' => 1.5], ['x' => []], ['x' => true]] as $extra) {
            foreach (['', '0', 'null'] as $signature) {
                $this->assertFalse($altcha->verify(base64_encode(json_encode([
                    'challenge' => ['parameters' => $parameters + $extra, 'signature' => $signature],
                    'solution' => ['counter' => 0, 'derivedKey' => $key],
                ]))));
                $this->assertSame('signature', $altcha->error());
            }
        }
    }

    public function testChallengesOfAnotherSecretAreRejected(): void
    {
        $payload = $this->solve($this->altcha(['secret' => 'another-secret-another-secret-another-secret'])->createChallenge())->toBase64();
        $altcha = $this->altcha();

        $this->assertFalse($altcha->verify($payload));
        $this->assertSame('signature', $altcha->error());
    }

    public function testWrongSolutionsAreRejected(): void
    {
        $altcha = $this->altcha();
        $payload = $this->solve($altcha->createChallenge())->toArray();

        $wrongCounter = $payload;
        $wrongCounter['solution']['counter']++;

        $wrongKey = $payload;
        $wrongKey['solution']['derivedKey'] = str_repeat('00', 32);

        $outOfRange = $payload;
        $outOfRange['solution']['counter'] = -1;

        foreach ([$wrongCounter, $wrongKey, $outOfRange] as $wrong) {
            $this->assertFalse($altcha->verify(base64_encode(json_encode($wrong))));
            $this->assertSame('solution', $altcha->error());
        }

        // None of the failed attempts used up the challenge
        $this->assertTrue($altcha->verify(base64_encode(json_encode($payload))));
    }

    public function testAKeyThatDoesNotMatchThePrefixIsRejected(): void
    {
        // Signed by the right secret, but counter 0 hardly ever derives a
        // key starting with this prefix.
        $challenge = $this->official()->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2(),
            cost: 10,
            keyPrefix: 'ffffffffffff',
            expiresAt: time() + 60,
        ))->toArray();

        $parameters = $challenge['parameters'];
        $key = hash_pbkdf2('sha256', hex2bin($parameters['nonce']) . pack('N', 0), hex2bin($parameters['salt']), 10, 32);

        $altcha = $this->altcha();

        $this->assertFalse($altcha->verify(base64_encode(json_encode([
            'challenge' => $challenge,
            'solution' => ['counter' => 0, 'derivedKey' => $key],
        ]))));
        $this->assertSame('solution', $altcha->error());
    }

    public function testExpiredChallengesAreRejected(): void
    {
        $challenge = $this->official()->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2(),
            cost: 10,
            counter: 3,
            expiresAt: time() - 1,
        ));

        $altcha = $this->altcha();

        $this->assertFalse($altcha->verify($this->solve($challenge->toArray())->toBase64()));
        $this->assertSame('expired', $altcha->error());
    }

    public function testChallengesWithoutExpiryAreRejected(): void
    {
        $challenge = $this->official()->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2(),
            cost: 10,
            counter: 3,
        ));

        $altcha = $this->altcha();

        $this->assertFalse($altcha->verify($this->solve($challenge->toArray())->toBase64()));
        $this->assertSame('expired', $altcha->error());
    }
}
