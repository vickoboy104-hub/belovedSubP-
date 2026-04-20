<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\UserWalletActivityNotification;
use App\Services\BvnApi;
use App\Services\GsubzApi;
use App\Services\NinApi;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VtuController extends Controller
{
    public function __construct(
        private readonly GsubzApi $gsubz,
        private readonly NinApi $ninApi,
        private readonly BvnApi $bvnApi,
    )
    {
    }

    // =========================================================
    // DASHBOARD
    // =========================================================
    public function dashboard()
    {
        $user = auth()->user();

        if ($user && trim((string) $user->referral_code) === '') {
            $user->referral_code = $this->generateUniqueReferralCode();
            $user->save();
        }

        $walletBalanceKobo = (int) ($user?->wallet?->balance ?? 0);
        $recentOrders = Order::where('user_id', $user->id)->latest()->take(10)->get();
        $recentOrderBalanceMap = $this->buildOrderBalanceMap($recentOrders, $user?->wallet);

        $referralCode = trim((string) ($user?->referral_code ?? ''));
        $referralLink = $referralCode !== '' ? route('referral.visit', ['code' => $referralCode]) : null;
        $referralBalanceKobo = (int) ($user?->referral_earnings_balance ?? 0);
        $referralTotalKobo = (int) ($user?->referral_earnings_total ?? 0);
        $totalReferrals = User::query()->where('referred_by_user_id', $user->id)->count();
        $activeReferrals = User::query()
            ->where('referred_by_user_id', $user->id)
            ->whereNotNull('referral_qualified_at')
            ->count();
        $userNotifications = $user->notifications()->latest()->take(10)->get();
        $unreadUserNotifications = $user->unreadNotifications()->count();

        return view('dashboard', compact(
            'walletBalanceKobo',
            'recentOrders',
            'recentOrderBalanceMap',
            'referralCode',
            'referralLink',
            'referralBalanceKobo',
            'referralTotalKobo',
            'totalReferrals',
            'activeReferrals',
            'userNotifications',
            'unreadUserNotifications',
        ));
    }

    // =========================================================
    // AIRTIME
    // =========================================================
    public function airtimeForm()
    {
        /**
         * Your airtime.blade.php builds the network list itself,
         * so this page doesn't strictly require DB services.
         *
         * But we still pass a safe services list in case you later use it.
         */
        $services = $this->parseServicesSetting('services_airtime');
        if (empty($services)) {
            $services = [
                'mtn'      => 'MTN',
                'airtel'   => 'Airtel',
                'glo'      => 'Glo',
                'etisalat' => '9mobile',
            ];
        }

        $phoneSuggestions = $this->phoneSuggestionsForUser(auth()->user(), 'airtime');

        return view('vtu.airtime', compact('services', 'phoneSuggestions'));
    }

    public function buyAirtime(Request $request)
    {
        $request->validate([
            'service_id' => ['required', 'string'],
            'phone'      => ['required', 'string'],
            'amount'     => ['required', 'numeric', 'min:1'],
        ]);

        $user = auth()->user();
        $wallet = $this->requireWallet($user->wallet);
        $phone = $this->normalizePhone((string) $request->phone);
        if (!$this->isValidPhone($phone)) {
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.airtime',
                ok: false,
                message: $this->buildErrorMessage(5),
                extra: $this->walletPayload($wallet),
                status: 422
            );
        }
        $request->merge(['phone' => $phone]);

        $provider = (string) setting('provider', 'gsubz');

        $baseAmountNaira = (float) $request->amount;
        $markupNaira     = (float) setting('markup_airtime', 0);
        $totalNaira      = $baseAmountNaira + $markupNaira;

        $totalKobo  = $this->toKobo($totalNaira);
        [$userDiscountPercent] = $this->applyDiscount($totalKobo, $user);
        $airtimeDiscountPercent = max(0, min(100, (float) setting('airtime_discount_percent', 2)));
        $discountPercent = max($airtimeDiscountPercent, (float) $userDiscountPercent);
        $discountKobo = (int) round($totalKobo * ($discountPercent / 100));
        $payableKobo = max(0, $totalKobo - $discountKobo);
        $profitKobo = $this->toKobo($markupNaira);
        $payableNaira = $payableKobo / 100;

        $requestId = $this->makeRequestId('AIR');

        DB::beginTransaction();
        try {
            $this->debitWallet(
                wallet: $wallet,
                amountKobo: $payableKobo,
                reference: $requestId,
                description: "Airtime - {$request->phone}"
            );

            $resolvedServiceId = $this->resolveServiceId($request->service_id, $request->service_id);

            $order = Order::create([
                'user_id'            => $user->id,
                'service_id'         => $resolvedServiceId,
                'customer_ref'       => $request->phone,
                'amount'             => $payableKobo,
                'profit'             => $profitKobo,
                'provider'           => $provider,
                'provider_reference' => $requestId,
                'status'             => 'pending',
                'meta'               => [
                    'type'               => 'airtime',
                    'service_id'         => $resolvedServiceId,
                    'phone'              => $request->phone,
                    'base_amount_naira'  => $baseAmountNaira,
                    'markup_naira'       => $markupNaira,
                    'total_amount_naira' => $totalNaira,
                    'discount_percent'   => $discountPercent,
                    'discount_kobo'      => $discountKobo,
                    'discount_naira'     => $discountKobo / 100,
                    'amount_paid_naira'  => $payableNaira,
                    'requestID'          => $requestId,
                ],
            ]);

            if (in_array($provider, ['gsubz', 'alt'], true)) {
                $providerServiceId = $this->providerServiceId($request->service_id);
                $payload = [
                    'serviceID' => $providerServiceId,
                    'amount'    => $baseAmountNaira,
                    'phone'     => $request->phone,
                    'requestID' => $requestId,
                ];

                $resp = $this->gsubz->pay($payload);

                if ($this->gsubz->isSuccessful($resp)) {
                    $order->status = 'success';
                    $order->provider_reference = (string) ($resp['transactionID'] ?? ($resp['requestID'] ?? $requestId));
                    $order->meta = array_merge($order->meta ?? [], [
                        'provider_response' => $resp,
                        'message'           => $this->gsubz->message($resp),
                    ]);
                    $order->save();
                    $this->awardReferralCommission($order);

                    DB::commit();
                    return $this->respondResult(
                        request: $request,
                        routeName: 'vtu.airtime',
                        ok: true,
                        message: 'Airtime purchase successful!',
                        extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
                    );
                }

                $verifyResp = $this->verifyProviderSuccess($requestId);
                if ($verifyResp !== null) {
                    $this->finalizeSuccessfulOrder($order, $requestId, $resp, $verifyResp);

                    DB::commit();
                    return $this->respondResult(
                        request: $request,
                        routeName: 'vtu.airtime',
                        ok: true,
                        message: 'Airtime purchase successful (verified)!',
                        extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
                    );
                }

                $realMessage = $this->userFacingProviderFailureMessage($this->gsubz->message($resp), $resp);
                $this->markFailedAndRefund($order, $wallet, $payableKobo, $requestId, $realMessage, $resp);

                DB::commit();
                return $this->respondResult(
                    request: $request,
                    routeName: 'vtu.airtime',
                    ok: false,
                    message: $realMessage,
                    extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet)),
                    status: 400
                );
            }

            // Mock mode
            $order->status = 'success';
            $order->save();
            $this->awardReferralCommission($order);
            DB::commit();
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.airtime',
                ok: true,
                message: 'Airtime purchase successful (Mock Mode)!',
                extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
            );
        } catch (\RuntimeException $e) {
            DB::rollBack();
            Log::warning('Airtime purchase blocked', ['error' => $e->getMessage()]);
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.airtime',
                ok: false,
                message: $this->userFacingRuntimeFailureMessage($e),
                extra: $this->walletPayload($wallet),
                status: 402
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Airtime purchase error', ['error' => $e->getMessage()]);
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.airtime',
                ok: false,
                message: $this->buildErrorMessage(4),
                extra: $this->walletPayload($wallet),
                status: 500
            );
        }
    }

    // =========================================================
    // DATA
    // =========================================================
    public function dataForm()
    {
        return view('vtu.data-index', [
            'services' => $this->dataServices(),
        ]);
    }

    public function dataServiceForm(string $service)
    {
        $services = $this->dataServices();
        abort_unless(array_key_exists($service, $services), 404);

        return view('vtu.data', [
            'serviceSlug' => $service,
            'serviceLabel' => $services[$service],
            'services' => [$service => $services[$service]],
            'phoneSuggestions' => $this->phoneSuggestionsForUser(auth()->user(), 'data'),
        ]);
    }

    public function buyData(Request $request)
    {
        $request->validate([
            'service_id' => ['required', 'string'],
            'plan'       => ['required', 'string'],
            'phone'      => ['required', 'string'],
            'amount'     => ['required', 'numeric', 'min:1'],
        ]);

        $user = auth()->user();
        $wallet = $this->requireWallet($user->wallet);
        $phone = $this->normalizePhone((string) $request->phone);
        if (!$this->isValidPhone($phone)) {
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.data',
                ok: false,
                message: $this->buildErrorMessage(5),
                extra: $this->walletPayload($wallet),
                status: 422
            );
        }
        $request->merge(['phone' => $phone]);

        $provider = (string) setting('provider', 'gsubz');

        $baseAmountNaira = (float) $request->amount;
        $markupNaira     = (float) setting('markup_data', 0);
        $totalNaira      = $baseAmountNaira + $markupNaira;

        $totalKobo  = $this->toKobo($totalNaira);
        [$discountPercent, $discountKobo, $payableKobo] = $this->applyDiscount($totalKobo, $user);
        $profitKobo = $this->toKobo($markupNaira);
        $payableNaira = $payableKobo / 100;

        $requestId = $this->makeRequestId('DATA');

        DB::beginTransaction();
        try {
            $this->debitWallet(
                wallet: $wallet,
                amountKobo: $payableKobo,
                reference: $requestId,
                description: "Data purchase - {$request->phone}"
            );

            $resolvedServiceId = $this->resolveServiceId($request->service_id, $request->service_id);

            $order = Order::create([
                'user_id'            => $user->id,
                'service_id'         => $resolvedServiceId,
                'customer_ref'       => $request->phone,
                'amount'             => $payableKobo,
                'profit'             => $profitKobo,
                'provider'           => $provider,
                'provider_reference' => $requestId,
                'status'             => 'pending',
                'meta'               => [
                    'type'               => 'data',
                    'service_id'         => $resolvedServiceId,
                    'plan'               => $request->plan,
                    'phone'              => $request->phone,
                    'base_amount_naira'  => $baseAmountNaira,
                    'markup_naira'       => $markupNaira,
                    'total_amount_naira' => $totalNaira,
                    'discount_percent'   => $discountPercent,
                    'discount_kobo'      => $discountKobo,
                    'discount_naira'     => $discountKobo / 100,
                    'amount_paid_naira'  => $payableNaira,
                    'requestID'          => $requestId,
                ],
            ]);

            if (in_array($provider, ['gsubz', 'alt'], true)) {
                $providerServiceId = $this->providerServiceId($request->service_id);
                $payload = [
                    'serviceID' => $providerServiceId,
                    'plan'      => $request->plan,
                    'amount'    => $baseAmountNaira,
                    'phone'     => $request->phone,
                    'requestID' => $requestId,
                ];

                $resp = $this->gsubz->pay($payload);

                if ($this->gsubz->isSuccessful($resp)) {
                    $order->status = 'success';
                    $order->provider_reference = (string) ($resp['transactionID'] ?? ($resp['requestID'] ?? $requestId));
                    $order->meta = array_merge($order->meta ?? [], [
                        'provider_response' => $resp,
                        'message'           => $this->gsubz->message($resp),
                    ]);
                    $order->save();
                    $this->awardReferralCommission($order);

                    DB::commit();
                    return $this->respondResult(
                        request: $request,
                        routeName: 'vtu.data',
                        ok: true,
                        message: 'Data purchase successful!',
                        extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
                    );
                }

                $verifyResp = $this->verifyProviderSuccess($requestId);
                if ($verifyResp !== null) {
                    $this->finalizeSuccessfulOrder($order, $requestId, $resp, $verifyResp);

                    DB::commit();
                    return $this->respondResult(
                        request: $request,
                        routeName: 'vtu.data',
                        ok: true,
                        message: 'Data purchase successful (verified)!',
                        extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
                    );
                }

                $realMessage = $this->userFacingProviderFailureMessage($this->gsubz->message($resp), $resp);
                $this->markFailedAndRefund($order, $wallet, $payableKobo, $requestId, $realMessage, $resp);

                DB::commit();
                return $this->respondResult(
                    request: $request,
                    routeName: 'vtu.data',
                    ok: false,
                    message: $realMessage,
                    extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet)),
                    status: 400
                );
            }

            $order->status = 'success';
            $order->save();
            $this->awardReferralCommission($order);
            DB::commit();
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.data',
                ok: true,
                message: 'Data purchase successful (Mock Mode)!',
                extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
            );
        } catch (\RuntimeException $e) {
            DB::rollBack();
            Log::warning('Data purchase blocked', ['error' => $e->getMessage()]);
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.data',
                ok: false,
                message: $this->userFacingRuntimeFailureMessage($e),
                extra: $this->walletPayload($wallet),
                status: 402
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Data purchase error', ['error' => $e->getMessage()]);
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.data',
                ok: false,
                message: $this->buildErrorMessage(4),
                extra: $this->walletPayload($wallet),
                status: 500
            );
        }
    }

    // =========================================================
    // RECHARGE CARD PRINTING
    // =========================================================
    public function rechargeCardForm()
    {
        $networkLabels = $this->rechargeCardNetworkLabels();
        $values = $this->rechargeCardValues();
        $markup = (float) setting('markup_recharge_card', 0);

        return view('vtu.recharge-card', [
            'networkLabels' => $networkLabels,
            'values' => $values,
            'markup' => $markup,
        ]);
    }

    public function buyRechargeCard(Request $request)
    {
        $allowedNetworks = array_keys($this->rechargeCardNetworkLabels());
        $allowedValues = $this->rechargeCardValues();

        $request->validate([
            'network' => ['required', Rule::in($allowedNetworks)],
            'value' => ['required', 'integer', Rule::in($allowedValues)],
            'num_voucher' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        $user = auth()->user();
        $wallet = $this->requireWallet($user->wallet);
        $provider = (string) setting('provider', 'gsubz');

        $network = strtolower((string) $request->network);
        $value = (int) $request->value;
        $numVoucher = (int) $request->num_voucher;

        $baseAmountNaira = $value * $numVoucher;
        $markupNaira = (float) setting('markup_recharge_card', 0);
        $totalNaira = $baseAmountNaira + $markupNaira;

        $totalKobo = $this->toKobo($totalNaira);
        [$discountPercent, $discountKobo, $payableKobo] = $this->applyDiscount($totalKobo, $user);
        $profitKobo = $this->toKobo($markupNaira);
        $payableNaira = $payableKobo / 100;

        $requestId = $this->makeRequestId('CARD');

        DB::beginTransaction();
        try {
            $this->debitWallet(
                wallet: $wallet,
                amountKobo: $payableKobo,
                reference: $requestId,
                description: "Recharge card ({$network}) x{$numVoucher} @ {$value}"
            );

            $serviceMapKey = 'service_map_card_' . $network;
            $providerServiceId = trim((string) setting($serviceMapKey, $network));
            if ($providerServiceId === '') {
                $providerServiceId = $network;
            }

            $resolvedServiceId = $this->resolveServiceId('card_' . $network, 'card_' . $network);

            $order = Order::create([
                'user_id' => $user->id,
                'service_id' => $resolvedServiceId,
                'customer_ref' => $network . '-cards',
                'amount' => $payableKobo,
                'profit' => $profitKobo,
                'provider' => $provider,
                'provider_reference' => $requestId,
                'status' => 'pending',
                'meta' => [
                    'type' => 'recharge_card',
                    'network' => $network,
                    'value' => $value,
                    'num_voucher' => $numVoucher,
                    'service_id' => $resolvedServiceId,
                    'provider_service_id' => $providerServiceId,
                    'base_amount_naira' => $baseAmountNaira,
                    'markup_naira' => $markupNaira,
                    'total_amount_naira' => $totalNaira,
                    'discount_percent' => $discountPercent,
                    'discount_kobo' => $discountKobo,
                    'discount_naira' => $discountKobo / 100,
                    'amount_paid_naira' => $payableNaira,
                    'requestID' => $requestId,
                ],
            ]);

            if (in_array($provider, ['gsubz', 'alt'], true)) {
                $payload = [
                    'serviceID' => $providerServiceId,
                    'network' => $network,
                    'value' => $value,
                    'numVoucher' => $numVoucher,
                    'requestID' => $requestId,
                ];

                $resp = $this->gsubz->pay($payload);

                if ($this->gsubz->isSuccessful($resp)) {
                    $order->status = 'success';
                    $order->provider_reference = (string) ($resp['transactionID'] ?? ($resp['requestID'] ?? $requestId));
                    $order->meta = array_merge($order->meta ?? [], [
                        'provider_response' => $resp,
                        'message' => $this->gsubz->message($resp),
                    ]);
                    $order->save();
                    $this->awardReferralCommission($order);

                    DB::commit();
                    return $this->respondResult(
                        request: $request,
                        routeName: 'vtu.recharge-card',
                        ok: true,
                        message: 'Recharge card purchase successful!',
                        extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
                    );
                }

                $verifyResp = $this->verifyProviderSuccess($requestId);
                if ($verifyResp !== null) {
                    $this->finalizeSuccessfulOrder($order, $requestId, $resp, $verifyResp);

                    DB::commit();
                    return $this->respondResult(
                        request: $request,
                        routeName: 'vtu.recharge-card',
                        ok: true,
                        message: 'Recharge card purchase successful (verified)!',
                        extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
                    );
                }

                $realMessage = $this->userFacingProviderFailureMessage($this->gsubz->message($resp), $resp);
                $this->markFailedAndRefund($order, $wallet, $payableKobo, $requestId, $realMessage, $resp);

                DB::commit();
                return $this->respondResult(
                    request: $request,
                    routeName: 'vtu.recharge-card',
                    ok: false,
                    message: $realMessage,
                    extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet)),
                    status: 400
                );
            }

            $order->status = 'success';
            $order->save();
            $this->awardReferralCommission($order);
            DB::commit();

            return $this->respondResult(
                request: $request,
                routeName: 'vtu.recharge-card',
                ok: true,
                message: 'Recharge card purchase successful (Mock Mode)!',
                extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
            );
        } catch (\RuntimeException $e) {
            DB::rollBack();
            Log::warning('Recharge card purchase blocked', ['error' => $e->getMessage()]);
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.recharge-card',
                ok: false,
                message: $this->userFacingRuntimeFailureMessage($e),
                extra: $this->walletPayload($wallet),
                status: 402
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Recharge card purchase error', ['error' => $e->getMessage()]);
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.recharge-card',
                ok: false,
                message: $this->buildErrorMessage(4),
                extra: $this->walletPayload($wallet),
                status: 500
            );
        }
    }

    // =========================================================
    // CABLE
    // =========================================================
    public function cableForm()
    {
        return view('vtu.cable-index', ['services' => $this->cableServices()]);
    }

    public function cableServiceForm(string $service)
    {
        $services = $this->cableServices();
        abort_unless(array_key_exists($service, $services), 404);

        return view('vtu.cable', [
            'selectedService' => $service,
            'selectedServiceLabel' => $services[$service],
            'services' => [$service => $services[$service]],
        ]);
    }

    public function buyCable(Request $request)
    {
        $request->validate([
            'service_id'   => ['required', 'in:dstv,gotv,startimes'],
            'plan'         => ['required', 'string'],
            'customer_ref' => ['required', 'string', 'min:5'],
            'base_amount'  => ['required', 'numeric', 'min:1'],
        ]);

        $user = auth()->user();
        $wallet = $this->requireWallet($user->wallet);

        $provider = (string) setting('provider', 'gsubz');

        $baseAmountNaira = (float) $request->base_amount;
        $markupNaira     = (float) setting('markup_cable', 0);
        $totalNaira      = $baseAmountNaira + $markupNaira;

        $totalKobo  = $this->toKobo($totalNaira);
        [$discountPercent, $discountKobo, $payableKobo] = $this->applyDiscount($totalKobo, $user);
        $profitKobo = $this->toKobo($markupNaira);
        $payableNaira = $payableKobo / 100;

        $requestId = $this->makeRequestId('CAB');

        $defaultPhone = (string) setting('default_phone', '08000000000');

        DB::beginTransaction();
        try {
            $this->debitWallet(
                wallet: $wallet,
                amountKobo: $payableKobo,
                reference: $requestId,
                description: "Cable subscription ({$request->service_id}) - {$request->customer_ref}"
            );

            $resolvedServiceId = $this->resolveServiceId($request->service_id, $request->service_id);

            $order = Order::create([
                'user_id'            => $user->id,
                'service_id'         => $resolvedServiceId,
                'customer_ref'       => $request->customer_ref,
                'amount'             => $payableKobo,
                'profit'             => $profitKobo,
                'provider'           => $provider,
                'provider_reference' => $requestId,
                'status'             => 'pending',
                'meta'               => [
                    'type'               => 'cable',
                    'service_id'         => $resolvedServiceId,
                    'plan'               => $request->plan,
                    'customerID'         => $request->customer_ref,
                    'phone'              => $defaultPhone,
                    'base_amount_naira'  => $baseAmountNaira,
                    'markup_naira'       => $markupNaira,
                    'total_amount_naira' => $totalNaira,
                    'discount_percent'   => $discountPercent,
                    'discount_kobo'      => $discountKobo,
                    'discount_naira'     => $discountKobo / 100,
                    'amount_paid_naira'  => $payableNaira,
                    'requestID'          => $requestId,
                ],
            ]);

            if (in_array($provider, ['gsubz', 'alt'], true)) {
                $providerServiceId = $this->providerServiceId($request->service_id);
                $payload = [
                    'serviceID'  => $providerServiceId,
                    'plan'       => $request->plan,
                    'amount'     => $baseAmountNaira,
                    'phone'      => $defaultPhone,
                    'customerID' => $request->customer_ref,
                    'requestID'  => $requestId,
                ];

                $resp = $this->gsubz->pay($payload);

                if ($this->gsubz->isSuccessful($resp)) {
                    $order->status = 'success';
                    $order->provider_reference = (string) ($resp['transactionID'] ?? ($resp['requestID'] ?? $requestId));
                    $order->meta = array_merge($order->meta ?? [], [
                        'provider_response' => $resp,
                        'message'           => $this->gsubz->message($resp),
                    ]);
                    $order->save();
                    $this->awardReferralCommission($order);

                    DB::commit();
                    return $this->respondResult(
                        request: $request,
                        routeName: 'vtu.cable',
                        ok: true,
                        message: 'Cable subscription successful!',
                        extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
                    );
                }

                $verifyResp = $this->verifyProviderSuccess($requestId);
                if ($verifyResp !== null) {
                    $this->finalizeSuccessfulOrder($order, $requestId, $resp, $verifyResp);

                    DB::commit();
                    return $this->respondResult(
                        request: $request,
                        routeName: 'vtu.cable',
                        ok: true,
                        message: 'Cable subscription successful (verified)!',
                        extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
                    );
                }

                $realMessage = $this->userFacingProviderFailureMessage($this->gsubz->message($resp), $resp);
                $this->markFailedAndRefund($order, $wallet, $payableKobo, $requestId, $realMessage, $resp);

                DB::commit();
                return $this->respondResult(
                    request: $request,
                    routeName: 'vtu.cable',
                    ok: false,
                    message: $realMessage,
                    extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet)),
                    status: 400
                );
            }

            $order->status = 'success';
            $order->save();
            $this->awardReferralCommission($order);
            DB::commit();
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.cable',
                ok: true,
                message: 'Cable subscription successful (Mock Mode)!',
                extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
            );
        } catch (\RuntimeException $e) {
            DB::rollBack();
            Log::warning('Cable purchase blocked', ['error' => $e->getMessage()]);
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.cable',
                ok: false,
                message: $this->userFacingRuntimeFailureMessage($e),
                extra: $this->walletPayload($wallet),
                status: 402
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Cable purchase error', ['error' => $e->getMessage()]);
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.cable',
                ok: false,
                message: $this->buildErrorMessage(4),
                extra: $this->walletPayload($wallet),
                status: 500
            );
        }
    }

    // =========================================================
    // ELECTRICITY
    // =========================================================
    public function electricityForm()
    {
        return view('vtu.electricity-index', ['services' => $this->electricityServices()]);
    }

    public function electricityServiceForm(string $service)
    {
        $services = $this->electricityServices();
        abort_unless(array_key_exists($service, $services), 404);

        return view('vtu.electricity', [
            'selectedService' => $service,
            'selectedServiceLabel' => $services[$service],
            'services' => [$service => $services[$service]],
        ]);
    }

    public function buyElectricity(Request $request)
    {
        $request->validate([
            'service_id'   => ['required', 'string'],
            'meter_type'   => ['required', 'in:prepaid,postpaid'],
            'customer_ref' => ['required', 'string', 'min:5'],
            'amount'       => ['required', 'numeric', 'min:50'],
        ]);

        $user = auth()->user();
        $wallet = $this->requireWallet($user->wallet);

        $provider = (string) setting('provider', 'gsubz');

        $baseAmountNaira = (float) $request->amount;
        $markupNaira     = (float) setting('markup_electricity', 0);
        $totalNaira      = $baseAmountNaira + $markupNaira;

        $totalKobo  = $this->toKobo($totalNaira);
        [$discountPercent, $discountKobo, $payableKobo] = $this->applyDiscount($totalKobo, $user);
        $profitKobo = $this->toKobo($markupNaira);
        $payableNaira = $payableKobo / 100;

        $requestId = $this->makeRequestId('ELEC');
        $defaultPhone = (string) setting('default_phone', '08000000000');

        DB::beginTransaction();
        try {
            $this->debitWallet(
                wallet: $wallet,
                amountKobo: $payableKobo,
                reference: $requestId,
                description: "Electricity ({$request->service_id}) - {$request->customer_ref}"
            );

            $resolvedServiceId = $this->resolveServiceId($request->service_id, $request->service_id);

            $order = Order::create([
                'user_id'            => $user->id,
                'service_id'         => $resolvedServiceId,
                'customer_ref'       => $request->customer_ref,
                'amount'             => $payableKobo,
                'profit'             => $profitKobo,
                'provider'           => $provider,
                'provider_reference' => $requestId,
                'status'             => 'pending',
                'meta'               => [
                    'type'               => 'electricity',
                    'service_id'         => $resolvedServiceId,
                    'meter_type'         => $request->meter_type,
                    'customerID'         => $request->customer_ref,
                    'phone'              => $defaultPhone,
                    'base_amount_naira'  => $baseAmountNaira,
                    'markup_naira'       => $markupNaira,
                    'total_amount_naira' => $totalNaira,
                    'discount_percent'   => $discountPercent,
                    'discount_kobo'      => $discountKobo,
                    'discount_naira'     => $discountKobo / 100,
                    'amount_paid_naira'  => $payableNaira,
                    'requestID'          => $requestId,
                ],
            ]);

            if (in_array($provider, ['gsubz', 'alt'], true)) {
                $providerServiceId = $this->providerServiceId($request->service_id);
                $payload = [
                    'serviceID'      => $providerServiceId,
                    'customerID'     => $request->customer_ref,
                    'amount'         => $baseAmountNaira,
                    'variation_code' => $request->meter_type,
                    'phone'          => $defaultPhone,
                    'requestID'      => $requestId,
                ];

                $resp = $this->gsubz->pay($payload);

                if ($this->gsubz->isSuccessful($resp)) {
                    $order->status = 'success';
                    $order->provider_reference = (string) ($resp['transactionID'] ?? ($resp['requestID'] ?? $requestId));
                    $order->meta = array_merge($order->meta ?? [], [
                        'provider_response' => $resp,
                        'message'           => $this->gsubz->message($resp),
                    ]);
                    $order->save();
                    $this->awardReferralCommission($order);

                    DB::commit();
                    return $this->respondResult(
                        request: $request,
                        routeName: 'vtu.electricity',
                        ok: true,
                        message: 'Electricity purchase successful!',
                        extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
                    );
                }

                $verifyResp = $this->verifyProviderSuccess($requestId);
                if ($verifyResp !== null) {
                    $this->finalizeSuccessfulOrder($order, $requestId, $resp, $verifyResp);

                    DB::commit();
                    return $this->respondResult(
                        request: $request,
                        routeName: 'vtu.electricity',
                        ok: true,
                        message: 'Electricity purchase successful (verified)!',
                        extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
                    );
                }

                $realMessage = $this->userFacingProviderFailureMessage($this->gsubz->message($resp), $resp);
                $this->markFailedAndRefund($order, $wallet, $payableKobo, $requestId, $realMessage, $resp);

                DB::commit();
                return $this->respondResult(
                    request: $request,
                    routeName: 'vtu.electricity',
                    ok: false,
                    message: $realMessage,
                    extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet)),
                    status: 400
                );
            }

            $order->status = 'success';
            $order->save();
            $this->awardReferralCommission($order);
            DB::commit();
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.electricity',
                ok: true,
                message: 'Electricity purchase successful (Mock Mode)!',
                extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
            );
        } catch (\RuntimeException $e) {
            DB::rollBack();
            Log::warning('Electricity purchase blocked', ['error' => $e->getMessage()]);
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.electricity',
                ok: false,
                message: $this->userFacingRuntimeFailureMessage($e),
                extra: $this->walletPayload($wallet),
                status: 402
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Electricity purchase error', ['error' => $e->getMessage()]);
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.electricity',
                ok: false,
                message: $this->buildErrorMessage(4),
                extra: $this->walletPayload($wallet),
                status: 500
            );
        }
    }

    // =========================================================
    // EXAM PIN
    // =========================================================
    public function examPinForm()
    {
        return view('vtu.exam-index', ['services' => $this->educationServices()]);
    }

    public function examServiceForm(string $service)
    {
        $services = $this->educationServices();
        abort_unless(array_key_exists($service, $services), 404);

        return view('vtu.exam-pin', [
            'selectedService' => $service,
            'selectedServiceLabel' => $services[$service],
            'services' => [$service => $services[$service]],
        ]);
    }

    public function buyExamPin(Request $request)
    {
        $request->validate([
            'pin_code' => ['required', 'string'],
            'phone'    => ['required', 'string'],
            'profile_id' => ['nullable', 'string', 'max:120'],
            'plan' => ['nullable', 'string', 'max:120'],
            'amount' => ['nullable', 'numeric', 'min:1'],
        ]);

        $supported = array_keys($this->parseServicesSetting('services_education'));
        if (empty($supported)) {
            $supported = ['jamb', 'waec', 'neco', 'nabteb'];
        }

        if (!in_array((string) $request->pin_code, $supported, true)) {
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.exam',
                ok: false,
                message: 'Selected education service is not available.',
                status: 422
            );
        }

        if ((string) $request->pin_code === 'jamb' && trim((string) $request->profile_id) === '') {
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.exam',
                ok: false,
                message: 'Profile ID is required for JAMB purchase.',
                status: 422
            );
        }

        $user = auth()->user();
        $wallet = $this->requireWallet($user->wallet);
        $phone = $this->normalizePhone((string) $request->phone);
        if (!$this->isValidPhone($phone)) {
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.exam',
                ok: false,
                message: $this->buildErrorMessage(5),
                extra: $this->walletPayload($wallet),
                status: 422
            );
        }
        $request->merge(['phone' => $phone]);

        $provider = (string) setting('provider', 'gsubz');

        $markupNaira = (float) setting('price_exam_transaction_fee', setting('markup_exam', 0));
        $priceMap = [
            'jamb' => (float) setting('price_exam_jamb', 0),
            'waec' => (float) setting('price_exam_waec', 0),
            'neco' => (float) setting('price_exam_neco', 0),
            'nabteb' => (float) setting('price_exam_nabteb', 0),
        ];
        $baseNaira = (float) ($request->amount ?? 0);
        if ($baseNaira <= 0) {
            $baseNaira = (float) ($priceMap[(string) $request->pin_code] ?? 0);
        }

        $totalNaira = $baseNaira + $markupNaira;

        if ($totalNaira <= 0) {
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.exam',
                ok: false,
                message: 'Exam price not configured yet. Please set education pricing in Admin Settings.',
                extra: $this->walletPayload($wallet),
                status: 422
            );
        }

        $totalKobo  = $this->toKobo($totalNaira);
        [$discountPercent, $discountKobo, $payableKobo] = $this->applyDiscount($totalKobo, $user);
        $profitKobo = $this->toKobo($markupNaira);
        $payableNaira = $payableKobo / 100;

        $requestId = $this->makeRequestId('EXAM');

        DB::beginTransaction();
        try {
            $this->debitWallet(
                wallet: $wallet,
                amountKobo: $payableKobo,
                reference: $requestId,
                description: "Exam pin ({$request->pin_code}) - {$request->phone}"
            );

            $serviceSlug = (string) setting('service_exam_' . $request->pin_code, $request->pin_code);
            $resolvedServiceId = $this->resolveServiceId($serviceSlug, $serviceSlug);
            $providerServiceId = $this->providerServiceId($serviceSlug);
            $profileId = trim((string) $request->profile_id);

            $order = Order::create([
                'user_id'            => $user->id,
                'service_id'         => $resolvedServiceId,
                'customer_ref'       => $request->phone,
                'amount'             => $payableKobo,
                'profit'             => $profitKobo,
                'provider'           => $provider,
                'provider_reference' => $requestId,
                'status'             => 'pending',
                'meta'               => [
                    'type'               => 'exam',
                    'pin_code'           => $request->pin_code,
                    'service_id'         => $resolvedServiceId,
                    'provider_service_id'=> $providerServiceId,
                    'plan'               => trim((string) $request->plan) !== '' ? (string) $request->plan : null,
                    'phone'              => $request->phone,
                    'profile_id'         => $profileId !== '' ? $profileId : null,
                    'base_amount_naira'  => $baseNaira,
                    'markup_naira'       => $markupNaira,
                    'total_amount_naira' => $totalNaira,
                    'discount_percent'   => $discountPercent,
                    'discount_kobo'      => $discountKobo,
                    'discount_naira'     => $discountKobo / 100,
                    'amount_paid_naira'  => $payableNaira,
                    'requestID'          => $requestId,
                ],
            ]);

            if (in_array($provider, ['gsubz', 'alt'], true)) {
                $payload = [
                    'serviceID' => $providerServiceId,
                    'amount'    => $baseNaira > 0 ? $baseNaira : $markupNaira,
                    'phone'     => $request->phone,
                    'requestID' => $requestId,
                ];
                if (trim((string) $request->plan) !== '') {
                    $payload['plan'] = (string) $request->plan;
                }
                if ($profileId !== '') {
                    $payload['customerID'] = $profileId;
                    $payload['profileID'] = $profileId;
                }

                $resp = $this->gsubz->pay($payload);

                if ($this->gsubz->isSuccessful($resp)) {
                    $order->status = 'success';
                    $order->provider_reference = (string) ($resp['transactionID'] ?? ($resp['requestID'] ?? $requestId));
                    $order->meta = array_merge($order->meta ?? [], [
                        'provider_response' => $resp,
                        'message'           => $this->gsubz->message($resp),
                    ]);
                    $order->save();
                    $this->awardReferralCommission($order);

                    DB::commit();
                    return $this->respondResult(
                        request: $request,
                        routeName: 'vtu.exam',
                        ok: true,
                        message: 'Exam pin purchase successful!',
                        extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
                    );
                }

                $verifyResp = $this->verifyProviderSuccess($requestId);
                if ($verifyResp !== null) {
                    $this->finalizeSuccessfulOrder($order, $requestId, $resp, $verifyResp);

                    DB::commit();
                    return $this->respondResult(
                        request: $request,
                        routeName: 'vtu.exam',
                        ok: true,
                        message: 'Exam pin purchase successful (verified)!',
                        extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
                    );
                }

                $realMessage = $this->userFacingProviderFailureMessage($this->gsubz->message($resp), $resp);
                $this->markFailedAndRefund($order, $wallet, $payableKobo, $requestId, $realMessage, $resp);

                DB::commit();
                return $this->respondResult(
                    request: $request,
                    routeName: 'vtu.exam',
                    ok: false,
                    message: $realMessage,
                    extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet)),
                    status: 400
                );
            }

            $order->status = 'success';
            $order->save();
            $this->awardReferralCommission($order);
            DB::commit();
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.exam',
                ok: true,
                message: 'Exam pin purchase successful (Mock Mode)!',
                extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
            );
        } catch (\RuntimeException $e) {
            DB::rollBack();
            Log::warning('Exam pin purchase blocked', ['error' => $e->getMessage()]);
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.exam',
                ok: false,
                message: $this->userFacingRuntimeFailureMessage($e),
                extra: $this->walletPayload($wallet),
                status: 402
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Exam pin purchase error', ['error' => $e->getMessage()]);
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.exam',
                ok: false,
                message: $this->buildErrorMessage(4),
                extra: $this->walletPayload($wallet),
                status: 500
            );
        }
    }

    // =========================================================
    // PREMIUM APPS
    // =========================================================
    public function premiumAppsForm()
    {
        $services = $this->parseServicesSetting('services_premium');
        if (empty($services)) {
            $services = [
                'canva' => 'Canva Pro',
            ];
        }

        return view('vtu.premium-apps', ['services' => $services]);
    }

    public function buyPremiumApp(Request $request)
    {
        $request->validate([
            'service_id' => ['required', 'string'],
            'plan' => ['required', 'string'],
            'email' => ['required', 'email:rfc,dns'],
            'amount' => ['required', 'numeric', 'min:1'],
            'whatsapp' => ['nullable', 'string'],
        ]);

        $user = auth()->user();
        $wallet = $this->requireWallet($user->wallet);

        $provider = (string) setting('provider', 'gsubz');
        $baseAmountNaira = (float) $request->amount;
        $markupNaira = (float) setting('markup_premium', 0);
        $totalNaira = $baseAmountNaira + $markupNaira;

        $totalKobo = $this->toKobo($totalNaira);
        [$discountPercent, $discountKobo, $payableKobo] = $this->applyDiscount($totalKobo, $user);
        $profitKobo = $this->toKobo($markupNaira);
        $payableNaira = $payableKobo / 100;

        $requestId = $this->makeRequestId('PREM');
        $whatsapp = trim((string) $request->whatsapp) !== ''
            ? $this->normalizePhone((string) $request->whatsapp)
            : (string) setting('default_phone', '08000000000');

        DB::beginTransaction();
        try {
            $this->debitWallet(
                wallet: $wallet,
                amountKobo: $payableKobo,
                reference: $requestId,
                description: "Premium app - {$request->email}"
            );

            $resolvedServiceId = $this->resolveServiceId((string) $request->service_id, (string) $request->service_id);
            $providerServiceId = $this->providerServiceId((string) $request->service_id);

            $order = Order::create([
                'user_id' => $user->id,
                'service_id' => $resolvedServiceId,
                'customer_ref' => (string) $request->email,
                'amount' => $payableKobo,
                'profit' => $profitKobo,
                'provider' => $provider,
                'provider_reference' => $requestId,
                'status' => 'pending',
                'meta' => [
                    'type' => 'premium',
                    'service_id' => $resolvedServiceId,
                    'provider_service_id' => $providerServiceId,
                    'plan' => (string) $request->plan,
                    'email' => (string) $request->email,
                    'whatsapp' => $whatsapp,
                    'base_amount_naira' => $baseAmountNaira,
                    'markup_naira' => $markupNaira,
                    'total_amount_naira' => $totalNaira,
                    'discount_percent' => $discountPercent,
                    'discount_kobo' => $discountKobo,
                    'discount_naira' => $discountKobo / 100,
                    'amount_paid_naira' => $payableNaira,
                    'requestID' => $requestId,
                ],
            ]);

            if ($provider === 'gsubz' || $provider === 'alt') {
                $payload = [
                    'serviceID' => $providerServiceId,
                    'plan' => (string) $request->plan,
                    'amount' => $baseAmountNaira,
                    'phone' => $whatsapp,
                    'requestID' => $requestId,
                    'customerID' => (string) $request->email,
                    'email' => (string) $request->email,
                    'whatsapp' => $whatsapp,
                ];

                $resp = $this->gsubz->pay($payload);

                if ($this->gsubz->isSuccessful($resp)) {
                    $order->status = 'success';
                    $order->provider_reference = (string) ($resp['transactionID'] ?? ($resp['requestID'] ?? $requestId));
                    $order->meta = array_merge($order->meta ?? [], [
                        'provider_response' => $resp,
                        'message' => $this->gsubz->message($resp),
                    ]);
                    $order->save();
                    $this->awardReferralCommission($order);

                    DB::commit();
                    return $this->respondResult(
                        request: $request,
                        routeName: 'vtu.premium-apps',
                        ok: true,
                        message: 'Premium app purchase successful!',
                        extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
                    );
                }

                $verifyResp = $this->verifyProviderSuccess($requestId);
                if ($verifyResp !== null) {
                    $this->finalizeSuccessfulOrder($order, $requestId, $resp, $verifyResp);

                    DB::commit();
                    return $this->respondResult(
                        request: $request,
                        routeName: 'vtu.premium-apps',
                        ok: true,
                        message: 'Premium app purchase successful (verified)!',
                        extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
                    );
                }

                $realMessage = $this->userFacingProviderFailureMessage($this->gsubz->message($resp), $resp);
                $this->markFailedAndRefund($order, $wallet, $payableKobo, $requestId, $realMessage, $resp);

                DB::commit();
                return $this->respondResult(
                    request: $request,
                    routeName: 'vtu.premium-apps',
                    ok: false,
                    message: $realMessage,
                    extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet)),
                    status: 400
                );
            }

            $order->status = 'success';
            $order->save();
            $this->awardReferralCommission($order);
            DB::commit();
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.premium-apps',
                ok: true,
                message: 'Premium app purchase successful (Mock Mode)!',
                extra: array_merge(['order_id' => $order->id], $this->walletPayload($wallet))
            );
        } catch (\RuntimeException $e) {
            DB::rollBack();
            Log::warning('Premium app purchase blocked', ['error' => $e->getMessage()]);
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.premium-apps',
                ok: false,
                message: $this->userFacingRuntimeFailureMessage($e),
                extra: $this->walletPayload($wallet),
                status: 402
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Premium app purchase error', ['error' => $e->getMessage()]);
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.premium-apps',
                ok: false,
                message: $this->buildErrorMessage(4),
                extra: $this->walletPayload($wallet),
                status: 500
            );
        }
    }

    // =========================================================
    // NIN SERVICES
    // =========================================================
    public function ninForm()
    {
        return view('vtu.nin-services');
    }

    public function ninValidationForm()
    {
        $user = auth()->user();
        $reports = Order::query()
            ->where('user_id', $user->id)
            ->where('meta->type', 'nin_validation')
            ->latest()
            ->take(100)
            ->get();

        return view('vtu.nin-validation', compact('reports'));
    }

    public function ninValidationSubmit(Request $request)
    {
        $payload = $request->validate([
            'validation_type' => ['required', Rule::in(['no_record', 'update_record'])],
            'nin' => ['required', 'digits:11'],
        ]);

        $user = auth()->user();
        $wallet = $this->requireWallet($user->wallet);
        $validationType = (string) $payload['validation_type'];

        $basePriceNaira = match ($validationType) {
            'update_record' => (float) setting('price_nin_validation_update_record', 1500),
            default => (float) setting('price_nin_validation_no_record', 1000),
        };
        $markupNaira = (float) setting('markup_nin_validation', 0);
        $totalNaira = $basePriceNaira + $markupNaira;
        $totalKobo = $this->toKobo($totalNaira);
        [$discountPercent, $discountKobo, $payableKobo] = $this->applyDiscount($totalKobo, $user);
        $profitKobo = $this->toKobo($markupNaira);
        $requestId = $this->makeRequestId('NINVAL');

        DB::beginTransaction();
        try {
            $this->debitWallet(
                wallet: $wallet,
                amountKobo: $payableKobo,
                reference: $requestId,
                description: 'NIN validation - ' . $payload['nin']
            );

            $resolvedServiceId = $this->resolveServiceId('nin_validation', 'nin_validation');
            $order = Order::create([
                'user_id' => $user->id,
                'service_id' => $resolvedServiceId,
                'customer_ref' => (string) $payload['nin'],
                'amount' => $payableKobo,
                'profit' => $profitKobo,
                'provider' => 'nin_api',
                'provider_reference' => $requestId,
                'status' => 'pending',
                'meta' => [
                    'type' => 'nin_validation',
                    'validation_type' => $validationType,
                    'base_amount_naira' => $basePriceNaira,
                    'markup_naira' => $markupNaira,
                    'total_amount_naira' => $totalNaira,
                    'discount_percent' => $discountPercent,
                    'discount_kobo' => $discountKobo,
                    'requestID' => $requestId,
                ],
            ]);

            $providerPayload = [
                'nin' => (string) $payload['nin'],
                'validation_type' => $validationType,
                'type' => $validationType,
                'category' => $validationType,
            ];
            $resp = $this->ninApi->submitValidation($providerPayload);
            $ok = $this->ninApi->isSuccessful($resp);
            $message = $this->ninApi->message($resp);

            if ($ok) {
                $order->status = 'success';
                $order->meta = array_merge($order->meta ?? [], [
                    'provider_response' => $resp,
                    'message' => $message,
                ]);
                $order->save();
                $this->awardReferralCommission($order);

                DB::commit();
                return back()->with('success', 'NIN validation submitted successfully.');
            }

            // Keep pending workflow if provider is asynchronous or endpoint is not configured yet.
            $order->status = 'pending';
            $order->meta = array_merge($order->meta ?? [], [
                'provider_response' => $resp,
                'message' => $message,
            ]);
            $order->save();
            DB::commit();

            return back()->with('success', 'NIN validation queued. ' . $message);
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return back()->with('error', $this->userFacingRuntimeFailureMessage($e));
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('NIN validation submission error', ['error' => $e->getMessage()]);
            return back()->with('error', $this->buildErrorMessage(4));
        }
    }

    public function ninSearch(Request $request)
    {
        $requestedType = strtolower(trim((string) $request->input('search_type', $request->input('verification_type', ''))));
        $searchType = match ($requestedType) {
            'by_nin' => 'nin',
            'by_phone' => 'phone',
            'by_demo' => 'demo',
            default => $requestedType,
        };

        if (!in_array($searchType, ['nin', 'phone', 'demo'], true)) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid verification type selected.',
                'data' => [],
            ], 422);
        }

        $requestId = $this->makeRequestId('NINV');
        $priceVerifyNaira = (float) setting('price_nin_verify', 180);
        $user = auth()->user();
        $wallet = $this->requireWallet($user->wallet);
        $priceVerifyKobo = $this->toKobo($priceVerifyNaira);

        if (((int) $wallet->balance) < $priceVerifyKobo) {
            return response()->json([
                'ok' => false,
                'message' => $this->buildErrorMessage(1),
                'balance_kobo' => (int) $wallet->balance,
                'data' => [],
            ], 402);
        }

        if ($searchType === 'nin') {
            $payload = $request->validate([
                'nin' => ['required', 'digits:11'],
            ]);

            $response = $this->ninApi->searchByNin((string) $payload['nin']);
        } elseif ($searchType === 'phone') {
            $payload = $request->validate([
                'phone' => ['required', 'digits_between:10,14'],
            ]);

            $response = $this->ninApi->searchByPhone((string) $payload['phone']);
        } else {
            $payload = $request->validate([
                'firstname' => ['required', 'string', 'max:120'],
                'lastname' => ['required', 'string', 'max:120'],
                'dob' => ['required', 'date_format:d-m-Y'],
                'gender' => ['required', Rule::in(['male', 'female', 'm', 'f'])],
            ]);

            $response = $this->ninApi->searchByDemography(
                (string) $payload['firstname'],
                (string) $payload['lastname'],
                (string) $payload['dob'],
                (string) $payload['gender'],
            );
        }

        $ok = $this->ninApi->isSuccessful($response);
        $message = $this->ninApi->message($response);
        $providerData = is_array($response['data'] ?? null) ? $response['data'] : [];
        $normalized = $this->normalizeNinPayload($providerData);
        $orderId = null;

        if ($ok) {
            DB::beginTransaction();
            try {
                $this->debitWallet(
                    wallet: $wallet,
                    amountKobo: $priceVerifyKobo,
                    reference: $requestId,
                    description: 'NIN verification - ' . ($normalized['nin'] ?? $searchType)
                );

                $resolvedServiceId = $this->resolveServiceId('nin_verify', 'nin');
                $order = Order::create([
                    'user_id' => $user->id,
                    'service_id' => $resolvedServiceId,
                    'customer_ref' => (string) ($normalized['nin'] ?? $searchType),
                    'amount' => $priceVerifyKobo,
                    'profit' => 0,
                    'provider' => 'nin_api',
                    'provider_reference' => $requestId,
                    'status' => 'success',
                'meta' => [
                    'type' => 'nin',
                    'service_type' => 'verify',
                    'verification_type' => $searchType,
                    'base_amount_naira' => $priceVerifyNaira,
                    'requestID' => $requestId,
                    'normalized' => $normalized,
                    'provider_response' => $response,
                    'message' => $message,
                ],
            ]);
                $this->awardReferralCommission($order);
                $orderId = $order->id;
                DB::commit();
            } catch (\RuntimeException $e) {
                DB::rollBack();
                return response()->json([
                    'ok' => false,
                    'message' => $this->userFacingRuntimeFailureMessage($e),
                    'data' => [],
                ], 402);
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error('NIN verification order error', ['error' => $e->getMessage()]);
                return response()->json([
                    'ok' => false,
                    'message' => $this->buildErrorMessage(4),
                    'data' => [],
                ], 500);
            }
        }

        return response()->json([
            'ok' => $ok,
            'message' => $message,
            'data' => $providerData,
            'normalized' => $normalized,
            'order_id' => $orderId,
            'balance_kobo' => (int) ($wallet->fresh()?->balance ?? $wallet->balance ?? 0),
            'raw' => $ok ? null : $response,
        ], $ok ? 200 : 422);
    }

    public function ninPrint(Request $request)
    {
        $validated = $request->validate([
            'slip_type' => ['required', Rule::in(['long_slip', 'standard_slip', 'premium_slip', 'vnin_slip'])],
            'verification_order_id' => ['nullable', 'integer', 'min:1'],
            'verification_type' => ['nullable', Rule::in(['by_nin', 'by_phone', 'by_demo'])],
        ]);

        $verificationOrderId = (int) ($validated['verification_order_id'] ?? 0);
        $verificationType = (string) ($validated['verification_type'] ?? '');
        $slipType = (string) $validated['slip_type'];
        $priceMapNaira = [
            'long_slip' => (float) setting('price_nin_slip_long', 180),
            'standard_slip' => (float) setting('price_nin_slip_standard', 180),
            'premium_slip' => (float) setting('price_nin_slip_premium', 180),
            'vnin_slip' => (float) setting('price_nin_slip_vnin', 180),
        ];
        $basePriceNaira = (float) ($priceMapNaira[$slipType] ?? 180);
        $markupNaira = (float) setting('markup_nin_print', 0);
        $totalNaira = $basePriceNaira + $markupNaira;
        $totalKobo = $this->toKobo($totalNaira);

        $user = auth()->user();
        $wallet = $this->requireWallet($user->wallet);
        if (((int) $wallet->balance) < $totalKobo) {
            return response()->json([
                'ok' => false,
                'message' => $this->buildErrorMessage(1),
                'data' => [],
                'balance_kobo' => (int) $wallet->balance,
            ], 402);
        }

        $providerPayload = [];
        $providerData = [];
        $normalized = [];
        $response = [];
        $ok = false;
        $message = '';
        $sourceVerificationOrderId = null;

        if ($verificationOrderId > 0) {
            try {
                $verified = $this->verifiedNinPrintDataFromOrder($verificationOrderId, $user);
            } catch (\RuntimeException $e) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                    'data' => [],
                    'balance_kobo' => (int) $wallet->balance,
                ], 422);
            }

            $verificationType = (string) $verified['verification_type'];
            $providerData = $verified['provider_data'];
            $normalized = $verified['normalized'];
            $sourceVerificationOrderId = (int) $verified['order']->id;

            $printableNin = trim((string) ($normalized['nin'] ?? ''));
            if ($printableNin === '' || $printableNin === '-') {
                return response()->json([
                    'ok' => false,
                    'message' => 'This verified record does not contain a printable NIN.',
                    'data' => [],
                    'balance_kobo' => (int) $wallet->balance,
                ], 422);
            }

            $providerPayload = [
                'service_type' => 'print',
                'slip_type' => $slipType,
                'verification_type' => $verificationType,
                'nin' => $printableNin,
            ];

            $message = 'Slip generated successfully. Use print or save as PDF.';
            $response = [
                'success' => true,
                'message' => $message,
                'data' => [
                    'local_generated' => true,
                    'slip_type' => $slipType,
                    'normalized' => $normalized,
                    'provider_data' => $providerData,
                    'source_verification_order_id' => $sourceVerificationOrderId,
                ],
            ];
            $ok = true;
        } else {
            if ($verificationType === '') {
                return response()->json([
                    'ok' => false,
                    'message' => 'Choose a verification type before printing.',
                    'data' => [],
                    'balance_kobo' => (int) $wallet->balance,
                ], 422);
            }

            $basePayload = [
                'service_type' => 'print',
                'slip_type' => $slipType,
                'verification_type' => $verificationType,
            ];

            if ($verificationType === 'by_nin') {
                $ninPayload = $request->validate([
                    'nin' => ['required', 'digits:11'],
                ]);
                $providerPayload = array_merge($basePayload, [
                    'nin' => (string) $ninPayload['nin'],
                ]);
            } elseif ($verificationType === 'by_phone') {
                $phonePayload = $request->validate([
                    'phone' => ['required', 'digits_between:10,14'],
                ]);
                $providerPayload = array_merge($basePayload, [
                    'phone' => (string) $phonePayload['phone'],
                ]);
            } else {
                $demoPayload = $request->validate([
                    'firstname' => ['required', 'string', 'max:120'],
                    'lastname' => ['required', 'string', 'max:120'],
                    'dob' => ['required', 'date_format:d-m-Y'],
                    'gender' => ['required', Rule::in(['male', 'female', 'm', 'f'])],
                ]);

                $providerPayload = array_merge($basePayload, [
                    // Keep both key styles for compatibility with providers that use either format.
                    'firstname' => (string) $demoPayload['firstname'],
                    'lastname' => (string) $demoPayload['lastname'],
                    'dob' => (string) $demoPayload['dob'],
                    'gender' => (string) $demoPayload['gender'],
                    'fname' => (string) $demoPayload['firstname'],
                    'lname' => (string) $demoPayload['lastname'],
                ]);
            }

            $response = $this->ninApi->printSlip($providerPayload);
            $ok = $this->ninApi->isSuccessful($response);
            $message = $this->ninApi->message($response);
            $providerData = is_array($response['data'] ?? null) ? $response['data'] : [];
            $normalized = $this->normalizeNinPayload($providerData);
        }

        $orderId = null;
        $issuedAt = now();

        if ($ok) {
            $requestId = $this->makeRequestId('NINP');
            DB::beginTransaction();
            try {
                $this->debitWallet(
                    wallet: $wallet,
                    amountKobo: $totalKobo,
                    reference: $requestId,
                    description: 'NIN slip print - ' . strtoupper(str_replace('_', ' ', $slipType))
                );

                $resolvedServiceId = $this->resolveServiceId('nin_print', 'nin');
                $order = Order::create([
                    'user_id' => $user->id,
                    'service_id' => $resolvedServiceId,
                    'customer_ref' => (string) ($providerPayload['nin'] ?? $providerPayload['phone'] ?? 'NIN Print'),
                    'amount' => $totalKobo,
                    'profit' => $this->toKobo($markupNaira),
                    'provider' => 'nin_api',
                    'provider_reference' => $requestId,
                    'status' => 'success',
                    'meta' => [
                        'type' => 'nin',
                        'service_type' => 'print',
                        'slip_type' => $slipType,
                        'verification_type' => $verificationType,
                        'base_amount_naira' => $basePriceNaira,
                        'markup_naira' => $markupNaira,
                        'requestID' => $requestId,
                        'source_verification_order_id' => $sourceVerificationOrderId,
                        'normalized' => $normalized,
                        'provider_data' => $providerData,
                        'provider_response' => $response,
                        'message' => $message,
                    ],
                ]);
                $this->awardReferralCommission($order);
                $orderId = $order->id;
                $issuedAt = $order->created_at ?? $issuedAt;
                DB::commit();
            } catch (\RuntimeException $e) {
                DB::rollBack();
                return response()->json([
                    'ok' => false,
                    'message' => $this->userFacingRuntimeFailureMessage($e),
                    'data' => [],
                ], 402);
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error('NIN print order error', ['error' => $e->getMessage()]);
                return response()->json([
                    'ok' => false,
                    'message' => $this->buildErrorMessage(4),
                    'data' => [],
                ], 500);
            }
        }

        $responseData = is_array($response['data'] ?? null) ? $response['data'] : [];

        return response()->json([
            'ok' => $ok,
            'message' => $message,
            'data' => array_merge($responseData, [
                'slip_type' => $slipType,
                'normalized' => $normalized,
                'provider_data' => $providerData,
                'issued_at' => $issuedAt->toIso8601String(),
                'issued_at_label' => $issuedAt->format('d M Y'),
                'source_verification_order_id' => $sourceVerificationOrderId,
            ]),
            'order_id' => $orderId,
            'balance_kobo' => (int) ($wallet->fresh()?->balance ?? $wallet->balance ?? 0),
            'raw' => $response,
        ], $ok ? 200 : 422);
    }

    private function verifiedNinPrintDataFromOrder(int $orderId, User $user): array
    {
        $order = Order::query()
            ->where('id', $orderId)
            ->where('user_id', $user->id)
            ->where('status', 'success')
            ->first();

        if (!$order) {
            throw new \RuntimeException('Verified NIN record not found.');
        }

        $meta = is_array($order->meta) ? $order->meta : [];
        if (($meta['type'] ?? null) !== 'nin' || ($meta['service_type'] ?? null) !== 'verify') {
            throw new \RuntimeException('The selected record is not a verified NIN result.');
        }

        $providerResponse = is_array($meta['provider_response'] ?? null) ? $meta['provider_response'] : [];
        $providerData = is_array($providerResponse['data'] ?? null) ? $providerResponse['data'] : [];
        $normalized = is_array($meta['normalized'] ?? null) ? $meta['normalized'] : [];

        if (empty($normalized)) {
            $normalized = $this->normalizeNinPayload($providerData);
        }

        if (empty($providerData) && empty($normalized)) {
            throw new \RuntimeException('Verified NIN data is no longer available for printing.');
        }

        $verificationType = match ((string) ($meta['verification_type'] ?? 'nin')) {
            'phone' => 'by_phone',
            'demo' => 'by_demo',
            'by_phone' => 'by_phone',
            'by_demo' => 'by_demo',
            default => 'by_nin',
        };

        return [
            'order' => $order,
            'provider_data' => $providerData,
            'normalized' => $normalized,
            'verification_type' => $verificationType,
        ];
    }

    public function ninSlipReports()
    {
        $response = $this->ninApi->slipReports();
        $ok = $this->ninApi->isSuccessful($response);
        $message = $this->ninApi->message($response);
        $data = $response['data'] ?? [];

        if (!is_array($data)) {
            $data = [];
        }

        return response()->json([
            'ok' => $ok,
            'message' => $message,
            'data' => $data,
            'raw' => $ok ? null : $response,
        ], $ok ? 200 : 422);
    }

    // =========================================================
    // BVN SERVICES
    // =========================================================
    public function bvnForm()
    {
        return view('vtu.bvn-services');
    }

    public function bvnVerify(Request $request)
    {
        $payload = $request->validate([
            'bvn' => ['required', 'digits:11'],
        ]);

        $user = auth()->user();
        $wallet = $this->requireWallet($user->wallet);

        $basePriceNaira = (float) setting('price_bvn_verify', 100);
        $markupNaira = (float) setting('markup_bvn', 0);
        $totalNaira = $basePriceNaira + $markupNaira;
        $totalKobo = $this->toKobo($totalNaira);

        if (((int) $wallet->balance) < $totalKobo) {
            return response()->json([
                'ok' => false,
                'message' => $this->buildErrorMessage(1),
                'data' => [],
                'balance_kobo' => (int) $wallet->balance,
            ], 402);
        }

        $response = $this->bvnApi->verify((string) $payload['bvn']);
        $ok = $this->bvnApi->isSuccessful($response);
        $message = $this->bvnApi->message($response);
        $providerData = is_array($response['data'] ?? null) ? $response['data'] : [];
        $normalized = $this->normalizeBvnPayload($providerData);

        if (!$ok) {
            return response()->json([
                'ok' => false,
                'message' => $message,
                'data' => $providerData,
                'normalized' => $normalized,
                'raw' => $response,
            ], 422);
        }

        $requestId = $this->makeRequestId('BVNV');
        DB::beginTransaction();
        try {
            $this->debitWallet(
                wallet: $wallet,
                amountKobo: $totalKobo,
                reference: $requestId,
                description: 'BVN verification - ' . (string) $payload['bvn']
            );

            $resolvedServiceId = $this->resolveServiceId('bvn_verify', 'bvn');
            $order = Order::create([
                'user_id' => $user->id,
                'service_id' => $resolvedServiceId,
                'customer_ref' => (string) ($normalized['bvn'] ?? $payload['bvn']),
                'amount' => $totalKobo,
                'profit' => $this->toKobo($markupNaira),
                'provider' => 'bvn_api',
                'provider_reference' => $requestId,
                'status' => 'success',
                'meta' => [
                    'type' => 'bvn',
                    'service_type' => 'verify',
                    'base_amount_naira' => $basePriceNaira,
                    'markup_naira' => $markupNaira,
                    'requestID' => $requestId,
                    'provider_response' => $response,
                    'message' => $message,
                ],
            ]);
            $this->awardReferralCommission($order);
            DB::commit();

            return response()->json([
                'ok' => true,
                'message' => $message !== '' ? $message : 'BVN verified successfully.',
                'data' => $providerData,
                'normalized' => $normalized,
                'order_id' => $order->id,
                'balance_kobo' => (int) ($wallet->fresh()?->balance ?? $wallet->balance ?? 0),
                'raw' => $response,
            ]);
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json([
                'ok' => false,
                'message' => $this->userFacingRuntimeFailureMessage($e),
                'data' => [],
            ], 402);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('BVN verify order error', ['error' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => $this->buildErrorMessage(4),
                'data' => [],
            ], 500);
        }
    }

    public function bvnRetrieve(Request $request)
    {
        $payload = $request->validate([
            'retrieve_type' => ['required', Rule::in(['phone', 'bms'])],
            'phone' => ['nullable', 'digits_between:10,14'],
            'bms_no' => ['nullable', 'string', 'max:100'],
            'ticket_id' => ['nullable', 'string', 'max:120'],
            'agent_code' => ['nullable', 'string', 'max:80'],
        ]);

        $retrieveType = (string) $payload['retrieve_type'];
        if ($retrieveType === 'phone' && empty($payload['phone'])) {
            return response()->json([
                'ok' => false,
                'message' => 'Phone number is required for phone retrieval.',
                'data' => [],
            ], 422);
        }
        if ($retrieveType === 'bms' && (empty($payload['bms_no']) || empty($payload['ticket_id']))) {
            return response()->json([
                'ok' => false,
                'message' => 'BMS ticket and ticket ID are required.',
                'data' => [],
            ], 422);
        }

        $user = auth()->user();
        $wallet = $this->requireWallet($user->wallet);
        $basePriceNaira = $retrieveType === 'phone'
            ? (float) setting('price_bvn_retrieve_phone', 2500)
            : (float) setting('price_bvn_retrieve_bms', 1000);
        $markupNaira = (float) setting('markup_bvn', 0);
        $totalNaira = $basePriceNaira + $markupNaira;
        $totalKobo = $this->toKobo($totalNaira);
        $requestId = $this->makeRequestId('BVNR');

        DB::beginTransaction();
        try {
            $this->debitWallet(
                wallet: $wallet,
                amountKobo: $totalKobo,
                reference: $requestId,
                description: 'BVN retrieval - ' . strtoupper($retrieveType)
            );

            $resolvedServiceId = $this->resolveServiceId('bvn_retrieve', 'bvn');
            $customerRef = $retrieveType === 'phone'
                ? (string) $payload['phone']
                : (string) ($payload['bms_no'] ?? '');

            $order = Order::create([
                'user_id' => $user->id,
                'service_id' => $resolvedServiceId,
                'customer_ref' => $customerRef,
                'amount' => $totalKobo,
                'profit' => $this->toKobo($markupNaira),
                'provider' => 'bvn_api',
                'provider_reference' => $requestId,
                'status' => 'pending',
                'meta' => [
                    'type' => 'bvn',
                    'service_type' => 'retrieve',
                    'retrieve_type' => $retrieveType,
                    'phone' => $payload['phone'] ?? null,
                    'bms_no' => $payload['bms_no'] ?? null,
                    'ticket_id' => $payload['ticket_id'] ?? null,
                    'agent_code' => $payload['agent_code'] ?? null,
                    'base_amount_naira' => $basePriceNaira,
                    'markup_naira' => $markupNaira,
                    'requestID' => $requestId,
                ],
            ]);

            $response = $retrieveType === 'phone'
                ? $this->bvnApi->retrieveByPhone((string) $payload['phone'])
                : $this->bvnApi->retrieveByBms(
                    (string) ($payload['bms_no'] ?? ''),
                    (string) ($payload['ticket_id'] ?? ''),
                    (string) ($payload['agent_code'] ?? '')
                );

            $ok = $this->bvnApi->isSuccessful($response);
            $message = $this->bvnApi->message($response);
            $providerData = is_array($response['data'] ?? null) ? $response['data'] : [];
            $normalized = $this->normalizeBvnPayload($providerData);

            if ($ok) {
                $order->status = 'success';
                $order->meta = array_merge($order->meta ?? [], [
                    'provider_response' => $response,
                    'message' => $message,
                ]);
                $order->save();
                $this->awardReferralCommission($order);
            } else {
                $order->status = 'pending';
                $order->meta = array_merge($order->meta ?? [], [
                    'provider_response' => $response,
                    'message' => $message,
                ]);
                $order->save();
            }

            DB::commit();

            return response()->json([
                'ok' => $ok,
                'message' => $ok
                    ? ($message !== '' ? $message : 'BVN retrieval request completed.')
                    : 'BVN retrieval submitted. ' . $message,
                'data' => $providerData,
                'normalized' => $normalized,
                'order_id' => $order->id,
                'balance_kobo' => (int) ($wallet->fresh()?->balance ?? $wallet->balance ?? 0),
                'raw' => $response,
            ], $ok ? 200 : 202);
        } catch (\RuntimeException $e) {
            DB::rollBack();
            return response()->json([
                'ok' => false,
                'message' => $this->userFacingRuntimeFailureMessage($e),
                'data' => [],
            ], 402);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('BVN retrieve workflow error', ['error' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => $this->buildErrorMessage(4),
                'data' => [],
            ], 500);
        }
    }

    // =========================================================
    // TRANSACTIONS + ORDERS
    // =========================================================
    public function transactions()
    {
        $user = auth()->user();
        $orders = Order::where('user_id', $user->id)->latest()->paginate(20);
        return view('vtu.transactions', compact('orders'));
    }

    public function orders()
    {
        $user = auth()->user();
        $orders = Order::where('user_id', $user->id)->latest()->paginate(20);
        $orderBalanceMap = $this->buildOrderBalanceMap($orders->getCollection(), $user?->wallet);

        return view('vtu.orders', compact('orders', 'orderBalanceMap'));
    }

    public function profitCalculator(Request $request)
    {
        $user = auth()->user();
        $query = Order::query()
            ->where('user_id', $user->id)
            ->where('status', 'success');

        $period = strtolower(trim((string) $request->input('period', 'today')));
        $date = trim((string) $request->input('date', ''));
        $from = trim((string) $request->input('from', ''));
        $to = trim((string) $request->input('to', ''));

        if ($period === 'month') {
            $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]);
        } elseif ($period === 'date' && $date !== '') {
            $query->whereDate('created_at', $date);
        } elseif ($period === 'range' && $from !== '' && $to !== '') {
            $fromDate = Carbon::parse($from)->startOfDay();
            $toDate = Carbon::parse($to)->endOfDay();
            if ($fromDate->lessThanOrEqualTo($toDate)) {
                $query->whereBetween('created_at', [$fromDate, $toDate]);
            }
        } else {
            $period = 'today';
            $query->whereDate('created_at', now()->toDateString());
        }

        $summary = [
            'orders' => (clone $query)->count(),
            'spent' => (int) (clone $query)->sum('amount'),
            'profit' => (int) (clone $query)->sum('profit'),
        ];

        $filters = [
            'period' => $period,
            'date' => $date,
            'from' => $from,
            'to' => $to,
        ];

        return view('vtu.profit-calculator', compact('summary', 'filters'));
    }

    public function markUserNotificationsRead(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $user->unreadNotifications->markAsRead();
        }

        return back()->with('success', 'Notifications marked as read.');
    }

    public function notificationsIndex(Request $request)
    {
        $user = $request->user();
        $notifications = collect();
        $unreadCount = 0;

        if ($user && Schema::hasTable('notifications')) {
            $unreadCount = $user->unreadNotifications()->count();
            $notifications = $user->notifications()->latest()->paginate(40);
        }

        return view('notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }

    // =========================================================
    // RECEIPT
    // =========================================================
    public function receipt(int $id)
    {
        $user = auth()->user();

        $order = Order::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        [$balanceBeforeKobo, $balanceAfterKobo] = $this->resolveOrderBalances($order, $user?->wallet);

        return view('vtu.receipt', compact('order', 'balanceBeforeKobo', 'balanceAfterKobo'));
    }

    // =========================================================
    // AJAX: GSUBZ Plans (for Data/Cable dropdowns)
    // =========================================================
    public function gsubzPlans(Request $request)
    {
        $serviceId = (string) $request->query('service', '');
        if (trim($serviceId) === '') {
            return response()->json([
                'ok'      => false,
                'plans'   => [],
                'message' => 'Missing service id.',
            ], 422);
        }

        $providerServiceId = $this->providerServiceId($serviceId);
        $resp = $this->gsubz->plans($providerServiceId);

        return response()->json([
            'ok'      => (bool) ($resp['ok'] ?? false),
            'plans'   => $resp['plans'] ?? [],
            'message' => $resp['message'] ?? null,
            'raw'     => $resp['raw'] ?? null,
            'service' => $providerServiceId,
        ]);
    }

    /**
     * @return array<int, array{phone:string,count:int,label:string,last_used_at:?string}>
     */
    private function phoneSuggestionsForUser(?User $user, string $type, int $limit = 8): array
    {
        if (!$user) {
            return [];
        }

        $orders = Order::query()
            ->where('user_id', $user->id)
            ->where('meta->type', $type)
            ->whereNotNull('customer_ref')
            ->where('customer_ref', '!=', '')
            ->latest()
            ->take(150)
            ->get(['customer_ref', 'created_at']);

        $stats = [];
        foreach ($orders as $order) {
            $phone = $this->normalizePhone((string) $order->customer_ref);
            if (!$this->isValidPhone($phone)) {
                continue;
            }

            $lastUsedAt = $order->created_at?->toISOString();
            if (!isset($stats[$phone])) {
                $stats[$phone] = [
                    'phone' => $phone,
                    'count' => 0,
                    'last_used_at' => $lastUsedAt,
                ];
            }

            $stats[$phone]['count']++;
            if (
                $lastUsedAt !== null
                && (
                    $stats[$phone]['last_used_at'] === null
                    || $lastUsedAt > $stats[$phone]['last_used_at']
                )
            ) {
                $stats[$phone]['last_used_at'] = $lastUsedAt;
            }
        }

        $suggestions = array_values($stats);
        usort($suggestions, function (array $left, array $right): int {
            if ($left['count'] !== $right['count']) {
                return $right['count'] <=> $left['count'];
            }

            return strcmp((string) ($right['last_used_at'] ?? ''), (string) ($left['last_used_at'] ?? ''));
        });

        $suggestions = array_slice($suggestions, 0, $limit);

        return array_map(function (array $item): array {
            $suffix = $item['count'] > 1 ? 'Used ' . $item['count'] . ' times' : 'Recently used';

            return [
                'phone' => $item['phone'],
                'count' => $item['count'],
                'label' => $item['phone'] . ' - ' . $suffix,
                'last_used_at' => $item['last_used_at'],
            ];
        }, $suggestions);
    }

    public function gsubzSocialPlans()
    {
        $resp = $this->gsubz->socialPlans();

        return response()->json([
            'ok' => (bool) ($resp['ok'] ?? false),
            'plans' => $resp['plans'] ?? [],
            'message' => $resp['message'] ?? null,
            'raw' => $resp['raw'] ?? null,
        ]);
    }

    // =========================================================
    // HELPERS
    // =========================================================
    private function respondResult(
        Request $request,
        string $routeName,
        bool $ok,
        string $message,
        array $extra = [],
        int $status = 200
    ) {
        if ($request->expectsJson()) {
            return response()->json(
                array_merge(['ok' => $ok, 'message' => $message], $extra),
                $status
            );
        }

        return redirect()
            ->route($routeName)
            ->with($ok ? 'success' : 'error', $message);
    }

    private function walletPayload(Wallet $wallet): array
    {
        $fresh = $wallet->fresh();
        return [
            'balance_kobo' => (int) ($fresh?->balance ?? 0),
        ];
    }

    private function requireWallet(?Wallet $wallet): Wallet
    {
        if (!$wallet) {
            abort(403, 'Wallet not found.');
        }
        return $wallet;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '234') && strlen($digits) === 13) {
            $digits = '0' . substr($digits, 3);
        }
        return $digits;
    }

    private function isValidPhone(string $phone): bool
    {
        return (bool) preg_match('/^0\d{10}$/', $phone);
    }

    private function toKobo(float $naira): int
    {
        return (int) round($naira * 100);
    }

    private function makeRequestId(string $prefix): string
    {
        return strtoupper($prefix) . '-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
    }

    private function resolveServiceId(string $serviceSlug, string $fallback): int
    {
        $primarySlug = str_replace(' ', '_', strtolower(trim($serviceSlug)));
        $fallbackSlug = str_replace(' ', '_', strtolower(trim($fallback)));

        if ($primarySlug !== '') {
            $service = Service::where('slug', $primarySlug)->first();
            if ($service) {
                return (int) $service->id;
            }
        }

        if ($fallbackSlug !== '') {
            $fallbackService = Service::where('slug', $fallbackSlug)->first();
            if ($fallbackService) {
                return (int) $fallbackService->id;
            }
        }

        // Guarantee a valid FK target instead of returning 0.
        $slug = $primarySlug !== '' ? $primarySlug : ($fallbackSlug !== '' ? $fallbackSlug : 'unknown-service');
        $name = (string) Str::of($slug)->replace(['_', '-'], ' ')->title();

        $service = Service::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name !== '' ? $name : 'Unknown Service']
        );

        return (int) $service->id;
    }

    private function debitWallet(Wallet $wallet, int $amountKobo, string $reference, string $description): void
    {
        $beforeBalanceKobo = (int) ($wallet->balance ?? 0);
        if ($beforeBalanceKobo < $amountKobo) {
            throw new \RuntimeException('Insufficient wallet balance.');
        }

        $afterBalanceKobo = $beforeBalanceKobo - $amountKobo;
        $wallet->balance = $afterBalanceKobo;
        $wallet->save();

        WalletTransaction::create([
            'wallet_id'    => $wallet->id,
            'type'         => 'debit',
            'amount'       => $amountKobo,
            'reference'    => $reference,
            'status'       => 'success',
            'channel'      => 'purchase',
            'description'  => $description,
            'meta'         => [
                'balance_before_kobo' => $beforeBalanceKobo,
                'balance_after_kobo' => $afterBalanceKobo,
            ],
        ]);

        $this->notifyWalletOwner(
            wallet: $wallet,
            title: 'Wallet Debited',
            message: 'N' . number_format($amountKobo / 100, 2) . ' debited for transaction: ' . $description,
            payload: [
                'type' => 'debit',
                'amount_kobo' => $amountKobo,
                'reference' => $reference,
                'channel' => 'purchase',
                'balance_before_kobo' => $beforeBalanceKobo,
                'balance_after_kobo' => $afterBalanceKobo,
            ]
        );
    }

    private function creditWallet(Wallet $wallet, int $amountKobo, string $reference, string $description): void
    {
        $beforeBalanceKobo = (int) ($wallet->balance ?? 0);
        $afterBalanceKobo = $beforeBalanceKobo + $amountKobo;
        $wallet->balance = $afterBalanceKobo;
        $wallet->save();

        WalletTransaction::create([
            'wallet_id'    => $wallet->id,
            'type'         => 'credit',
            'amount'       => $amountKobo,
            'reference'    => $reference,
            'status'       => 'success',
            'channel'      => 'refund',
            'description'  => $description,
            'meta'         => [
                'balance_before_kobo' => $beforeBalanceKobo,
                'balance_after_kobo' => $afterBalanceKobo,
            ],
        ]);

        $this->notifyWalletOwner(
            wallet: $wallet,
            title: 'Wallet Credited',
            message: 'N' . number_format($amountKobo / 100, 2) . ' credited: ' . $description,
            payload: [
                'type' => 'credit',
                'amount_kobo' => $amountKobo,
                'reference' => $reference,
                'channel' => 'refund',
                'balance_before_kobo' => $beforeBalanceKobo,
                'balance_after_kobo' => $afterBalanceKobo,
            ]
        );
    }

    private function notifyWalletOwner(Wallet $wallet, string $title, string $message, array $payload = []): void
    {
        try {
            $user = $wallet->user()->first();
            if (!$user) {
                return;
            }

            $user->notify(new UserWalletActivityNotification($title, $message, $payload));
        } catch (\Throwable $e) {
            Log::warning('Wallet notification failed.', [
                'wallet_id' => $wallet->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveOrderBalances(Order $order, ?Wallet $wallet): array
    {
        $meta = $order->meta ?? [];
        $balanceBeforeKobo = $this->asIntOrNull($meta['wallet_balance_before_kobo'] ?? null);
        $balanceAfterKobo = $this->asIntOrNull($meta['wallet_balance_after_kobo'] ?? null);

        if (!$wallet) {
            return [$balanceBeforeKobo, $balanceAfterKobo];
        }

        $requestId = trim((string) ($meta['requestID'] ?? $order->provider_reference ?? ''));
        if ($requestId === '') {
            return [$balanceBeforeKobo, $balanceAfterKobo];
        }

        $purchaseTx = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('reference', $requestId)
            ->where('type', 'debit')
            ->orderByDesc('id')
            ->first();

        if (!$purchaseTx) {
            return [$balanceBeforeKobo, $balanceAfterKobo];
        }

        $txMeta = is_array($purchaseTx->meta) ? $purchaseTx->meta : [];
        $balanceBeforeKobo ??= $this->asIntOrNull($txMeta['balance_before_kobo'] ?? null);
        $balanceAfterKobo ??= $this->asIntOrNull($txMeta['balance_after_kobo'] ?? null);

        if ($balanceAfterKobo === null) {
            $balanceAfterKobo = $this->inferBalanceAfterTransaction($wallet, $purchaseTx);
        }

        $amountKobo = (int) ($purchaseTx->amount ?? 0);
        if ($amountKobo > 0) {
            if ($balanceBeforeKobo === null && $balanceAfterKobo !== null) {
                $balanceBeforeKobo = $purchaseTx->type === 'debit'
                    ? $balanceAfterKobo + $amountKobo
                    : $balanceAfterKobo - $amountKobo;
            }

            if ($balanceAfterKobo === null && $balanceBeforeKobo !== null) {
                $balanceAfterKobo = $purchaseTx->type === 'debit'
                    ? $balanceBeforeKobo - $amountKobo
                    : $balanceBeforeKobo + $amountKobo;
            }
        }

        return [$balanceBeforeKobo, $balanceAfterKobo];
    }

    private function inferBalanceAfterTransaction(Wallet $wallet, WalletTransaction $transaction): ?int
    {
        $currentBalanceKobo = $this->asIntOrNull($wallet->balance);
        if ($currentBalanceKobo === null) {
            return null;
        }

        $laterDeltaKobo = (int) WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where(function ($query) use ($transaction) {
                $query->where('created_at', '>', $transaction->created_at)
                    ->orWhere(function ($inner) use ($transaction) {
                        $inner->where('created_at', $transaction->created_at)
                            ->where('id', '>', $transaction->id);
                    });
            })
            ->get(['type', 'amount'])
            ->reduce(function (int $carry, WalletTransaction $item): int {
                $amount = (int) ($item->amount ?? 0);
                if ($item->type === 'credit') {
                    return $carry + $amount;
                }

                return $carry - $amount;
            }, 0);

        return $currentBalanceKobo - $laterDeltaKobo;
    }

    private function asIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function buildOrderBalanceMap(iterable $orders, ?Wallet $wallet): array
    {
        $map = [];

        foreach ($orders as $order) {
            if (!$order instanceof Order) {
                continue;
            }

            [$beforeKobo, $afterKobo] = $this->resolveOrderBalances($order, $wallet);

            $map[(int) $order->id] = [
                'before_kobo' => $beforeKobo,
                'after_kobo' => $afterKobo,
            ];
        }

        return $map;
    }

    private function normalizeNinPayload(array $payload): array
    {
        $lookup = [];
        foreach ($payload as $key => $value) {
            $lookup[strtolower((string) $key)] = $value;
        }

        $pick = static function (array $map, array $keys, string $fallback = '-') {
            foreach ($keys as $key) {
                $normalizedKey = strtolower($key);
                if (!array_key_exists($normalizedKey, $map)) {
                    continue;
                }

                $value = $map[$normalizedKey];
                if (is_string($value)) {
                    $value = trim($value);
                }

                if ($value === '' || $value === null) {
                    continue;
                }

                return $value;
            }

            return $fallback;
        };

        $first = (string) $pick($lookup, ['first_name', 'firstname', 'firs_tname', 'pfirstname'], '-');
        $middle = (string) $pick($lookup, ['middle_name', 'middlename', 'pmiddlename'], '-');
        $last = (string) $pick($lookup, ['last_name', 'lastname', 'surname', 'psurname'], '-');

        $parts = array_values(array_filter([$first, $middle, $last], static fn ($part) => $part !== '-'));
        $fullName = !empty($parts) ? implode(' ', $parts) : '-';

        return [
            'full_name' => $fullName,
            'nin' => $pick($lookup, ['nin', 'nin_number', 'national_identification_number']),
            'tracking_id' => $pick($lookup, ['tracking_id', 'trackingid', 'trackingidno', 'trackingcode', 'tracking_code', 'centralid']),
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'gender' => $pick($lookup, ['gender']),
            'birthdate' => $pick($lookup, ['birthdate', 'date_of_birth', 'dob']),
            'phone_number' => $pick($lookup, ['phone_number', 'phone', 'telephoneno']),
            'state' => $pick($lookup, ['state', 'state_of_origin', 'self_origin_state', 'birthstate']),
            'lga' => $pick($lookup, ['lga', 'lga_of_origin', 'self_origin_lga', 'birthlga']),
            'residence_state' => $pick($lookup, ['residence_state']),
            'residence_lga' => $pick($lookup, ['residence_lga']),
            'residence_town' => $pick($lookup, ['residence_town']),
            'address_line_1' => $pick($lookup, ['residence_adressline1', 'residence_addressline1', 'residence_address', 'residence_addr']),
            'photo' => $pick($lookup, ['photo', 'image'], ''),
            'signature' => $pick($lookup, ['signature'], ''),
        ];
    }

    private function normalizeBvnPayload(array $payload): array
    {
        $lookup = [];
        foreach ($payload as $key => $value) {
            $lookup[strtolower((string) $key)] = $value;
        }

        $pick = static function (array $map, array $keys, string $fallback = '-') {
            foreach ($keys as $key) {
                $normalizedKey = strtolower($key);
                if (!array_key_exists($normalizedKey, $map)) {
                    continue;
                }

                $value = $map[$normalizedKey];
                if (is_string($value)) {
                    $value = trim($value);
                }

                if ($value === '' || $value === null) {
                    continue;
                }

                return $value;
            }

            return $fallback;
        };

        $first = (string) $pick($lookup, ['first_name', 'firstname', 'firs_tname'], '-');
        $middle = (string) $pick($lookup, ['middle_name', 'middlename'], '-');
        $last = (string) $pick($lookup, ['last_name', 'lastname', 'surname'], '-');
        $parts = array_values(array_filter([$first, $middle, $last], static fn ($part) => $part !== '-'));

        return [
            'full_name' => !empty($parts) ? implode(' ', $parts) : '-',
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'bvn' => $pick($lookup, ['bvn']),
            'nin' => $pick($lookup, ['nin']),
            'gender' => $pick($lookup, ['gender']),
            'date_of_birth' => $pick($lookup, ['date_of_birth', 'birthdate', 'dob']),
            'phone_number' => $pick($lookup, ['phone_number', 'phone']),
            'email' => $pick($lookup, ['email']),
            'state' => $pick($lookup, ['state']),
            'lga' => $pick($lookup, ['lga', 'lga_of_origin']),
            'residence' => $pick($lookup, ['residence_address', 'address', 'residence']),
            'image' => $pick($lookup, ['image', 'photo'], ''),
        ];
    }

    private function applyDiscount(int $totalKobo, $user): array
    {
        $percent = (float) ($user?->discount_percent ?? 0);

        if ($percent <= 0) {
            return [0.0, 0, $totalKobo];
        }

        if ($percent > 100) {
            $percent = 100;
        }

        $discountKobo = (int) round($totalKobo * ($percent / 100));
        $payableKobo = max(0, $totalKobo - $discountKobo);

        return [$percent, $discountKobo, $payableKobo];
    }

    private function dataServices(): array
    {
        $fallback = [
            'mtn_awoof' => 'MTN Awoof Data (Cheap)',
            'mtn_gifting' => 'MTN Data (Gifting)',
            'mtn_sme' => 'MTN Data (SME)',
            'mtn_cg' => 'MTN Data (Corporate)',
            'mtn_cg_lite' => 'MTN Data (CG Lite)',
            'mtn_coupon' => 'MTN Coupon',
            'mtncg' => 'MTN CG',
            'airtel_sme' => 'Airtel Data (SME)',
            'airtel_cg' => 'Airtel Data (CG)',
            'airtel_gifting' => 'Airtel Data (Gifting)',
            'glo_data' => 'Glo Data',
            'glo_sme' => 'Glo Data (SME)',
            'etisalat_data' => '9mobile Data',
        ];

        $displayOrder = [
            'mtn_awoof',
            'mtn_gifting',
            'mtn_sme',
            'mtn_cg',
            'mtn_cg_lite',
            'mtn_coupon',
            'mtncg',
            'airtel_sme',
            'airtel_cg',
            'airtel_gifting',
            'glo_data',
            'glo_sme',
            'etisalat_data',
        ];

        $serviceDefaultEnabled = [
            'mtn_awoof' => '1',
            'mtn_gifting' => '1',
            'mtn_sme' => '1',
            'mtn_cg' => '0',
            'mtn_cg_lite' => '0',
            'mtn_coupon' => '0',
            'mtncg' => '0',
            'airtel_sme' => '1',
            'airtel_cg' => '1',
            'airtel_gifting' => '1',
            'glo_data' => '1',
            'glo_sme' => '1',
            'etisalat_data' => '1',
        ];

        $customServices = $this->parseServicesSetting('services_data');
        $baseServices = !empty($customServices) ? $customServices : $fallback;
        $expectedSlugs = array_values(array_unique(array_merge(array_keys($fallback), array_keys($baseServices))));

        $dbServices = Service::query()
            ->whereIn('slug', $expectedSlugs)
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->toArray();

        $services = array_replace($baseServices, $dbServices);

        foreach ($serviceDefaultEnabled as $slug => $defaultEnabled) {
            $enabled = (string) setting('data_service_enabled_' . $slug, $defaultEnabled) === '1';
            if (!$enabled) {
                unset($services[$slug]);
            }
        }

        $orderedServices = [];
        foreach ($displayOrder as $slug) {
            if (!array_key_exists($slug, $services)) {
                continue;
            }
            $orderedServices[$slug] = $services[$slug];
            unset($services[$slug]);
        }

        foreach ($services as $slug => $label) {
            $orderedServices[$slug] = $label;
        }

        return $orderedServices;
    }

    private function cableServices(): array
    {
        $services = $this->parseServicesSetting('services_cable');
        if (!empty($services)) {
            return $services;
        }

        return [
            'dstv' => 'DSTV Subscription',
            'gotv' => 'GOTV Subscription',
            'startimes' => 'Startimes Subscription',
        ];
    }

    private function electricityServices(): array
    {
        $services = $this->parseServicesSetting('services_electricity');
        if (!empty($services)) {
            return $services;
        }

        return [
            'abuja-electric' => 'Abuja Electric (AEDC)',
            'eko-electric' => 'Eko Electric (EKEDC)',
            'ibadan-electric' => 'Ibadan Electric (IBEDC)',
            'ikeja-electric' => 'Ikeja Electric (IKEDC)',
            'jos-electic' => 'Jos Electric (JED)',
            'kaduna-electric' => 'Kaduna Electric (KAEDCO)',
            'kano-electric' => 'Kano Electric (KEDCO)',
            'portharcourt-electric' => 'Port Harcourt Electric (PHED)',
            'aba-electric' => 'Aba Electric (ABA)',
            'yola-electric' => 'Yola Electric (YEDC)',
            'benin-electric' => 'Benin Electric (BEDC)',
            'enugu-electric' => 'Enugu Electric (EEDC)',
        ];
    }

    private function educationServices(): array
    {
        $services = $this->parseServicesSetting('services_education');
        if (!empty($services)) {
            return $services;
        }

        return [
            'jamb' => 'JAMB PIN (UTME & Direct Entry)',
            'waec' => 'WAEC Result Checker PIN',
            'neco' => 'NECO Result Checker PIN',
            'nabteb' => 'NABTEB Result Checker PIN',
        ];
    }

    private function parseServicesSetting(string $key): array
    {
        $raw = trim((string) setting($key, ''));
        if ($raw === '') return [];

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;

            $parts = preg_split('/\\s*\\|\\s*/', $line, 2);
            if (count($parts) < 2) {
                $parts = preg_split('/\\s*=\\s*/', $line, 2);
            }

            $id = trim($parts[0] ?? '');
            if ($id === '') continue;
            if (preg_match('/\s/', $id)) {
                $id = preg_replace('/\s+/', '_', strtolower($id)) ?? $id;
            }
            $label = trim($parts[1] ?? $id);

            $out[$id] = $label !== '' ? $label : $id;
        }

        return $out;
    }

    private function rechargeCardNetworkLabels(): array
    {
        $configured = $this->parseServicesSetting('recharge_card_networks');
        $cleaned = [];

        foreach ($configured as $key => $label) {
            $slug = strtolower(trim((string) $key));
            if ($slug === '') {
                continue;
            }
            $cleaned[$slug] = trim((string) $label) !== '' ? trim((string) $label) : strtoupper($slug);
        }

        if (!empty($cleaned)) {
            return $cleaned;
        }

        return [
            'mtn' => 'MTN',
            'airtel' => 'Airtel',
            'glo' => 'Glo',
            'etisalat' => '9mobile',
        ];
    }

    private function rechargeCardValues(): array
    {
        $raw = trim((string) setting('recharge_card_values', ''));
        if ($raw === '') {
            return [100, 200, 400, 500, 1000];
        }

        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $values = [];

        foreach ($parts as $part) {
            $line = trim((string) $part);
            if ($line === '') {
                continue;
            }

            $first = trim((string) preg_split('/\\|/', $line, 2)[0]);
            $digits = preg_replace('/[^0-9]/', '', $first);
            if ($digits === '') {
                continue;
            }

            $amount = (int) $digits;
            if ($amount > 0) {
                $values[] = $amount;
            }
        }

        $values = array_values(array_unique($values));
        if (empty($values)) {
            return [100, 200, 400, 500, 1000];
        }

        sort($values);
        return $values;
    }

    private function verifyProviderSuccess(string $requestId): ?array
    {
        if (trim($requestId) === '') {
            return null;
        }

        $verifyResp = $this->gsubz->verify($requestId);
        if (!is_array($verifyResp) || empty($verifyResp)) {
            return null;
        }

        if ($this->gsubz->isSuccessful($verifyResp)) {
            return $verifyResp;
        }

        $raw = strtolower(trim(implode(' ', array_filter([
            (string) ($verifyResp['status'] ?? ''),
            (string) ($verifyResp['description'] ?? ''),
            (string) ($verifyResp['api_response'] ?? ''),
            (string) ($verifyResp['message'] ?? ''),
        ]))));

        if (str_contains($raw, 'success')) {
            return $verifyResp;
        }

        return null;
    }

    private function finalizeSuccessfulOrder(
        Order $order,
        string $requestId,
        array $providerResp = [],
        ?array $verifyResp = null
    ): void {
        $source = $verifyResp ?? $providerResp;
        $providerRef = (string) (
            $source['transactionID']
            ?? $source['requestID']
            ?? $providerResp['transactionID']
            ?? $providerResp['requestID']
            ?? $requestId
        );

        $meta = array_merge($order->meta ?? [], [
            'provider_response' => $providerResp,
            'message' => $this->gsubz->message($source),
        ]);

        if ($verifyResp !== null) {
            $meta['verify_response'] = $verifyResp;
        }

        $order->status = 'success';
        $order->provider_reference = $providerRef;
        $order->meta = $meta;
        $order->save();
        $this->awardReferralCommission($order);
    }

    private function awardReferralCommission(Order $order): void
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        if (($meta['referral_processed'] ?? false) === true || ($meta['referral_processed'] ?? '0') === '1') {
            return;
        }

        $meta['referral_processed'] = '1';
        $meta['referral_commission_kobo'] = 0;
        $meta['referral_percent'] = 0;

        $buyer = User::query()->find((int) $order->user_id);
        if (!$buyer) {
            $meta['referral_commission_status'] = 'buyer_missing';
            $order->meta = $meta;
            $order->save();
            return;
        }

        $referrerUserId = (int) ($buyer->referred_by_user_id ?? 0);
        if ($referrerUserId <= 0 || $referrerUserId === (int) $buyer->id) {
            $meta['referral_commission_status'] = 'no_referrer';
            $order->meta = $meta;
            $order->save();
            return;
        }

        if ($buyer->referral_qualified_at === null && !$this->buyerHasSuccessfulFunding($buyer)) {
            $meta['referral_commission_status'] = 'awaiting_first_funding';
            $order->meta = $meta;
            $order->save();
            return;
        }

        if ($buyer->referral_qualified_at === null) {
            $buyer->referral_qualified_at = now();
            $buyer->save();
        }

        if ((string) setting('referral_system_enabled', '1') !== '1') {
            $meta['referral_commission_status'] = 'system_disabled';
            $order->meta = $meta;
            $order->save();
            return;
        }

        $serviceType = trim(strtolower((string) ($meta['type'] ?? '')));
        if ($serviceType === '') {
            $meta['referral_commission_status'] = 'service_unknown';
            $order->meta = $meta;
            $order->save();
            return;
        }

        $serviceEnabledKey = 'referral_enabled_' . $serviceType;
        if ((string) setting($serviceEnabledKey, '1') !== '1') {
            $meta['referral_commission_status'] = 'service_disabled';
            $order->meta = $meta;
            $order->save();
            return;
        }

        $defaultPercent = (float) setting('referral_default_percent', 1);
        $percent = (float) setting('referral_percent_' . $serviceType, $defaultPercent);
        $percent = max(0, min(100, $percent));
        if ($percent <= 0) {
            $meta['referral_commission_status'] = 'percent_zero';
            $order->meta = $meta;
            $order->save();
            return;
        }

        $commissionKobo = (int) floor(((int) $order->amount) * ($percent / 100));
        if ($commissionKobo <= 0) {
            $meta['referral_commission_status'] = 'commission_zero';
            $order->meta = $meta;
            $order->save();
            return;
        }

        $referrer = User::query()->lockForUpdate()->find($referrerUserId);
        if (!$referrer) {
            $meta['referral_commission_status'] = 'referrer_missing';
            $order->meta = $meta;
            $order->save();
            return;
        }

        $referrer->referral_earnings_balance = (int) ($referrer->referral_earnings_balance ?? 0) + $commissionKobo;
        $referrer->referral_earnings_total = (int) ($referrer->referral_earnings_total ?? 0) + $commissionKobo;
        $referrer->save();
        try {
            $referrer->notify(new UserWalletActivityNotification(
                'Referral Commission Earned',
                'You earned N' . number_format($commissionKobo / 100, 2) . ' from a referral transaction.',
                [
                    'type' => 'credit',
                    'channel' => 'referral_commission',
                    'amount_kobo' => $commissionKobo,
                    'order_id' => $order->id,
                    'service_type' => $serviceType,
                ]
            ));
        } catch (\Throwable $e) {
            Log::warning('Referral commission notification failed.', [
                'referrer_user_id' => $referrer->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        $meta['referral_commission_status'] = 'credited';
        $meta['referral_commission_kobo'] = $commissionKobo;
        $meta['referral_percent'] = $percent;
        $meta['referral_referrer_user_id'] = (int) $referrer->id;
        $order->meta = $meta;
        $order->save();
    }

    private function buyerHasSuccessfulFunding(User $buyer): bool
    {
        $walletId = $buyer->wallet()->value('id');
        if (!$walletId) {
            return false;
        }

        return WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->where('type', 'credit')
            ->where('status', 'success')
            ->exists();
    }

    private function generateUniqueReferralCode(): string
    {
        do {
            $candidate = Str::upper(Str::random(8));
        } while (User::query()->where('referral_code', $candidate)->exists());

        return $candidate;
    }

    private function userFacingProviderFailureMessage(string $providerMessage, array $resp = []): string
    {
        $raw = strtolower(trim(implode(' ', array_filter([
            $providerMessage,
            (string) ($resp['api_response'] ?? ''),
            (string) ($resp['description'] ?? ''),
            (string) ($resp['message'] ?? ''),
            (string) ($resp['response_body'] ?? ''),
        ]))));

        $code = (int) ($resp['code'] ?? 0);
        $httpStatus = (int) ($resp['http_status'] ?? 0);

        if ($raw === '') {
            return $this->buildErrorMessage(3);
        }

        if (
            str_contains($raw, 'invalid phone') ||
            str_contains($raw, 'incorrect phone') ||
            str_contains($raw, 'phone number is not valid') ||
            str_contains($raw, 'invalid msisdn') ||
            str_contains($raw, 'invalid recipient')
        ) {
            return $this->buildErrorMessage(5);
        }

        if (
            in_array($code, [402, 500, 502, 503, 504, 506], true) ||
            in_array($httpStatus, [500, 502, 503, 504, 506], true) ||
            str_contains($raw, 'insufficient balance') ||
            str_contains($raw, 'insufficient provider balance') ||
            str_contains($raw, 'timeout') ||
            str_contains($raw, 'gateway') ||
            str_contains($raw, 'no response') ||
            str_contains($raw, 'server error') ||
            str_contains($raw, 'service unavailable') ||
            str_contains($raw, 'variant also negotiates')
        ) {
            return $this->buildErrorMessage(2);
        }

        if (
            in_array($code, [204, 206, 401, 404, 405, 406], true) ||
            str_contains($raw, 'invalid') ||
            str_contains($raw, 'not successful') ||
            str_contains($raw, 'failed')
        ) {
            return $this->buildErrorMessage(3);
        }

        return $this->buildErrorMessage(3);
    }

    private function userFacingRuntimeFailureMessage(\RuntimeException $e): string
    {
        $raw = strtolower($e->getMessage());

        if (
            str_contains($raw, 'insufficient wallet balance') ||
            str_contains($raw, 'insufficient balance')
        ) {
            return $this->buildErrorMessage(1);
        }

        return $this->buildErrorMessage(4);
    }

    private function buildErrorMessage(int $code): string
    {
        return match ($code) {
            1 => 'FAILED! (#1) INSUFFICIENT BALANCE',
            2 => 'FAILED (#2): TRY AGAIN OR CONTACT BELOVEDSUBP',
            3 => 'FAILED (#3): PROVIDER REQUEST FAILED. TRY AGAIN OR CONTACT BELOVEDSUBP',
            5 => 'FAILED (#5): INCORRECT PHONE NUMBER',
            default => 'FAILED (#4): SYSTEM ERROR. TRY AGAIN OR CONTACT BELOVEDSUBP',
        };
    }

    private function providerServiceId(string $serviceSlug): string
    {
        $slug = str_replace(' ', '_', strtolower(trim($serviceSlug)));
        if ($slug === '') {
            return $serviceSlug;
        }

        $serviceAliases = [
            'canva_pro' => 'canva',
            'canva-pro' => 'canva',
            'canvapro' => 'canva',
            'canva_premium' => 'canva',
        ];
        $slug = $serviceAliases[$slug] ?? $slug;

        $provider = trim((string) setting('provider', 'gsubz'));
        if ($provider === '') {
            $provider = 'gsubz';
        }

        $profileMap = $this->parseServiceMapProfile('service_map_profile_' . $provider);
        if (array_key_exists($slug, $profileMap) && trim((string) $profileMap[$slug]) !== '') {
            return trim((string) $profileMap[$slug]);
        }

        $providerSpecificKey = 'service_map_' . $provider . '_' . $slug;
        $providerSpecific = trim((string) setting($providerSpecificKey, ''));
        if ($providerSpecific !== '') {
            return $providerSpecific;
        }

        $legacyKey = 'service_map_' . $slug;
        $legacy = trim((string) setting($legacyKey, ''));
        return $legacy !== '' ? $legacy : $slug;
    }

    private function parseServiceMapProfile(string $key): array
    {
        $raw = trim((string) setting($key, ''));
        if ($raw === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $map = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s*[\|=]\s*/', $line, 2);
            $slug = trim((string) ($parts[0] ?? ''));
            $providerId = trim((string) ($parts[1] ?? ''));

            if ($slug === '' || $providerId === '') {
                continue;
            }

            $map[$slug] = $providerId;
        }

        return $map;
    }

    private function markFailedAndRefund(
        Order $order,
        Wallet $wallet,
        int $amountKobo,
        string $reference,
        string $message,
        array $resp = []
    ): void {
        $order->status = 'failed';
        $order->meta = array_merge($order->meta ?? [], [
            'provider_response' => $resp,
            'message'           => $message,
        ]);
        $order->save();

        $this->creditWallet(
            wallet: $wallet,
            amountKobo: $amountKobo,
            reference: 'REFUND-' . $reference,
            description: "Refund for failed order: {$reference}"
        );
    }
}
