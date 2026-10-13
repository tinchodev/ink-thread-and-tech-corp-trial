<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model\Artwork;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Opaque reference to a stored artwork file, carried in the buy request so cart edit and reorder keep the file.
 *
 * The reference is encrypted with the store key (authenticated), so a client cannot forge one that points at an
 * arbitrary path; a token that fails to decrypt or does not reference a valid artwork path is ignored.
 */
class ArtworkToken
{
    /**
     * @param EncryptorInterface $encryptor
     * @param Json $json
     * @param ArtworkFileMapper $artworkFileMapper
     */
    public function __construct(
        private readonly EncryptorInterface $encryptor,
        private readonly Json $json,
        private readonly ArtworkFileMapper $artworkFileMapper
    ) {
    }

    /**
     * Encode a stored file as a token.
     *
     * @param ArtworkFile $artworkFile
     * @return string
     */
    public function encode(ArtworkFile $artworkFile): string
    {
        return $this->encryptor->encrypt($this->json->serialize($artworkFile->toArray()));
    }

    /**
     * Decode a token; null when it is invalid or tampered with.
     *
     * @param mixed $token
     * @return ArtworkFile|null
     */
    public function decode(mixed $token): ?ArtworkFile
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        try {
            $json = $this->encryptor->decrypt($token);
            return $json !== '' ? $this->artworkFileMapper->fromArray($this->json->unserialize($json)) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
