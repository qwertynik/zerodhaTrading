<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use KiteConnect\KiteConnect;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class CloseStopLossOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zerodha:close-stop-loss-orders
                                {--api-key= : Your Zerodha API key}
                                {--api-secret= : Your Zerodha API secret}
                                {--access-token= : Your Zerodha access token (optional)}
                                {--no-confirm : Skip confirmation before closing orders}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Close pending stop loss orders from Zerodha trading account';

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

            // Get all open orders and convert to array
            $orders = $kite->getOrders();
            $ordersArray = json_decode(json_encode($orders), true);

            // Filter for stop loss orders
            $stopLossOrders = array_filter($ordersArray, function ($order) {
                // Stop loss orders are usually identified by order_type = 'SL' or 'SL-M'
                return in_array($order['order_type'], ['SL', 'SL-M']);
            });

            if (empty($stopLossOrders)) {
                $this->info('No pending stop loss orders found');
                return 0;
            }

            // Display the stop loss orders
            $this->table([
                'Order ID',
                'Symbol',
                'Quantity',
                'Price',
                'Status',
                'Order Type',
                'Product Type',
                'Exchange'
            ], array_map(function ($order) {
                return [
                    $order['order_id'],
                    $order['tradingsymbol'],
                    $order['quantity'],
                    $order['price'],
                    $order['status'],
                    $order['order_type'],
                    $order['product'],
                    $order['exchange']
                ];
            }, array_values($stopLossOrders)));

            // Ask for confirmation unless --no-confirm flag is set
            if (!$skipConfirm) {
                $helper = $this->getHelper('question');
                $question = new ConfirmationQuestion('Do you want to close these stop loss orders? (y/n) ', false);

                if (!$helper->ask($this->input, $this->output, $question)) {
                    $this->info('Operation cancelled');
                    return 0;
                }
            }

            // Close each stop loss order
            foreach ($stopLossOrders as $order) {
                try {
                    // Get variety from order details
                    $variety = $order['variety'] ?? 'regular';
                    $kite->cancelOrder($variety, $order['order_id']);
                    $this->info("Closed order: {$order['order_id']} ({$order['tradingsymbol']})");
                } catch (\Exception $e) {
                    $this->error("Failed to close order {$order['order_id']}: " . $e->getMessage());
                }
            }

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
