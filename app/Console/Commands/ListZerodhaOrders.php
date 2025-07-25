<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use KiteConnect\KiteConnect;

class ListZerodhaOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zerodha:list-orders
                                {--api-key= : Your Zerodha API key}
                                {--api-secret= : Your Zerodha API secret}
                                {--access-token= : Your Zerodha access token (optional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List open orders from Zerodha trading account';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $apiKey = $this->option('api-key') ?? env('ZERODHA_API_KEY');
        $apiSecret = $this->option('api-secret') ?? env('ZERODHA_API_SECRET');
        $accessToken = $this->option('access-token') ?? env('ZERODHA_ACCESS_TOKEN');

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

            // Get open orders
            $orders = $kite->getOrders();
            // Convert stdClass to array
            $ordersArray = json_decode(json_encode($orders), true);


            // Display open orders in a table
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
            }, array_values($ordersArray)));

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }
    }
}
