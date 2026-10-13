<?php
/**
 * InkThread Decoration Pricing
 */
declare(strict_types=1);

namespace InkThread\DecorationPricing\Model;

/**
 * Pools quantities of cart lines that share a product and an identical decoration (method + location set),
 * so e.g. 6 S + 6 M of the same tee with the same decoration are both priced at the 12+ tier.
 * The note and the configurable child (size/colour) do not split a pool; different products never pool.
 */
class TierQuantityResolver
{
    /**
     * Resolve the tier quantity of every line.
     *
     * @param iterable<TierLine> $lines
     * @return array<string, float> line id => tier quantity
     */
    public function resolve(iterable $lines): array
    {
        $poolTotals = [];
        $lineKeys = [];
        $result = [];

        foreach ($lines as $line) {
            $selection = $line->getSelection();
            if ($selection === null || !$selection->isDecorated()) {
                $result[$line->getLineId()] = $line->getQty();
                continue;
            }
            $key = $line->getProductId() . '|' . $selection->getPricingKey();
            $poolTotals[$key] = ($poolTotals[$key] ?? 0.0) + $line->getQty();
            $lineKeys[$line->getLineId()] = $key;
        }

        foreach ($lineKeys as $lineId => $key) {
            $result[$lineId] = $poolTotals[$key];
        }

        return $result;
    }
}
