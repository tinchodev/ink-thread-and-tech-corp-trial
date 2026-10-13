<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Test\Unit\Model\Artwork;

use InkThread\DecorationPricing\Model\Artwork\ArtworkFile;
use InkThread\DecorationPricing\Model\Artwork\ArtworkFileMapper;
use InkThread\DecorationPricing\Model\Artwork\ArtworkToken;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Math\Random;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

/**
 * Uses the real Magento encryptor (libsodium AEAD) with a test key, so tampering is checked for real.
 */
class ArtworkTokenTest extends TestCase
{
    /**
     * @var ArtworkToken
     */
    private ArtworkToken $token;

    /**
     * @var Encryptor
     */
    private Encryptor $encryptor;

    protected function setUp(): void
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnMap([
            [Encryptor::PARAM_CRYPT_KEY, null, str_repeat('k', 32)],
        ]);
        $this->encryptor = new Encryptor($this->createMock(Random::class), $deploymentConfig);
        $this->token = new ArtworkToken($this->encryptor, new Json(), new ArtworkFileMapper());
    }

    public function testRoundTrip(): void
    {
        $file = new ArtworkFile('inkthread_decoration/artwork/abc.png', 'logo.png', 123, 'image/png');

        $decoded = $this->token->decode($this->token->encode($file));

        $this->assertEquals($file, $decoded);
    }

    public function testTamperedTokenIsRejected(): void
    {
        $token = $this->token->encode(
            new ArtworkFile('inkthread_decoration/artwork/abc.png', 'logo.png', 1, 'image/png')
        );
        $tampered = substr($token, 0, -4) . (substr($token, -4) === 'AAAA' ? 'BBBB' : 'AAAA');

        $this->assertNull($this->token->decode($tampered));
    }

    public function testValidlyEncryptedForeignPathIsRejected(): void
    {
        $forged = $this->encryptor->encrypt('{"path":"../app/etc/env.php","name":"env.php"}');

        $this->assertNull($this->token->decode($forged));
    }

    /**
     * @dataProvider garbageProvider
     */
    public function testGarbageIsRejected(mixed $token): void
    {
        $this->assertNull($this->token->decode($token));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function garbageProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'array' => [['x']],
            'plain text' => ['inkthread_decoration/artwork/a.png'],
        ];
    }
}
