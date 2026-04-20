<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $routeMeta = [
            'home' => ['changefreq' => 'daily', 'priority' => '1.0'],
            'guides.cheap-data' => ['changefreq' => 'weekly', 'priority' => '0.9'],
            'guides.fund-wallet' => ['changefreq' => 'weekly', 'priority' => '0.8'],
            'guides.electricity-bills' => ['changefreq' => 'weekly', 'priority' => '0.8'],
            'guides.nin-services' => ['changefreq' => 'weekly', 'priority' => '0.8'],
            'guides.education-services' => ['changefreq' => 'weekly', 'priority' => '0.8'],
            'guides.premium-apps' => ['changefreq' => 'weekly', 'priority' => '0.8'],
        ];

        $urls = [];
        foreach ($routeMeta as $routeName => $meta) {
            $urls[] = [
                'loc' => route($routeName),
                'lastmod' => now()->toDateString(),
                'changefreq' => $meta['changefreq'],
                'priority' => $meta['priority'],
            ];
        }

        $xml = view('sitemap', compact('urls'))->render();

        return response($xml, 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
