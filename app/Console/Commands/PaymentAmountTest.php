<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\PaymentController;

class PaymentAmountTest extends Command
{
    protected $signature = 'test:payment-amounts';
    protected $description = 'Test payment amount processing with various scenarios';

    private $testCases = [
        // Standard cases
        ['billed' => 100.00, 'fee' => 2.50, 'discount' => 0.00, 'absorb' => false],
        ['billed' => 50.00, 'fee' => 1.50, 'discount' => 0.00, 'absorb' => false],
        
        // Odd amounts
        ['billed' => 33.33, 'fee' => 1.99, 'discount' => 0.00, 'absorb' => false],
        ['billed' => 199.99, 'fee' => 5.99, 'discount' => 0.00, 'absorb' => false],
        
        // Very small amounts
        ['billed' => 0.01, 'fee' => 0.01, 'discount' => 0.00, 'absorb' => false],
        ['billed' => 0.50, 'fee' => 0.02, 'discount' => 0.00, 'absorb' => false],
        
        // Fee absorption cases
        ['billed' => 100.00, 'fee' => 2.50, 'discount' => 0.00, 'absorb' => true],
        ['billed' => 50.00, 'fee' => 1.50, 'discount' => 0.00, 'absorb' => true],
        
        // With discounts
        ['billed' => 100.00, 'fee' => 2.50, 'discount' => 10.00, 'absorb' => false],
        ['billed' => 50.00, 'fee' => 1.50, 'discount' => 5.00, 'absorb' => false],
        
        // Edge cases
        ['billed' => 0.99, 'fee' => 0.99, 'discount' => 0.00, 'absorb' => false],
        ['billed' => 999999.99, 'fee' => 999.99, 'discount' => 0.00, 'absorb' => false],
        
        // Floating point precision test cases
        ['billed' => 10.95, 'fee' => 0.33, 'discount' => 0.00, 'absorb' => false],
        ['billed' => 19.95, 'fee' => 0.67, 'discount' => 0.00, 'absorb' => false]
    ];

    public function handle()
    {
        $paymentController = new PaymentController();
        $results = [];
        $errors = [];

        foreach ($this->testCases as $index => $case) {
            $this->info("\nProcessing Test Case #" . ($index + 1));
            $this->line("----------------------------------------");
            $this->line("Input:");
            $this->line("  Billed Amount: $" . number_format($case['billed'], 2));
            $this->line("  Fee Amount: $" . number_format($case['fee'], 2));
            $this->line("  Discount: $" . number_format($case['discount'], 2));
            $this->line("  Fee Absorbed: " . ($case['absorb'] ? 'Yes' : 'No'));

            try {
                $processed = $paymentController->processAmounts(
                    $case['billed'],
                    $case['fee'],
                    $case['discount'],
                    $case['absorb']
                );

                $isValid = $paymentController->validateAmounts($processed);

                $results[] = [
                    'case' => $index + 1,
                    'input' => $case,
                    'output' => $processed,
                    'valid' => $isValid
                ];

                $this->line("\nOutput:");
                $this->line("  Billed: $" . $processed['billed']['amount'] . " ({$processed['billed']['pennies']} pennies)");
                $this->line("  Fee: $" . $processed['fee']['amount'] . " ({$processed['fee']['pennies']} pennies)");
                $this->line("  Discount: $" . $processed['discount']['amount'] . " ({$processed['discount']['pennies']} pennies)");
                $this->line("  Total: $" . $processed['total']['amount'] . " ({$processed['total']['pennies']} pennies)");
                $this->line("  Validation: " . ($isValid ? 'PASSED' : 'FAILED'));

            } catch (\Exception $e) {
                $errors[] = [
                    'case' => $index + 1,
                    'input' => $case,
                    'error' => $e->getMessage()
                ];
                $this->error("Error processing case #" . ($index + 1) . ": " . $e->getMessage());
            }
        }

        // Log all results
        Log::channel('payment-test')->info('Payment Amount Processing Test Results', [
            'timestamp' => now()->toIso8601String(),
            'test_cases' => count($this->testCases),
            'successful' => count($results),
            'errors' => count($errors),
            'results' => $results,
            'errors_detail' => $errors
        ]);

        // Summary
        $this->newLine(2);
        $this->info("Test Summary");
        $this->line("----------------------------------------");
        $this->line("Total Test Cases: " . count($this->testCases));
        $this->line("Successful: " . count($results));
        $this->line("Errors: " . count($errors));
    }
}