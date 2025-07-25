<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use KiteConnect\KiteConnect;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class CloseAllPositions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zerodha:close-all-positions
                                {--api-key= : Your Zerodha API key}
                                {--api-secret= : Your Zerodha API secret}
                                {--access-token= : Your Zerodha access token (optional)}
                                {--no-confirm : Skip confirmation before closing positions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Close all existing positions in Zerodha trading account';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $apiKey = $this->option('api-key') ?? env('ZERODHA_API_KEY');
        $apiSecret = $this->option('api-secret') ?? env('ZERODHA_API_SECRET');
        $accessToken = $this->option('access-token') ?? env('ZERODHA_ACCESS_TOKEN');
        $skipConfirm = $this->option('no-confirm');

        if (!$apiKey || !$apiSecret) {
            $this->error('API key and API secret are required');
            $this->line('You can either pass them as command options or set them in your .env file');
            return 1;
        }

        try {
            $kite = new KiteConnect($apiKey);

            if (!$accessToken) {
                // Start authentication flow
                $loginUrl = $kite->getLoginURL();
                $this->line("Please visit the following URL to authenticate:");
                $this->line($loginUrl);
                $this->line("\nAfter logging in, you will be redirected with a request_token. Please enter it below:");

                $requestToken = $this->ask('Enter request_token from redirect URL:');
                if (!$requestToken) {
                    $this->error('Request token is required');
                    return 1;
                }

                // Exchange request token for access token
                $this->line("Authenticating with Kite Connect...");
                $response = $kite->generateSession($requestToken, $apiSecret);
                $accessToken = $response->access_token;
                $this->info("Successfully obtained access token!");
            }

            $kite->setAccessToken($accessToken);

            // Get all positions
            $positions = $kite->getPositions();
            $positionsArray = json_decode(json_encode($positions), true);

            // Filter for net positions (positions with non-zero quantity)
            $netPositions = array_filter($positionsArray['net'], function ($position) {
                return $position['quantity'] != 0;
            });

            if (empty($netPositions)) {
                $this->info('No open positions found');
                return 0;
            }

            // Display the positions
            $this->table([
                'Symbol',
                'Quantity',
                'Average Price',
                'LTP',
                'P&L',
                'Product Type',
                'Exchange'
            ], array_map(function ($position) {
                return [
                    $position['tradingsymbol'],
                    $position['quantity'],
                    $position['average_price'],
                    $position['last_price'],
                    $position['pnl'],
                    $position['product'],
                    $position['exchange']
                ];
            }, array_values($netPositions)));

            // Ask for confirmation unless --no-confirm flag is set
            if (!$skipConfirm) {
                $helper = $this->getHelper('question');
                $question = new ConfirmationQuestion('Do you want to close all positions? (y/n) ', false);

                if (!$helper->ask($this->input, $this->output, $question)) {
                    $this->info('Operation cancelled');
                    return 0;
                }
            }

            // Close each position
            foreach ($netPositions as $position) {
                try {
                    // Calculate exit quantity (negative for long positions, positive for short)
                    $exitQuantity = $position['quantity'] < 0 ? -$position['quantity'] : $position['quantity'];
                    
                    // Place opposite order to exit position
                    $order = $kite->placeOrder(
                        KiteConnect::VARIETY_REGULAR,
                        $position['tradingsymbol'],
                        $exitQuantity,
                        $position['exchange'],
                        $position['product']
                    );

                    $this->info("Closed position: {$position['tradingsymbol']} (Quantity: {$position['quantity']}, Order ID: {$order->order_id})");
                } catch (\Exception $e) {
                    $this->error("Failed to close position {$position['tradingsymbol']}: " . $e->getMessage());
                }
            }

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
