<?php

declare(strict_types=1);

namespace App\Providers;

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
