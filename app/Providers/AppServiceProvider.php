<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AiChat\AiChatLlmClient;
use App\Services\AiChat\GeminiLlmClient;
use App\Services\GoogleCalendar\GoogleApiCalendarGateway;
use App\Services\GoogleCalendar\GoogleCalendarGateway;
use App\Services\GoogleCalendar\GoogleCalendarService;
use App\Services\Stripe\CheckoutSessionGateway;
use App\Services\Stripe\StripeCheckoutSessionGateway;
use App\View\Composers\EnrollmentSwitcherComposer;
use App\View\Composers\NotificationBadgeComposer;
use App\View\Composers\SectionPageMetaComposer;
use App\View\Composers\SidebarBadgeComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AiChatLlmClient::class, fn () => new GeminiLlmClient(
            config('ai-chat.gemini.api_key'),
            (string) config('ai-chat.gemini.model'),
            (string) config('ai-chat.gemini.base_url'),
            (int) config('ai-chat.gemini.timeout'),
        ));

        $this->app->bind(GoogleCalendarGateway::class, fn () => new GoogleApiCalendarGateway(
            (string) config('services.google.client_id'),
            (string) config('services.google.client_secret'),
            (string) config('services.google.redirect'),
        ));
        // 空き時間のメモ化をリクエスト内で共有するため 1 インスタンスにする
        $this->app->scoped(GoogleCalendarService::class);

        $this->app->bind(CheckoutSessionGateway::class, fn () => new StripeCheckoutSessionGateway(
            new StripeClient((string) config('services.stripe.secret')),
            (string) config('services.stripe.currency', 'jpy'),
        ));
    }

    public function boot(): void
    {
        View::composer('layouts._partials.sidebar-*', SidebarBadgeComposer::class);
        View::composer('layouts._partials.topbar', NotificationBadgeComposer::class);
        View::composer('components.enrollment-switcher', EnrollmentSwitcherComposer::class);
        View::composer('learning.sections.show', SectionPageMetaComposer::class);
    }
}
