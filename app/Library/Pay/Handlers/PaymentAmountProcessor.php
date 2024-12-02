<?php

namespace App\Library\Pay\Handlers;

class PaymentAmountProcessor
{
    private const PRECISION = 2;
    private const ROUNDING_MODE = PHP_ROUND_HALF_UP;
    
    /**
     * Processes payment amounts with consistent handling
     */
    public static function processPaymentAmounts(float $billedAmount, float $feeAmount): array 
    {
        // Convert to pennies for precise math
        $billedPennies = self::toPennies($billedAmount);
        $feePennies = self::toPennies($feeAmount);
        $totalPennies = $billedPennies + $feePennies;

        return [
            'billed' => [
                'pennies' => $billedPennies,
                'decimal' => self::fromPennies($billedPennies),
                'formatted' => number_format(self::fromPennies($billedPennies), 2)
            ],
            'fee' => [
                'pennies' => $feePennies,
                'decimal' => self::fromPennies($feePennies),
                'formatted' => number_format(self::fromPennies($feePennies), 2)
            ],
            'total' => [
                'pennies' => $totalPennies,
                'decimal' => self::fromPennies($totalPennies),
                'formatted' => number_format(self::fromPennies($totalPennies), 2)
            ]
        ];
    }

    /**
     * Converts amount to pennies for precise calculations
     */
    private static function toPennies($amount): int
    {
        return (int)round($amount * 100, 0, self::ROUNDING_MODE);
    }

    /**
     * Converts pennies back to decimal amount
     */
    private static function fromPennies(int $pennies): float
    {
        return round($pennies / 100, self::PRECISION, self::ROUNDING_MODE);
    }

    /**
     * Validates payment amounts and relationships
     */
    public static function validateAmounts(array $amounts): bool
    {
        // Ensure amounts are positive
        if ($amounts['billed']['pennies'] <= 0 || $amounts['total']['pennies'] <= 0) {
            return false;
        }

        // Validate fee relationship
        if ($amounts['fee']['pennies'] < 0) {
            return false;
        }

        // Validate total equals billed + fee
        if ($amounts['total']['pennies'] !== ($amounts['billed']['pennies'] + $amounts['fee']['pennies'])) {
            return false;
        }

        return true;
    }
}

class PaymentRequestValidator 
{
    /**
     * Validates payment request data
     */
    public static function validateRequest(array $request, bool $isIvr = false): array
    {
        $errors = [];

        // Required fields based on payment type
        $required = [
            'company' => 'Company ID is required',
            'account' => 'Account number is required',
        ];

        if ($isIvr) {
            $required = array_merge($required, [
                'ccard' => 'Card number is required',
                'ccexp' => 'Card expiration is required',
                'cvv' => 'CVV is required'
            ]);
        }

        foreach ($required as $field => $message) {
            if (empty($request[$field])) {
                $errors[] = $message;
            }
        }

        // Amount validation
        if (!isset($request['bill']) || !is_numeric($request['bill']) || $request['bill'] <= 0) {
            $errors[] = 'Invalid payment amount';
        }

        return $errors;
    }
}

class PaymentTransactionLogger
{
    /**
     * Logs payment transaction details
     */
    public static function logTransaction(string $loggerName, array $data, string $message): void
    {
        $logData = [
            'timestamp' => now()->toIso8601String(),
            'company_id' => $data['company_id'] ?? null,
            'account_id' => $data['account_id'] ?? null,
            'amount_details' => [
                'billed' => $data['amounts']['billed']['formatted'] ?? null,
                'fee' => $data['amounts']['fee']['formatted'] ?? null,
                'total' => $data['amounts']['total']['formatted'] ?? null
            ],
            'transaction_id' => $data['transaction_id'] ?? null,
            'status' => $data['status'] ?? null,
            'is_ivr' => $data['is_ivr'] ?? false,
            'processor_id' => $data['processor_id'] ?? null
        ];

        \Log::channel($loggerName)->info($message, $logData);
    }
}