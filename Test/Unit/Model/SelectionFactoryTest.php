<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Test\Unit\Model;

use InkThread\DecorationPricing\Model\Artwork\ArtworkFileMapper;
use InkThread\DecorationPricing\Model\SelectionFactory;
use InkThread\DecorationPricing\Model\Source\DecorationMethod;
use InkThread\DecorationPricing\Model\Source\PrintLocation;
use PHPUnit\Framework\TestCase;

class SelectionFactoryTest extends TestCase
{
    /**
     * @var SelectionFactory
     */
    private SelectionFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new SelectionFactory(new ArtworkFileMapper());
    }

    /**
     * @dataProvider notPostedProvider
     */
    public function testReturnsNullWhenNothingWasPosted(mixed $data): void
    {
        $this->assertNull($this->factory->createFromRequestData($data));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notPostedProvider(): array
    {
        return ['null' => [null], 'string' => ['embroidery'], 'int' => [1]];
    }

    public function testNormalisesInput(): void
    {
        $selection = $this->factory->createFromRequestData([
            'method' => ' embroidery ',
            'locations' => [' left_chest', '', 'sleeve ', ['nested']],
            'note' => '  Logo on white  ',
        ]);

        $this->assertSame(DecorationMethod::EMBROIDERY, $selection->getMethod());
        $this->assertSame([PrintLocation::LEFT_CHEST, PrintLocation::SLEEVE], $selection->getLocations());
        $this->assertSame('Logo on white', $selection->getNote());
    }

    public function testSingleLocationStringIsAccepted(): void
    {
        $selection = $this->factory->createFromRequestData(['method' => 'screen_print', 'locations' => 'full_back']);

        $this->assertSame([PrintLocation::FULL_BACK], $selection->getLocations());
        $this->assertSame('', $selection->getNote());
    }

    public function testDuplicatesAreKeptForTheValidator(): void
    {
        $selection = $this->factory->createFromRequestData([
            'method' => 'embroidery',
            'locations' => ['sleeve', 'sleeve'],
        ]);

        $this->assertSame([PrintLocation::SLEEVE, PrintLocation::SLEEVE], $selection->getLocations());
    }

    /**
     * @dataProvider noneProvider
     * @param array<string,mixed> $data
     */
    public function testMethodNoneDropsLocationsAndNote(array $data): void
    {
        $selection = $this->factory->createFromRequestData($data);

        $this->assertSame(DecorationMethod::NONE, $selection->getMethod());
        $this->assertSame([], $selection->getLocations());
        $this->assertSame('', $selection->getNote());
        $this->assertFalse($selection->isDecorated());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function noneProvider(): array
    {
        return [
            'explicit none' => [['method' => 'none', 'locations' => ['sleeve'], 'note' => 'x']],
            'empty method' => [['method' => '', 'locations' => ['sleeve']]],
            'missing method' => [['locations' => ['sleeve']]],
            'non-scalar method' => [['method' => ['embroidery']]],
        ];
    }

    public function testLocationTextsAreKeptOnlyForSelectedLocations(): void
    {
        $selection = $this->factory->createFromRequestData([
            'method' => 'embroidery',
            'locations' => ['left_chest', 'full_back'],
            'location_texts' => [
                'left_chest' => '  ACME ',
                'full_back' => '',
                'sleeve' => 'not selected',
                'x' => ['y'],
            ],
        ]);

        $this->assertSame([PrintLocation::LEFT_CHEST => 'ACME'], $selection->getLocationTexts());
    }

    public function testRequestDataCannotInjectArtwork(): void
    {
        $selection = $this->factory->createFromRequestData([
            'method' => 'embroidery',
            'locations' => ['sleeve'],
            'artwork' => ['sleeve' => ['path' => 'inkthread_decoration/artwork/x.png', 'name' => 'x.png']],
        ]);

        $this->assertSame([], $selection->getArtwork());
    }

    public function testStoredDataRestoresArtworkForSelectedLocations(): void
    {
        $selection = $this->factory->createFromStored([
            'method' => 'embroidery',
            'locations' => ['sleeve'],
            'artwork' => [
                'sleeve' => ['path' => 'inkthread_decoration/artwork/a.png', 'name' => 'logo.png', 'size' => 10],
                'full_back' => ['path' => 'inkthread_decoration/artwork/b.png', 'name' => 'not-selected.png'],
            ],
        ]);

        $this->assertSame(['sleeve'], array_keys($selection->getArtwork()));
        $this->assertSame('logo.png', $selection->getArtwork()['sleeve']->getName());
    }

    /**
     * @dataProvider unsafeArtworkPathProvider
     */
    public function testStoredArtworkOutsideStorageIsIgnored(string $path): void
    {
        $selection = $this->factory->createFromStored([
            'method' => 'embroidery',
            'locations' => ['sleeve'],
            'artwork' => ['sleeve' => ['path' => $path, 'name' => 'x.png']],
        ]);

        $this->assertSame([], $selection->getArtwork());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeArtworkPathProvider(): array
    {
        return [
            'other var dir' => ['log/system.log'],
            'traversal' => ['inkthread_decoration/artwork/../../app/etc/env.php'],
            'absolute' => ['/etc/passwd'],
        ];
    }

    public function testPricingKeyIgnoresLocationOrderAndNote(): void
    {
        $first = $this->factory->createFromRequestData([
            'method' => 'embroidery',
            'locations' => ['sleeve', 'left_chest'],
            'note' => 'A',
        ]);
        $second = $this->factory->createFromRequestData([
            'method' => 'embroidery',
            'locations' => ['left_chest', 'sleeve'],
            'note' => 'B',
        ]);

        $this->assertSame($first->getPricingKey(), $second->getPricingKey());
        $this->assertSame([PrintLocation::SLEEVE, PrintLocation::LEFT_CHEST], $first->getLocations());
    }
}
