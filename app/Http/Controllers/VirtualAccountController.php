<?php

namespace App\Http\Controllers;

use App\Services\FlutterwaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VirtualAccountController extends Controller
{
    public function __construct(
        private readonly FlutterwaveService $flutterwave,
    ) {
    }

    public function assign(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9]{10,15}$/'],
        ]);

        $user = $request->user();
        if (!$user) {
            return back()->with('error', 'You must be logged in.');
        }

        $firstName = trim((string) ($user->first_name ?: Str::before((string) $user->name, ' ')));
        $lastName = trim((string) ($user->last_name ?: Str::after((string) $user->name, ' ')));

        if ($firstName === '' || $lastName === '') {
            return redirect()
                ->route('profile.edit')
                ->with('error', 'Please update your first and last name in profile before generating a virtual account.');
        }

        if (!$this->flutterwave->configured()) {
            return back()->with('error', 'Flutterwave is not configured yet. Please contact admin.');
        }

        $fixedAccountBvn = trim((string) config('services.flutterwave.fixed_account_bvn', ''));
        if ($fixedAccountBvn === '') {
            return back()->with('error', 'Flutterwave fixed-account BVN is not configured on the server yet.');
        }

        $siteName = trim((string) setting('site_name', config('app.name', 'BelovedSubP')));
        $txRef = 'FLW_VA_'.Str::upper(Str::random(14));
        $phone = trim((string) $validated['phone']);

        $payload = [
            'email' => (string) $user->email,
            'amount' => '0.00',
            'currency' => 'NGN',
            'tx_ref' => $txRef,
            'firstname' => $firstName,
            'lastname' => $lastName,
            'phonenumber' => $phone,
            'is_permanent' => true,
            'narration' => $siteName.' Wallet',
            'bvn' => $fixedAccountBvn,
        ];

        try {
            $response = $this->flutterwave->createVirtualAccount($payload);
            $json = $response->json();

            if (!$response->successful() || (($json['status'] ?? '') !== 'success' && ($json['status'] ?? false) !== true)) {
                $message = (string) ($json['message'] ?? 'Virtual account generation failed. Please try again.');

                Log::warning('Flutterwave virtual account assignment failed.', [
                    'status' => $response->status(),
                    'body' => $json,
                    'user_id' => $user->id,
                ]);

                return back()->with('error', $message);
            }

            $data = (array) ($json['data'] ?? []);
            $accountNumber = trim((string) ($data['account_number'] ?? ''));
            $bankName = trim((string) ($data['bank_name'] ?? ''));
            $accountName = trim((string) ($data['account_name'] ?? $siteName));

            if ($accountNumber === '' || $bankName === '') {
                Log::warning('Flutterwave virtual account response incomplete.', [
                    'user_id' => $user->id,
                    'payload' => $data,
                ]);

                return back()->with('error', 'Virtual account response was incomplete. Please try again in a few seconds.');
            }

            $user->first_name = $firstName;
            $user->last_name = $lastName;
            $user->name = trim($firstName.' '.$lastName);
            $user->phone = $phone;
            $user->virtual_account_provider = 'flutterwave';
            $user->virtual_account_bank = $bankName;
            $user->virtual_account_name = $accountName;
            $user->virtual_account_number = $accountNumber;
            $user->virtual_account_assigned_at = now();
            $user->virtual_account_metadata = [
                'tx_ref' => $txRef,
                'flw_ref' => (string) ($data['flw_ref'] ?? ''),
                'order_ref' => (string) ($data['order_ref'] ?? ''),
                'account_status' => (string) ($data['account_status'] ?? ''),
                'raw_response' => $data,
                'uses_server_fixed_bvn' => true,
            ];
            $user->save();

            return back()->with('success', 'Virtual account generated successfully. You can now transfer directly to fund your wallet.');
        } catch (\Throwable $e) {
            Log::error('Flutterwave virtual account assignment exception.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Could not generate virtual account at the moment. Please try again later.');
        }
    }
}
