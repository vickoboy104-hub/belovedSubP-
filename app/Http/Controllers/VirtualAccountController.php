<?php

namespace App\Http\Controllers;

use App\Services\FlutterwaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            'identity_type' => ['required', Rule::in(['bvn', 'nin'])],
            'bvn' => ['nullable', 'digits:11', 'required_if:identity_type,bvn'],
            'nin' => ['nullable', 'digits:11', 'required_if:identity_type,nin'],
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

        $siteName = trim((string) setting('site_name', config('app.name', 'BelovedSubP')));
        $txRef = 'FLW_VA_'.Str::upper(Str::random(14));
        $phone = trim((string) $validated['phone']);
        $identityType = (string) $validated['identity_type'];
        $identityValue = trim((string) ($validated[$identityType] ?? ''));
        $existingMeta = (array) ($user->virtual_account_metadata ?? []);
        $alreadyAssignedToCustomer = !empty($user->virtual_account_number)
            && in_array((string) ($existingMeta['identity_type'] ?? ''), ['bvn', 'nin'], true)
            && !empty($existingMeta['assigned_using_customer_identity']);

        if ($identityValue === '') {
            return back()->with('error', 'Flutterwave requires a valid '.strtoupper($identityType).' for a permanent virtual account.');
        }

        if (
            $alreadyAssignedToCustomer
            && (string) ($existingMeta['identity_type'] ?? '') === $identityType
            && (string) ($existingMeta['identity_last4'] ?? '') === substr($identityValue, -4)
        ) {
            return back()->with('success', 'Your dedicated virtual account is already active.');
        }

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
        ];

        $payload[$identityType] = $identityValue;

        try {
            $response = $this->flutterwave->createVirtualAccount($payload);
            $json = $response->json();

            if (!$response->successful() || (($json['status'] ?? '') !== 'success' && ($json['status'] ?? false) !== true)) {
                $message = trim((string) ($json['message'] ?? ''));
                $validationErrors = data_get($json, 'error.validation_errors', []);

                if ($message === '' && is_array($validationErrors) && isset($validationErrors[0]['message'])) {
                    $message = (string) $validationErrors[0]['message'];
                }

                if ($message === '') {
                    $message = 'Virtual account generation failed. Please try again.';
                }

                Log::warning('Flutterwave virtual account assignment failed.', [
                    'status' => $response->status(),
                    'body' => $json,
                    'user_id' => $user->id,
                    'identity_type' => $identityType,
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
            if ($identityType === 'bvn') {
                $user->flutterwave_bvn = $identityValue;
            } else {
                $user->flutterwave_nin = $identityValue;
            }
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
                'identity_type' => $identityType,
                'identity_last4' => substr($identityValue, -4),
                'identity_masked' => str_repeat('*', max(strlen($identityValue) - 4, 0)).substr($identityValue, -4),
                'assigned_using_customer_identity' => true,
                'raw_response' => $data,
            ];
            $user->save();

            return back()->with('success', 'Permanent virtual account generated successfully. You can now fund your wallet with direct transfer.');
        } catch (\Throwable $e) {
            Log::error('Flutterwave virtual account assignment exception.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'identity_type' => $identityType,
            ]);

            return back()->with('error', 'Could not generate virtual account at the moment. Please try again later.');
        }
    }
}
