<?php

namespace App\Http\Controllers;

use App\Models\NinVerificationCache;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\AdminSystemAlertNotification;
use App\Notifications\ManualOrderNotification;
use App\Notifications\UserWalletActivityNotification;
use App\Services\BvnApi;
use App\Services\GsubzApi;
use App\Services\ManualFulfilmentService;
use App\Services\NinApi;
use App\Services\ProviderPlanPriceService;
use App\Services\WalletLedger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VtuController extends Controller
{
    public function __construct(
        private readonly GsubzApi $gsubz,
        private readonly NinApi $ninApi,
        private readonly BvnApi $bvnApi,
        private readonly ProviderPlanPriceService $planPrices,
        private readonly ManualFulfilmentService $manualServices,
        private readonly WalletLedger $ledger,
    )
    {
    }

    // =========================================================
    // DASHBOARD
    // =========================================================
    public function dashboard()
    {
        $user = auth()->user();

        $user?->ensureReferralCode();

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
        return view('vtu.airtime-index', [
            'services' => $this->airtimeServices(),
        ]);
    }

    public function airtimeServiceForm(string $service)
    {
        $services = $this->airtimeServices();
        abort_unless(array_key_exists($service, $services), 404);

        return view('vtu.airtime', [
            'serviceSlug' => $service,
            'serviceLabel' => $services[$service],
            'phoneSuggestions' => $this->phoneSuggestionsForUser(auth()->user(), 'airtime'),
        ]);
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

        $serviceSlug = str_replace(' ', '_', strtolower(trim((string) $request->service_id)));
        $services = $this->dataServices();
        if (!array_key_exists($serviceSlug, $services)) {
            return $this->respondResult(
                request: $request,
                routeName: 'vtu.data',
                ok: false,
                message: 'Selected data service is not available.',
                extra: $this->walletPayload($wallet),
                status: 422
            );
        }

        $baseAmountNaira = (float) $request->amount;
        $sellingAmountNaira = $baseAmountNaira;
        $priceOverrideApplied = false;
        $providerServiceId = $this->providerServiceId($serviceSlug);

        if (in_array($provider, ['gsubz', 'alt'], true)) {
            $pricing = $this->resolvePlanPricing($serviceSlug, (string) $request->plan);
            if ($pricing === null) {
                return $this->respondResult(
                    request: $request,
                    routeName: 'vtu.data',
                    ok: false,
                    message: 'Selected data plan is not available right now. Please reload the plan list and try again.',
                    extra: $this->walletPayload($wallet),
                    status: 422
                );
            }

            $baseAmountNaira = $pricing['provider_price'];
            $sellingAmountNaira = $pricing['selling_price'];
            $priceOverrideApplied = $pricing['custom'];
        }

        $totalKobo  = $this->toKobo($sellingAmountNaira);
        [$discountPercent, $discountKobo, $payableKobo] = $this->applyDiscount($totalKobo, $user);
        $profitKobo = max(0, $this->toKobo($sellingAmountNaira - $baseAmountNaira));
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

            $resolvedServiceId = $this->resolveServiceId($serviceSlug, $serviceSlug);

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
                    'provider_amount_naira' => $baseAmountNaira,
                    'selling_amount_naira' => $sellingAmountNaira,
                    'markup_naira'       => max(0, $sellingAmountNaira - $baseAmountNaira),
                    'total_amount_naira' => $sellingAmountNaira,
                    'price_override_applied' => $priceOverrideApplied,
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
        $sellingAmountNaira = $baseAmountNaira;
        $priceOverrideApplied = false;
        $providerServiceId = $this->providerServiceId((string) $request->service_id);

        if (in_array($provider, ['gsubz', 'alt'], true)) {
            $pricing = $this->resolvePlanPricing((string) $request->service_id, (string) $request->plan);
            if ($pricing === null) {
                return $this->respondResult(
                    request: $request,
                    routeName: 'vtu.cable',
                    ok: false,
                    message: 'Selected cable plan is not available right now. Please reload the plan list and try again.',
                    extra: $this->walletPayload($wallet),
                    status: 422
                );
            }

            $baseAmountNaira = $pricing['provider_price'];
            $sellingAmountNaira = $pricing['selling_price'];
            $priceOverrideApplied = $pricing['custom'];
        }

        $totalKobo  = $this->toKobo($sellingAmountNaira);
        [$discountPercent, $discountKobo, $payableKobo] = $this->applyDiscount($totalKobo, $user);
        $profitKobo = max(0, $this->toKobo($sellingAmountNaira - $baseAmountNaira));
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
                    'provider_amount_naira' => $baseAmountNaira,
                    'selling_amount_naira' => $sellingAmountNaira,
                    'markup_naira'       => max(0, $sellingAmountNaira - $baseAmountNaira),
                    'total_amount_naira' => $sellingAmountNaira,
                    'price_override_applied' => $priceOverrideApplied,
                    'discount_percent'   => $discountPercent,
                    'discount_kobo'      => $discountKobo,
                    'discount_naira'     => $discountKobo / 100,
                    'amount_paid_naira'  => $payableNaira,
                    'requestID'          => $requestId,
                ],
            ]);

            if (in_array($provider, ['gsubz', 'alt'], true)) {
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

        $examDefaults = exam_price_defaults();
        $markupNaira = (float) setting('price_exam_transaction_fee', $examDefaults['fee']);
        $priceMap = [
            'jamb' => (float) setting('price_exam_jamb', $examDefaults['jamb']),
            'waec' => (float) setting('price_exam_waec', $examDefaults['waec']),
            'neco' => (float) setting('price_exam_neco', $examDefaults['neco']),
            'nabteb' => (float) setting('price_exam_nabteb', $examDefaults['nabteb']),
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
            'update_record' => identity_price('price_nin_validation_update_record'),
            default => identity_price('price_nin_validation_no_record'),
        };
        $markupNaira = (float) setting('markup_nin_validation', 0);

        // No validation endpoint means a human does the work. Charge and queue it
        // the same way, so the customer still gets a receipt and a result.
        if (!$this->ninApi->supportsValidation()) {
            try {
                $queued = $this->queueManualOrder(
                    user: $user,
                    wallet: $wallet,
                    slug: 'nin_validation',
                    title: 'NIN Validation',
                    submitted: [
                        'validation_type' => $validationType,
                        'nin' => (string) $payload['nin'],
                        'phone' => (string) ($user->phone ?? ''),
                    ],
                    customerRef: (string) $payload['nin'],
                    requestPrefix: 'NINVAL',
                    serviceId: $this->resolveServiceId('nin_validation', 'nin_validation'),
                    extraMeta: ['type' => 'nin_validation', 'validation_type' => $validationType],
                    pricing: ['base_naira' => $basePriceNaira, 'markup_naira' => $markupNaira],
                );
            } catch (\RuntimeException $e) {
                return back()->with('error', $this->userFacingRuntimeFailureMessage($e));
            } catch (\Throwable $e) {
                Log::error('NIN validation queueing error', ['error' => $e->getMessage()]);

                return back()->with('error', $this->buildErrorMessage(4));
            }

            return redirect()->route('vtu.receipt', $queued->id)->with('success', 'NIN validation submitted. We will notify you as soon as it is ready.');
        }

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
            $friendlyMessage = $this->friendlyNinProviderFailureMessage('validation', $resp, $message);

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

            if ($this->ninValidationShouldQueue($resp, $message)) {
                $order->status = 'pending';
                $order->meta = array_merge($order->meta ?? [], [
                    'provider_response' => $resp,
                    'message' => $message,
                ]);
                $order->save();
                DB::commit();

                return back()->with('success', 'NIN validation queued. We will keep you updated once processing completes.');
            }

            $this->markFailedAndRefund($order, $wallet, $payableKobo, $requestId, $friendlyMessage, $resp);
            DB::commit();

            return back()->with('error', $friendlyMessage);
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
        $priceVerifyNaira = identity_price('price_nin_verify');
        $user = auth()->user();
        $wallet = $this->requireWallet($user->wallet);
        $priceVerifyKobo = $this->toKobo($priceVerifyNaira);
        $forceRefresh = $request->boolean('force_refresh');

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
            $lookupPayload = [
                'nin' => (string) $payload['nin'],
            ];
        } elseif ($searchType === 'phone') {
            $payload = $request->validate([
                'phone' => ['required', 'digits_between:10,14'],
            ]);
            $lookupPayload = [
                'phone' => (string) $payload['phone'],
            ];
        } else {
            $payload = $request->validate([
                'firstname' => ['required', 'string', 'max:120'],
                'lastname' => ['required', 'string', 'max:120'],
                'dob' => ['required', 'date_format:d-m-Y'],
                'gender' => ['required', Rule::in(['male', 'female', 'm', 'f'])],
            ]);
            $lookupPayload = [
                'firstname' => (string) $payload['firstname'],
                'lastname' => (string) $payload['lastname'],
                'dob' => (string) $payload['dob'],
                'gender' => (string) $payload['gender'],
            ];
        }

        $cacheRecord = !$forceRefresh
            ? $this->findNinVerificationCache($searchType, $lookupPayload)
            : null;

        if ($cacheRecord) {
            $providerData = is_array($cacheRecord->provider_data) ? $cacheRecord->provider_data : [];
            $normalized = is_array($cacheRecord->normalized_data) ? $cacheRecord->normalized_data : [];
            if (empty($normalized) && !empty($providerData)) {
                $normalized = $this->normalizeNinPayload($providerData);
            }

            $response = [
                'success' => true,
                'message' => 'Loaded from saved verification.',
                'data' => $providerData,
                'normalized' => $normalized,
                'cache_hit' => true,
                'cache_id' => $cacheRecord->id,
                'cached_at' => optional($cacheRecord->last_verified_at)->toIso8601String(),
            ];
        } elseif ($searchType === 'nin') {
            $response = $this->ninApi->searchByNin((string) $lookupPayload['nin']);
        } elseif ($searchType === 'phone') {
            $response = $this->ninApi->searchByPhone((string) $lookupPayload['phone']);
        } else {
            $response = $this->ninApi->searchByDemography(
                (string) $lookupPayload['firstname'],
                (string) $lookupPayload['lastname'],
                (string) $lookupPayload['dob'],
                (string) $lookupPayload['gender'],
            );
        }

        $ok = $this->ninApi->isSuccessful($response);
        $message = $this->ninApi->message($response);
        $friendlyMessage = $ok ? $message : $this->friendlyNinProviderFailureMessage('verification', $response, $message);
        $providerData = is_array($response['data'] ?? null) ? $response['data'] : [];
        $normalized = is_array($response['normalized'] ?? null) ? $response['normalized'] : $this->normalizeNinPayload($providerData);
        $orderId = null;
        $cacheHit = (bool) ($response['cache_hit'] ?? false);
        $cacheId = $cacheRecord?->id ?? ($response['cache_id'] ?? null);
        $cachedAt = $response['cached_at'] ?? optional($cacheRecord?->last_verified_at)->toIso8601String();
        $cachedAtLabel = $this->formatNinCacheTimestamp($cachedAt);

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
                    'cache_hit' => $cacheHit,
                    'cache_id' => $cacheId,
                    'lookup_payload' => $lookupPayload,
                ],
            ]);
                $cacheRecord = $this->persistNinVerificationCache(
                    existing: $cacheRecord,
                    searchType: $searchType,
                    lookupPayload: $lookupPayload,
                    providerData: $providerData,
                    normalized: $normalized,
                    userId: (int) $user->id,
                    orderId: (int) $order->id,
                    refreshTimestamp: !$cacheHit,
                );
                $this->awardReferralCommission($order);
                $orderId = $order->id;
                $cacheId = $cacheRecord?->id ?? $cacheId;
                $cachedAt = optional($cacheRecord?->last_verified_at)->toIso8601String() ?? $cachedAt;
                $cachedAtLabel = $this->formatNinCacheTimestamp($cachedAt);
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
            'message' => $ok ? $message : $friendlyMessage,
            'data' => $providerData,
            'normalized' => $normalized,
            'order_id' => $orderId,
            'balance_kobo' => (int) ($wallet->fresh()?->balance ?? $wallet->balance ?? 0),
            'cache_hit' => $cacheHit,
            'cache_id' => $cacheId,
            'cached_at' => $cachedAt,
            'cached_at_label' => $cachedAtLabel,
            'force_refresh' => $forceRefresh,
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
            'long_slip' => identity_price('price_nin_slip_long'),
            'standard_slip' => identity_price('price_nin_slip_standard'),
            'premium_slip' => identity_price('price_nin_slip_premium'),
            'vnin_slip' => identity_price('price_nin_slip_vnin'),
        ];
        $basePriceNaira = (float) ($priceMapNaira[$slipType] ?? identity_price('price_nin_slip_long'));
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
            'message' => $ok ? $message : $this->friendlyNinProviderFailureMessage('slip print', $response, $message),
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

        $basePriceNaira = identity_price('price_bvn_verify');
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
            ? identity_price('price_bvn_retrieve_phone')
            : identity_price('price_bvn_retrieve_bms');
        $markupNaira = (float) setting('markup_bvn', 0);

        $endpointAvailable = $retrieveType === 'phone'
            ? $this->bvnApi->supportsRetrieveByPhone()
            : $this->bvnApi->supportsRetrieveByBms();

        // Without a retrieval endpoint an admin does the search by hand. Charge and
        // queue on this same form so the customer never sees a difference.
        if (!$endpointAvailable) {
            $customerRef = $retrieveType === 'phone'
                ? (string) $payload['phone']
                : (string) ($payload['bms_no'] ?? '');

            try {
                $queued = $this->queueManualOrder(
                    user: $user,
                    wallet: $wallet,
                    slug: 'bvn_retrieve',
                    title: 'BVN Retrieval',
                    submitted: [
                        'retrieve_type' => $retrieveType,
                        'phone' => (string) ($payload['phone'] ?? ''),
                        'bms_no' => (string) ($payload['bms_no'] ?? ''),
                        'ticket_id' => (string) ($payload['ticket_id'] ?? ''),
                        'agent_code' => (string) ($payload['agent_code'] ?? ''),
                    ],
                    customerRef: $customerRef,
                    requestPrefix: 'BVNR',
                    serviceId: $this->resolveServiceId('bvn_retrieve', 'bvn'),
                    extraMeta: [
                        'type' => 'bvn',
                        'service_type' => 'retrieve',
                        'retrieve_type' => $retrieveType,
                    ],
                    pricing: ['base_naira' => $basePriceNaira, 'markup_naira' => $markupNaira],
                );
            } catch (\RuntimeException $e) {
                return response()->json([
                    'ok' => false,
                    'message' => $this->userFacingRuntimeFailureMessage($e),
                    'data' => [],
                ], 402);
            } catch (\Throwable $e) {
                Log::error('BVN retrieval queueing error', ['error' => $e->getMessage()]);

                return response()->json([
                    'ok' => false,
                    'message' => $this->buildErrorMessage(4),
                    'data' => [],
                ], 500);
            }

            return response()->json([
                'ok' => true,
                'queued' => true,
                'message' => 'Retrieval request received. Our team is searching for your BVN and will send the result to your receipt.',
                'data' => [],
                'normalized' => [
                    'status' => 'In progress',
                    'message' => 'Your result will appear on the receipt page and in your notifications.',
                ],
                'order_id' => $queued->id,
                'receipt_url' => route('vtu.receipt', $queued->id),
                'balance_kobo' => (int) ($wallet->fresh()?->balance ?? $wallet->balance ?? 0),
            ]);
        }

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

                DB::commit();

                return response()->json([
                    'ok' => true,
                    'message' => $message !== '' ? $message : 'BVN retrieval request completed.',
                    'data' => $providerData,
                    'normalized' => $normalized,
                    'order_id' => $order->id,
                    'balance_kobo' => (int) ($wallet->fresh()?->balance ?? $wallet->balance ?? 0),
                    'raw' => $response,
                ]);
            }

            // The provider gave a definitive answer and it was not a BVN, so the
            // money comes back rather than sitting against an order that will
            // never resolve. Every other purchase path on this site does this.
            $realMessage = $this->userFacingProviderFailureMessage($message, $response);
            $this->markFailedAndRefund($order, $wallet, $totalKobo, $requestId, $realMessage, $response);

            DB::commit();

            return response()->json([
                'ok' => false,
                'refunded' => true,
                'message' => $realMessage.' The charge has been returned to your wallet.',
                'data' => $providerData,
                'normalized' => $normalized,
                'order_id' => $order->id,
                'balance_kobo' => (int) ($wallet->fresh()?->balance ?? $wallet->balance ?? 0),
                'raw' => $response,
            ], 400);
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
        $buyAgainUrl = $this->resolveBuyAgainUrl($order);

        return view('vtu.receipt', compact('order', 'balanceBeforeKobo', 'balanceAfterKobo', 'buyAgainUrl'));
    }

    /**
     * Result documents hold identity data, so they live on the private disk and
     * are only ever streamed to the customer who owns the order.
     */
    public function receiptFile(int $id)
    {
        $user = auth()->user();

        $order = Order::query()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $meta = is_array($order->meta) ? $order->meta : [];
        $path = trim((string) ($meta['result_file'] ?? ''));

        abort_if($path === '' || !Storage::disk('local')->exists($path), 404);

        $name = trim((string) ($meta['result_file_name'] ?? ''));
        if ($name === '') {
            $name = basename($path);
        }

        return Storage::disk('local')->download($path, $name);
    }

    // Identity services with no live provider: the customer pays and fills the
    // form as usual, an admin completes it, and the result lands on the receipt.
    public function manualServiceForm(string $service)
    {
        $definition = $this->manualServices->find($service);
        abort_unless($definition !== null, 404);

        return view('vtu.manual-service', [
            'definition' => $definition,
            'priceNaira' => $this->manualServices->totalNaira($service),
            'turnaroundLabel' => $this->manualServices->turnaroundLabel($service),
            'expectedBy' => $this->manualServices->expectedBy($service),
            'walletBalanceKobo' => (int) (auth()->user()?->wallet?->balance ?? 0),
        ]);
    }

    public function manualServiceSubmit(string $service, Request $request)
    {
        $definition = $this->manualServices->find($service);
        abort_unless($definition !== null, 404);

        $submitted = $request->validate($this->manualServices->validationRules($service));

        $user = auth()->user();
        $wallet = $this->requireWallet($user->wallet);
        $title = (string) $definition['title'];
        $payableKobo = $this->manualPayableKobo($service, $user);

        if (((int) $wallet->balance) < $payableKobo) {
            return back()->with('error', $this->buildErrorMessage(1));
        }

        $customerRef = (string) ($submitted['tracking_id']
            ?? $submitted['nin']
            ?? $submitted['bvn']
            ?? $submitted['phone']
            ?? $title);

        try {
            $order = $this->queueManualOrder(
                user: $user,
                wallet: $wallet,
                slug: $service,
                title: $title,
                submitted: $submitted,
                customerRef: $customerRef,
                requestPrefix: 'MANUAL',
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $this->userFacingRuntimeFailureMessage($e));
        } catch (\Throwable $e) {
            Log::error('Manual identity service submission error', [
                'service' => $service,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', $this->buildErrorMessage(4));
        }

        return redirect()
            ->route('vtu.receipt', $order->id)
            ->with('success', $title.' received. '.$this->manualServices->turnaroundLabel($service).' to complete it.');
    }

    private function manualPayableKobo(string $slug, ?User $user): int
    {
        $totalKobo = $this->toKobo($this->manualServices->totalNaira($slug));
        [, , $payableKobo] = $this->applyDiscount($totalKobo, $user);

        return $payableKobo;
    }

    /**
     * Charge at submission and park the order in the admin queue. Shared by the
     * standalone identity services and by the NIN/BVN endpoints whose provider
     * credentials are not configured, so both behave identically.
     *
     * @param  array<string, mixed>  $submitted
     * @param  array<string, mixed>  $extraMeta
     * @param  array{base_naira?: float, markup_naira?: float}|null  $pricing
     */
    private function queueManualOrder(
        User $user,
        Wallet $wallet,
        string $slug,
        string $title,
        array $submitted,
        string $customerRef,
        string $requestPrefix,
        ?int $serviceId = null,
        array $extraMeta = [],
        ?array $pricing = null,
    ): Order {
        $basePriceNaira = $pricing['base_naira'] ?? $this->manualServices->priceNaira($slug);
        $markupNaira = $pricing['markup_naira'] ?? $this->manualServices->markupNaira($slug);
        $totalNaira = $basePriceNaira + $markupNaira;
        $totalKobo = $this->toKobo($totalNaira);
        [$discountPercent, $discountKobo, $payableKobo] = $this->applyDiscount($totalKobo, $user);
        $requestId = $this->makeRequestId($requestPrefix);
        $expectedBy = $this->manualServices->expectedBy($slug);

        DB::beginTransaction();
        try {
            $this->debitWallet($wallet, $payableKobo, $requestId, $title.' - '.$customerRef);

            $order = Order::create([
                'user_id' => $user->id,
                'service_id' => $serviceId ?? $this->resolveServiceId($slug, 'identity'),
                'customer_ref' => $customerRef,
                'amount' => $payableKobo,
                'profit' => $this->toKobo($markupNaira),
                'provider' => 'manual',
                'provider_reference' => $requestId,
                'status' => 'pending',
                'meta' => array_merge([
                    'type' => 'manual_service',
                    'manual_queue' => true,
                    'manual_service' => $slug,
                    'manual_service_title' => $title,
                    'submitted' => $submitted,
                    'base_amount_naira' => $basePriceNaira,
                    'markup_naira' => $markupNaira,
                    'total_amount_naira' => $totalNaira,
                    'discount_percent' => $discountPercent,
                    'discount_kobo' => $discountKobo,
                    'turnaround_hours' => $this->manualServices->turnaroundHours($slug),
                    'expected_by' => $expectedBy->toIso8601String(),
                    'requestID' => $requestId,
                ], $extraMeta),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        // Outside the transaction: a notification failure must not roll back a
        // payment the customer has already made.
        $this->notifyManualOrderParties($order, $user, $title, $expectedBy);

        return $order;
    }

    private function notifyManualOrderParties(Order $order, User $user, string $title, Carbon $expectedBy): void
    {
        $receiptUrl = route('vtu.receipt', $order->id);

        try {
            $user->notify(new ManualOrderNotification(
                title: $title.' received',
                message: 'We have received your '.$title.' request and charged your wallet. Our team is working on it and it should be ready '.$expectedBy->diffForHumans(null, true).'. Your result will appear on this receipt.',
                url: $receiptUrl,
                payload: [
                    'type' => 'manual_order_received',
                    'order_id' => $order->id,
                    'manual_service' => (string) ($order->meta['manual_service'] ?? ''),
                    'expected_by' => $expectedBy->toIso8601String(),
                ],
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to notify customer about manual order.', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->notifyAdminsAboutManualOrder($order, $user, $title, $receiptUrl);
    }

    /**
     * The high-priority alert the owner asked for: every request that has no
     * API behind it must reach an admin immediately, because a human is the
     * only thing that can complete it.
     */
    private function notifyAdminsAboutManualOrder(Order $order, User $user, string $title, string $receiptUrl): void
    {
        try {
            $admins = User::query()->where('is_admin', true)->get();
            if ($admins->isEmpty()) {
                return;
            }

            $submitted = is_array($order->meta['submitted'] ?? null) ? $order->meta['submitted'] : [];
            $summary = collect($submitted)
                ->reject(fn ($value) => $value === null || $value === '')
                ->map(fn ($value, $key) => str_replace('_', ' ', $key).': '.$value)
                ->implode(' | ');

            Notification::send($admins, new AdminSystemAlertNotification(
                title: 'ACTION NEEDED: '.$title.' request waiting',
                message: $user->name.' ('.$user->email.') submitted a '.$title.' request that needs manual processing. '.$summary,
                severity: 'critical',
                url: route('admin.manual-orders.show', $order->id),
                payload: [
                    'type' => 'manual_order_submitted',
                    'order_id' => $order->id,
                    'manual_service' => (string) ($order->meta['manual_service'] ?? ''),
                    'customer_id' => $user->id,
                    'customer_name' => $user->name,
                    'customer_email' => $user->email,
                    'amount_kobo' => (int) $order->amount,
                    'severity' => 'critical',
                ],
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to notify admins about a manual order.', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
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
        $provider = (string) setting('provider', 'gsubz');
        $plans = $this->planPrices->customerPlans($resp['plans'] ?? [], $serviceId, $providerServiceId, $provider);

        return response()->json([
            'ok'      => (bool) ($resp['ok'] ?? false),
            'plans'   => $plans,
            'message' => $resp['message'] ?? null,
            'service' => $providerServiceId,
        ]);
    }

    private function resolvePlanPricing(string $serviceSlug, string $planId): ?array
    {
        $provider = (string) setting('provider', 'gsubz');
        $providerServiceId = $this->providerServiceId($serviceSlug);
        $resp = $this->gsubz->plans($providerServiceId);
        if (!($resp['ok'] ?? false) || !is_array($resp['plans'] ?? null)) {
            return null;
        }

        return $this->planPrices->pricingForPlan($serviceSlug, $planId, $resp['plans'], $providerServiceId, $provider);
    }

    private function resolveBuyAgainUrl(Order $order): string
    {
        $meta = is_array($order->meta) ? $order->meta : [];
        $type = (string) ($meta['type'] ?? '');
        $service = $order->service_id ? Service::query()->find($order->service_id) : null;
        $serviceSlug = trim((string) ($service?->slug ?? ($meta['service_id'] ?? '')));

        return match ($type) {
            'airtime' => $serviceSlug !== '' ? route('vtu.airtime.service', $serviceSlug) : route('vtu.airtime'),
            'data' => $serviceSlug !== '' ? route('vtu.data.service', $serviceSlug) : route('vtu.data'),
            'cable' => $serviceSlug !== '' ? route('vtu.cable.service', $serviceSlug) : route('vtu.cable'),
            'electricity' => $serviceSlug !== '' ? route('vtu.electricity.service', $serviceSlug) : route('vtu.electricity'),
            'exam' => $serviceSlug !== '' ? route('vtu.exam.service', $serviceSlug) : route('vtu.exam'),
            'premium' => route('vtu.premium-apps'),
            'recharge_card' => route('vtu.recharge-card'),
            'nin' => route('vtu.nin'),
            'nin_validation' => route('vtu.nin-validation'),
            'bvn' => route('vtu.bvn'),
            default => route('dashboard'),
        };
    }

    private function friendlyNinProviderFailureMessage(string $action, array $response, ?string $fallbackMessage = null): string
    {
        if ($this->ninProviderWalletIssueDetected($response)) {
            $this->notifyAdminsAboutNinProviderWalletIssue($action, $response);

            return 'NIN service is temporarily unavailable right now. Please try again shortly or contact support.';
        }

        $message = trim((string) ($fallbackMessage ?: $this->ninApi->message($response)));

        return $message !== ''
            ? $message
            : 'NIN service is temporarily unavailable right now. Please try again shortly or contact support.';
    }

    private function ninValidationShouldQueue(array $response, ?string $message = null): bool
    {
        $raw = strtolower($this->flattenFailurePayload($response).' '.trim((string) $message));

        if ($this->ninProviderWalletIssueDetected($response)) {
            return false;
        }

        return str_contains($raw, 'pending')
            || str_contains($raw, 'queued')
            || str_contains($raw, 'queue')
            || str_contains($raw, 'processing')
            || str_contains($raw, 'submitted');
    }

    private function ninProviderWalletIssueDetected(array $response): bool
    {
        $raw = strtolower($this->flattenFailurePayload($response));

        return (str_contains($raw, 'insufficient') && (
            str_contains($raw, 'fund')
            || str_contains($raw, 'wallet')
            || str_contains($raw, 'balance')
            || str_contains($raw, 'credit')
        ))
            || str_contains($raw, 'low balance')
            || str_contains($raw, 'not enough balance')
            || str_contains($raw, 'wallet is empty')
            || str_contains($raw, 'no fund');
    }

    private function flattenFailurePayload(array $response): string
    {
        $parts = [
            $response['message'] ?? null,
            $response['error'] ?? null,
            $response['status'] ?? null,
            data_get($response, 'response.message'),
            data_get($response, 'response.error'),
            data_get($response, 'response.status'),
        ];

        $responseBody = $response['response'] ?? null;
        if (is_array($responseBody)) {
            $encoded = json_encode($responseBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded !== false) {
                $parts[] = $encoded;
            }
        } elseif (is_string($responseBody)) {
            $parts[] = $responseBody;
        }

        return implode(' ', array_filter(array_map(
            static fn ($value) => is_scalar($value) ? trim((string) $value) : null,
            $parts
        )));
    }

    private function notifyAdminsAboutNinProviderWalletIssue(string $action, array $response): void
    {
        $fingerprint = 'nin-provider-wallet-alert:'.md5($action.'|'.$this->flattenFailurePayload($response));
        if (Cache::has($fingerprint)) {
            return;
        }

        Cache::put($fingerprint, true, now()->addMinutes(20));

        try {
            $admins = User::query()->where('is_admin', true)->get();
            if ($admins->isEmpty()) {
                return;
            }

            Notification::send($admins, new AdminSystemAlertNotification(
                title: 'URGENT: NIN provider wallet needs funding',
                message: 'A NIN '.$action.' request failed because the provider wallet appears empty or underfunded. Please fund the NIN provider wallet immediately.',
                severity: 'critical',
                url: url('/admin'),
                payload: [
                    'type' => 'nin_provider_wallet',
                    'action' => $action,
                    'severity' => 'critical',
                    'provider_message' => trim((string) ($response['message'] ?? $response['error'] ?? '')),
                ],
            ));
        } catch (\Throwable $e) {
            Log::warning('Failed to notify admins about NIN provider wallet issue.', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
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
        $this->ledger->debit($wallet, $amountKobo, $reference, $description);
    }

    private function creditWallet(Wallet $wallet, int $amountKobo, string $reference, string $description): void
    {
        $this->ledger->credit($wallet, $amountKobo, $reference, $description);
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

    private function ninVerificationCacheEnabled(): bool
    {
        static $exists = null;

        if ($exists === null) {
            try {
                $exists = Schema::hasTable('nin_verification_caches');
            } catch (\Throwable $e) {
                $exists = false;
            }
        }

        return $exists;
    }

    private function findNinVerificationCache(string $searchType, array $lookupPayload): ?NinVerificationCache
    {
        if (!$this->ninVerificationCacheEnabled()) {
            return null;
        }

        if ($searchType === 'nin') {
            $ninKey = $this->normalizeNinLookupKey((string) ($lookupPayload['nin'] ?? ''));
            if ($ninKey === '') {
                return null;
            }

            return NinVerificationCache::query()
                ->where(function ($query) use ($ninKey) {
                    $query->where('lookup_nin', $ninKey)
                        ->orWhere('resolved_nin', $ninKey);
                })
                ->orderByDesc('last_verified_at')
                ->first();
        }

        if ($searchType === 'phone') {
            $phoneKeys = $this->possibleNinPhoneLookupKeys((string) ($lookupPayload['phone'] ?? ''));
            if (empty($phoneKeys)) {
                return null;
            }

            return NinVerificationCache::query()
                ->where(function ($query) use ($phoneKeys) {
                    $query->whereIn('lookup_phone', $phoneKeys)
                        ->orWhereIn('resolved_phone', $phoneKeys);
                })
                ->orderByDesc('last_verified_at')
                ->first();
        }

        $demoKey = $this->buildNinDemoLookupKey(
            (string) ($lookupPayload['firstname'] ?? ''),
            (string) ($lookupPayload['lastname'] ?? ''),
            (string) ($lookupPayload['dob'] ?? ''),
            (string) ($lookupPayload['gender'] ?? '')
        );

        if ($demoKey === '') {
            return null;
        }

        return NinVerificationCache::query()
            ->where('lookup_demo', $demoKey)
            ->orderByDesc('last_verified_at')
            ->first();
    }

    private function persistNinVerificationCache(
        ?NinVerificationCache $existing,
        string $searchType,
        array $lookupPayload,
        array $providerData,
        array $normalized,
        int $userId,
        int $orderId,
        bool $refreshTimestamp
    ): ?NinVerificationCache {
        if (!$this->ninVerificationCacheEnabled()) {
            return null;
        }

        $cache = $existing ?: $this->findExistingNinVerificationCacheForWrite($searchType, $lookupPayload, $normalized);
        if (!$cache) {
            $cache = new NinVerificationCache();
        }

        $lookupNin = $this->normalizeNinLookupKey((string) ($lookupPayload['nin'] ?? ''));
        $lookupPhoneCandidates = $this->possibleNinPhoneLookupKeys((string) ($lookupPayload['phone'] ?? ''));
        $lookupDemo = $searchType === 'demo'
            ? $this->buildNinDemoLookupKey(
                (string) ($lookupPayload['firstname'] ?? ''),
                (string) ($lookupPayload['lastname'] ?? ''),
                (string) ($lookupPayload['dob'] ?? ''),
                (string) ($lookupPayload['gender'] ?? '')
            )
            : '';
        $resolvedNin = $this->normalizeNinLookupKey((string) ($normalized['nin'] ?? ''));
        $resolvedPhoneCandidates = $this->possibleNinPhoneLookupKeys((string) ($normalized['phone_number'] ?? ''));
        $resolvedDemo = $this->buildNinDemoLookupKey(
            (string) ($normalized['first_name'] ?? ''),
            (string) ($normalized['last_name'] ?? ''),
            (string) ($normalized['birthdate'] ?? ''),
            (string) ($normalized['gender'] ?? '')
        );

        if ($lookupNin !== '' && blank($cache->lookup_nin)) {
            $cache->lookup_nin = $lookupNin;
        }

        if (!empty($lookupPhoneCandidates) && blank($cache->lookup_phone)) {
            $cache->lookup_phone = $lookupPhoneCandidates[0];
        }

        if ($lookupDemo !== '') {
            $cache->lookup_demo = $lookupDemo;
        } elseif ($resolvedDemo !== '' && blank($cache->lookup_demo)) {
            $cache->lookup_demo = $resolvedDemo;
        }

        if ($resolvedNin !== '') {
            $cache->resolved_nin = $resolvedNin;
        }

        if (!empty($resolvedPhoneCandidates)) {
            $cache->resolved_phone = $resolvedPhoneCandidates[0];
        }

        $cache->source_lookup_type = $searchType;
        $cache->source_payload = $lookupPayload;
        $cache->normalized_data = $normalized;
        $cache->provider_data = $providerData;

        if (!$cache->exists || !$cache->first_verified_by_user_id) {
            $cache->first_verified_by_user_id = $userId;
        }

        $cache->last_verified_by_user_id = $userId;
        $cache->last_order_id = $orderId;

        if ($refreshTimestamp || !$cache->last_verified_at) {
            $cache->last_verified_at = now();
        }

        $cache->save();

        return $cache;
    }

    private function findExistingNinVerificationCacheForWrite(string $searchType, array $lookupPayload, array $normalized): ?NinVerificationCache
    {
        if (!$this->ninVerificationCacheEnabled()) {
            return null;
        }

        $ninKeys = array_values(array_unique(array_filter([
            $this->normalizeNinLookupKey((string) ($lookupPayload['nin'] ?? '')),
            $this->normalizeNinLookupKey((string) ($normalized['nin'] ?? '')),
        ])));
        $phoneKeys = array_values(array_unique(array_filter(array_merge(
            $this->possibleNinPhoneLookupKeys((string) ($lookupPayload['phone'] ?? '')),
            $this->possibleNinPhoneLookupKeys((string) ($normalized['phone_number'] ?? ''))
        ))));
        $demoKeys = array_values(array_unique(array_filter([
            $searchType === 'demo'
                ? $this->buildNinDemoLookupKey(
                    (string) ($lookupPayload['firstname'] ?? ''),
                    (string) ($lookupPayload['lastname'] ?? ''),
                    (string) ($lookupPayload['dob'] ?? ''),
                    (string) ($lookupPayload['gender'] ?? '')
                )
                : '',
            $this->buildNinDemoLookupKey(
                (string) ($normalized['first_name'] ?? ''),
                (string) ($normalized['last_name'] ?? ''),
                (string) ($normalized['birthdate'] ?? ''),
                (string) ($normalized['gender'] ?? '')
            ),
        ])));

        if (empty($ninKeys) && empty($phoneKeys) && empty($demoKeys)) {
            return null;
        }

        return NinVerificationCache::query()
            ->where(function ($query) use ($ninKeys, $phoneKeys, $demoKeys) {
                if (!empty($ninKeys)) {
                    $query->orWhereIn('lookup_nin', $ninKeys)
                        ->orWhereIn('resolved_nin', $ninKeys);
                }

                if (!empty($phoneKeys)) {
                    $query->orWhereIn('lookup_phone', $phoneKeys)
                        ->orWhereIn('resolved_phone', $phoneKeys);
                }

                if (!empty($demoKeys)) {
                    $query->orWhereIn('lookup_demo', $demoKeys);
                }
            })
            ->orderByDesc('last_verified_at')
            ->first();
    }

    private function normalizeNinLookupKey(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function possibleNinPhoneLookupKeys(string $value): array
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return [];
        }

        $variants = [$digits];

        if (str_starts_with($digits, '234') && strlen($digits) === 13) {
            $variants[] = '0' . substr($digits, 3);
            $variants[] = substr($digits, 3);
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $variants[] = substr($digits, 1);
            $variants[] = '234' . substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            $variants[] = '0' . $digits;
            $variants[] = '234' . $digits;
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function buildNinDemoLookupKey(string $firstname, string $lastname, string $dob, string $gender): string
    {
        $first = Str::lower(trim($firstname));
        $last = Str::lower(trim($lastname));
        $date = $this->normalizeNinDateKey($dob);
        $genderKey = $this->normalizeNinGenderKey($gender);

        if ($first === '' || $last === '' || $date === '' || $genderKey === '') {
            return '';
        }

        return implode('|', [$first, $last, $date, $genderKey]);
    }

    private function normalizeNinGenderKey(string $gender): string
    {
        $value = Str::lower(trim($gender));

        return match ($value) {
            'male', 'm' => 'm',
            'female', 'f' => 'f',
            default => '',
        };
    }

    private function normalizeNinDateKey(string $value): string
    {
        $date = trim($value);
        if ($date === '') {
            return '';
        }

        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $date)) {
            return $date;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $date)->format('d-m-Y');
            } catch (\Throwable $e) {
                return $date;
            }
        }

        try {
            return Carbon::parse($date)->format('d-m-Y');
        } catch (\Throwable $e) {
            return $date;
        }
    }

    private function formatNinCacheTimestamp(?string $timestamp): ?string
    {
        if (!$timestamp) {
            return null;
        }

        try {
            return Carbon::parse($timestamp)->format('d M Y, h:i A');
        } catch (\Throwable $e) {
            return null;
        }
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

    private function airtimeServices(): array
    {
        $services = $this->parseServicesSetting('services_airtime');
        if (!empty($services)) {
            return $services;
        }

        return [
            'mtn' => 'MTN Airtime',
            'airtel' => 'Airtel Airtime',
            'glo' => 'Glo Airtime',
            'etisalat' => '9mobile Airtime',
        ];
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

        // These slugs must match the `services` table, because the same string
        // is what goes to the provider as serviceID when no service map is set.
        return [
            'abuja-electric' => 'Abuja Electric (AEDC)',
            'eko-electric' => 'Eko Electric (EKEDC)',
            'ibadan-electric' => 'Ibadan Electric (IBEDC)',
            'ikeja-electric' => 'Ikeja Electric (IKEDC)',
            'jos-electric' => 'Jos Electric (JED)',
            'kaduna-electric' => 'Kaduna Electric (KAEDCO)',
            'kano-electric' => 'Kano Electric (KEDCO)',
            'phed-electric' => 'Port Harcourt Electric (PHED)',
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

    private function userFacingProviderFailureMessage(string $providerMessage, array $resp = []): string
    {
        if (!empty($resp['unconfigured'])) {
            return 'Airtime and data purchases are temporarily unavailable: no provider API key is configured.';
        }

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
        if (array_key_exists($slug, $profileMap)) {
            $profileServiceId = $this->usableProviderServiceId($profileMap[$slug]);
            if ($profileServiceId !== null) {
                return $profileServiceId;
            }
        }

        $providerSpecificKey = 'service_map_' . $provider . '_' . $slug;
        $providerSpecific = $this->usableProviderServiceId(setting($providerSpecificKey, ''));
        if ($providerSpecific !== null) {
            return $providerSpecific;
        }

        $legacyKey = 'service_map_' . $slug;
        $legacy = $this->usableProviderServiceId(setting($legacyKey, ''));
        return $legacy !== null ? $legacy : $slug;
    }

    private function usableProviderServiceId(mixed $value): ?string
    {
        $serviceId = trim((string) $value);
        if ($serviceId === '') {
            return null;
        }

        // Admin service-map fields are provider service codes, not plan prices.
        // If a price is saved there by mistake, falling back to the local slug keeps plan lookups alive.
        if (preg_match('/^(?:\x{20A6}|N|NGN)?\s*\d+(?:[,.]\d+)?\s*(?:naira)?$/iu', $serviceId)) {
            return null;
        }

        return $serviceId;
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
