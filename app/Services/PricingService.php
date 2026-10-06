<?php

namespace App\Services;

/**
 * Single source of truth for order pricing — a faithful port of
 * utils/pricing.ts. The SAME rules run on the client (live preview,
 * public/js/pricing.js) and on the server (authoritative computation) so the
 * client can never dictate the payable amount.
 */
class PricingService
{
    public const DEFAULT_URGENCY_MULTIPLIERS = [
        'normal' => 1.0,
        'urgent' => 1.3,
        'immediate' => 1.6,
    ];

    public const DEFAULT_COURIER_COST = 35000;

    public static function isValidUrgency(mixed $u): bool
    {
        return in_array($u, ['normal', 'urgent', 'immediate'], true);
    }

    public static function isValidDelivery(mixed $d): bool
    {
        return in_array($d, ['download', 'in_person', 'courier'], true);
    }

    /**
     * @param  array  $service  mapped service (id, basePrice, fields[])
     * @param  array  $formData raw form data from the client
     */
    public static function calculate(
        array $service,
        array $formData,
        string $urgency,
        string $deliveryMethod,
        ?array $multipliers = null,
        ?int $courierCost = null,
        int $discountAmount = 0
    ): array {
        $multipliers = $multipliers ?? self::DEFAULT_URGENCY_MULTIPLIERS;
        $courierCost = $courierCost ?? self::DEFAULT_COURIER_COST;
        $subtotal = (int) ($service['basePrice'] ?? 0);

        if (($service['id'] ?? '') === 'srv-print-doc') {
            // Special print rule: pages × pagePrice × sizeMultiplier × copies + binding
            $pages = max(1, (int) ($formData['pageCount'] ?? 1));
            $copies = max(1, (int) ($formData['copies'] ?? 1));
            $isColor = ($formData['printColor'] ?? '') === 'color';
            $isA3 = ($formData['paperSize'] ?? '') === 'A3';
            $binding = $formData['binding'] ?? null;

            $pagePrice = $isColor ? 5500 : 1500;
            $sizeMultiplier = $isA3 ? 2 : 1;

            $bindingCost = 0;
            if ($binding) {
                $bindingCost = self::optionExtraPrice($service, 'binding', (string) $binding);
                if (! $bindingCost) {
                    if ($binding === 'plastic_coil') {
                        $bindingCost = 25000;
                    }
                    if ($binding === 'metal_coil') {
                        $bindingCost = 40000;
                    }
                }
            }

            $subtotal = $pages * $pagePrice * $sizeMultiplier * $copies + $bindingCost;
        } else {
            foreach ($service['fields'] ?? [] as $field) {
                if (($field['type'] ?? '') === 'select' && ! empty($field['options'])) {
                    $val = $formData[$field['id']] ?? null;
                    foreach ($field['options'] as $opt) {
                        if (($opt['value'] ?? null) === $val && ! empty($opt['extraPrice'])) {
                            $subtotal += (int) $opt['extraPrice'];
                        }
                    }
                }
            }
        }

        $urgencyMultiplier = $multipliers[$urgency] ?? 1.0;
        $deliveryCost = $deliveryMethod === 'courier' ? $courierCost : 0;
        $totalBeforeDiscount = (int) round($subtotal * $urgencyMultiplier) + $deliveryCost;
        $payable = max(0, $totalBeforeDiscount - $discountAmount);

        return [
            'subtotal' => $subtotal,
            'urgencyMultiplier' => $urgencyMultiplier,
            'deliveryCost' => $deliveryCost,
            'totalBeforeDiscount' => $totalBeforeDiscount,
            'discountAmount' => $discountAmount,
            'payable' => $payable,
        ];
    }

    /** Finds the extraPrice declared on a form-field option. */
    private static function optionExtraPrice(array $service, string $fieldId, string $value): int
    {
        foreach ($service['fields'] ?? [] as $field) {
            if (($field['id'] ?? '') !== $fieldId) {
                continue;
            }
            foreach ($field['options'] ?? [] as $opt) {
                if (($opt['value'] ?? '') === $value) {
                    return (int) ($opt['extraPrice'] ?? 0);
                }
            }
        }

        return 0;
    }
}
