<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class AdminController extends Controller
{
    public function dashboard()
    {
        $totalUsers = User::count();

        $totalOrders = Order::count();
        $successfulOrders = Order::where('status', 'success')->count();
        $pendingOrders = Order::where('status', 'pending')->count();

        // Total Sales = sum of successful orders amount (kobo)
        $totalSales = (int) Order::where('status', 'success')->sum('amount');

        // Total Profit = sum of successful orders profit (kobo)
        $totalProfit = (int) Order::where('status', 'success')->sum('profit');

        // Wallet funding (Flutterwave credits only)
        $totalFunding = (int) WalletTransaction::whereIn('channel', ['flutterwave', 'flutterwave_virtual_account'])
            ->where('type', 'credit')
            ->where('status', 'success')
            ->sum('amount');

        // Total purchases (debits that succeeded)
        $totalPurchases = (int) WalletTransaction::where('type', 'debit')
            ->where('status', 'success')
            ->sum('amount');

        $recentOrders = Order::latest()->take(10)->get();
        $recentTransactions = WalletTransaction::latest()->take(10)->get();

        return view('admin.dashboard', compact(
            'totalUsers',
            'totalOrders',
            'successfulOrders',
            'pendingOrders',
            'totalSales',
            'totalProfit',
            'totalFunding',
            'totalPurchases',
            'recentOrders',
            'recentTransactions'
        ));
    }

    public function users()
    {
        $users = User::latest()->paginate(20);
        return view('admin.users', compact('users'));
    }

    public function orders(Request $request)
    {
        $query = Order::with('user')->latest();

        // ✅ Search: order id / provider reference / customer ref / user name/email
        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                if (is_numeric($search)) {
                    $q->orWhere('id', (int) $search);
                }

                $q->orWhere('provider_reference', 'like', "%{$search}%")
                    ->orWhere('customer_ref', 'like', "%{$search}%");

                $q->orWhereHas('user', function ($u) use ($search) {
                    $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        // ✅ Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // ✅ Filter by type (stored in meta->type)
        if ($request->filled('type')) {
            $query->where('meta->type', $request->type);
        }

        $orders = $query->paginate(20)->appends($request->query());

        return view('admin.orders', compact('orders'));
    }

    /**
     * Admin Settings Page
     * (Provider, markups, exam prices, branding, popup, whatsapp)
     */
    public function settings()
    {
        return view('admin.settings', [
            // Provider
            'provider' => setting('provider', 'mock'),

            // Markups (₦)
            'airtime_markup' => setting('markup_airtime', '0'),
            'data_markup' => setting('markup_data', '0'),
            'cable_markup' => setting('markup_cable', '0'),
            'electricity_markup' => setting('markup_electricity', '0'),
            'exam_markup' => setting('markup_exam', '0'),

            // Exam base prices (₦)
            'price_exam_waec' => setting('price_exam_waec', '0'),
            'price_exam_neco' => setting('price_exam_neco', '0'),
            'price_exam_nabteb' => setting('price_exam_nabteb', '0'),

            // Branding
            'site_name' => setting('site_name', 'My VTU'),
            'site_logo' => setting('site_logo', ''),     // public path like /branding/logo.png
            'site_favicon' => setting('site_favicon', ''), // public path like /branding/favicon.ico

            // Popup + WhatsApp
            'home_popup_enabled' => setting('home_popup_enabled', '1'),
            'home_popup_message' => setting('home_popup_message', 'Need NIN services? Tap the WhatsApp button to chat with us.'),
            'whatsapp_link' => setting('whatsapp_link', 'https://wa.me/2348165587119'),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            // Provider
            'provider' => ['required', 'in:mock,gsubz,alt'],

            // Markups (₦)
            'markup_airtime' => ['required', 'numeric', 'min:0'],
            'markup_data' => ['required', 'numeric', 'min:0'],
            'markup_cable' => ['required', 'numeric', 'min:0'],
            'markup_electricity' => ['required', 'numeric', 'min:0'],
            'markup_exam' => ['required', 'numeric', 'min:0'],

            // Exam prices (₦)
            'price_exam_waec' => ['nullable', 'numeric', 'min:0'],
            'price_exam_neco' => ['nullable', 'numeric', 'min:0'],
            'price_exam_nabteb' => ['nullable', 'numeric', 'min:0'],

            // Branding
            'site_name' => ['nullable', 'string', 'max:80'],
            'site_logo_file' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
            'site_favicon_file' => ['nullable', 'file', 'mimes:ico,png', 'max:1024'],

            // Popup + WhatsApp
            'home_popup_enabled' => ['nullable', 'in:0,1'],
            'home_popup_message' => ['nullable', 'string', 'max:300'],
            'whatsapp_link' => ['nullable', 'url'],
        ]);

        // Ensure branding directory exists
        $brandingDir = public_path('branding');
        if (!File::exists($brandingDir)) {
            File::makeDirectory($brandingDir, 0755, true);
        }

        // Handle logo upload
        if ($request->hasFile('site_logo_file')) {
            $file = $request->file('site_logo_file');
            $ext = strtolower($file->getClientOriginalExtension());
            $name = 'logo.' . $ext;

            $file->move($brandingDir, $name);
            $validated['site_logo'] = '/branding/' . $name;
        }

        // Handle favicon upload
        if ($request->hasFile('site_favicon_file')) {
            $file = $request->file('site_favicon_file');
            $ext = strtolower($file->getClientOriginalExtension());
            $name = 'favicon.' . $ext;

            $file->move($brandingDir, $name);
            $validated['site_favicon'] = '/branding/' . $name;
        }

        // Prepare settings key/value writes
        $items = [
            'provider' => $validated['provider'],

            'markup_airtime' => $validated['markup_airtime'],
            'markup_data' => $validated['markup_data'],
            'markup_cable' => $validated['markup_cable'],
            'markup_electricity' => $validated['markup_electricity'],
            'markup_exam' => $validated['markup_exam'],

            'price_exam_waec' => $validated['price_exam_waec'] ?? setting('price_exam_waec', '0'),
            'price_exam_neco' => $validated['price_exam_neco'] ?? setting('price_exam_neco', '0'),
            'price_exam_nabteb' => $validated['price_exam_nabteb'] ?? setting('price_exam_nabteb', '0'),

            'site_name' => $validated['site_name'] ?? setting('site_name', 'My VTU'),

            'home_popup_enabled' => $validated['home_popup_enabled'] ?? setting('home_popup_enabled', '1'),
            'home_popup_message' => $validated['home_popup_message'] ?? setting('home_popup_message', ''),
            'whatsapp_link' => $validated['whatsapp_link'] ?? setting('whatsapp_link', ''),
        ];

        // If uploads set these, store them too
        if (!empty($validated['site_logo'])) {
            $items['site_logo'] = $validated['site_logo'];
        }
        if (!empty($validated['site_favicon'])) {
            $items['site_favicon'] = $validated['site_favicon'];
        }

        foreach ($items as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => (string) $value]
            );
        }

        settings_flush_cache();

        return back()->with('success', 'Settings saved successfully ✅');
    }
}
