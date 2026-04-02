<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;


class WebhookController extends Controller
{
    /**
     * Meta Webhook Verification (The Handshake)
     */
    public function verify(Request $request)
    {
        // 1. Get the secret token you put in your .env file
        $localVerifyToken = env('WHATSAPP_VERIFY_TOKEN');

        // 2. Get the parameters Meta is sending in the URL
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        // 3. Check if Meta is trying to subscribe and if the tokens match
        if ($mode === 'subscribe' && $token === $localVerifyToken) {
            Log::info('WhatsApp Webhook Verified Successfully!');
            
            // Meta expects you to just echo back the challenge number they sent you
            return response($challenge, 200)
                ->header('Content-Type', 'text/plain');
        }

        Log::warning('Webhook verification failed. Token mismatch.');
        return response('Forbidden', 403);
    }

    /**
     * Receive incoming messages (We will use this later)
     */
    public function receive(Request $request)
{
    Log::info('RAW WEBHOOK HIT! Data: ' . file_get_contents('php://input'));

    try {
        $entry = $request->input('entry.0.changes.0.value');
        
        $messageBody = $entry['messages'][0]['text']['body'] ?? null;
        $senderNumber = $entry['messages'][0]['from'] ?? null;

        if (!$messageBody || !$senderNumber) {
            return response('EVENT_RECEIVED', 200);
        }

        // 1. Save Customer Message to DB
        DB::table('whatsapp_chats')->insert([
            'phone_number' => $senderNumber,
            'role' => 'user',
            'message' => $messageBody,
            'created_at' => now(),
        ]);

        // 2. Get history
        $history = DB::table('whatsapp_chats')
            ->where('phone_number', $senderNumber)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->reverse();

        $contents = [];
        foreach ($history as $chat) {
            $contents[] = [
                'role' => $chat->role,
                'parts' => [['text' => $chat->message]]
            ];
        }

        $apiKey = env('GEMINI_API_KEY');

        // 3. Ask Gemini
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}", [
            'contents' => $contents,
            'systemInstruction' => [
                'parts' => [['text' => "You are a helpful human-like sales assistant. If a customer asks about purchasing or checking an item, use the checkStockAndPrice tool to look up actual database details before answering. Keep your responses natural, friendly, and concise."]]
            ],
            'tools' => [
                [
                    'functionDeclarations' => [
                        [
                            'name' => 'checkStockAndPrice',
                            'description' => 'Looks up the availability and price of an item in the store catalog based on product name and color.',
                            'parameters' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'product_name' => ['type' => 'STRING'],
                                    'color' => ['type' => 'STRING']
                                ],
                                'required' => ['product_name', 'color']
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        // 4. Handle Gemini's Response
        if ($response->successful()) {
            $data = $response->json();
            $aiResponse = null;
            
            // SCENARIO A: Gemini decided to use the Database Tool
            if (isset($data['candidates'][0]['content']['parts'][0]['functionCall'])) {
                
                $functionCall = $data['candidates'][0]['content']['parts'][0]['functionCall'];
                $functionName = $functionCall['name'];
                $args = $functionCall['args'];

                if ($functionName === 'checkStockAndPrice') {
                    // Look up item in Laravel database
                    $product = DB::table('products')
                        ->where('name', 'like', '%' . $args['product_name'] . '%')
                        ->where('color', 'like', '%' . $args['color'] . '%')
                        ->first();

                    // Generate a natural text response for the user
                    if ($product && $product->stock_quantity > 0) {
                        $aiResponse = "Yes! I just checked and the {$args['color']} {$args['product_name']} is available. The price is {$product->price_cfa} CFA. Would you like to pay now or later?";
                    } else {
                        $aiResponse = "I just checked, and it looks like we are out of stock of the {$args['color']} {$args['product_name']}. Can I help you find another color or item?";
                    }
                }
            } 
            
            // SCENARIO B: Gemini just chatted normally (no tool used)
            if (!$aiResponse) {
                $aiResponse = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Sorry, I am having trouble understanding that.';
            }

            Log::info("Gemini AI replied to {$senderNumber}: " . $aiResponse);

            // 5. Save AI's response to DB
            DB::table('whatsapp_chats')->insert([
                'phone_number' => $senderNumber,
                'role' => 'model',
                'message' => $aiResponse,
                'created_at' => now(),
            ]);

            // 6. Physically send the message to the WhatsApp Interface!
            $this->sendWhatsAppMessage($senderNumber, $aiResponse);

        } else {
            Log::error('Gemini API Error: ' . $response->body());
        }

    } catch (\Exception $e) {
        Log::error('Webhook processing Error: ' . $e->getMessage());
    }

    return response('EVENT_RECEIVED', 200);
}
    private function sendWhatsAppMessage($recipientNumber, $messageText)
{
    $accessToken = env('WHATSAPP_ACCESS_TOKEN');
    $phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID');

    $url = "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages";

    $response = Http::withToken($accessToken)
        ->post($url, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipientNumber,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $messageText
            ]
        ]);

    // ADD THIS TO SEE THE EXACT ERROR 
    if ($response->successful()) {
        Log::info("Message physically sent back to " . $recipientNumber);
    } else {
        Log::error("Failed to send WhatsApp message! Meta says: " . $response->body());
    }
}
}