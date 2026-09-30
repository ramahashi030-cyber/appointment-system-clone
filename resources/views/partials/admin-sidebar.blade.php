@php
    $adminLinks = [
        ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-fill'],
        ['route' => 'admin.patients', 'label' => 'Patients', 'icon' => 'bi-people-fill'],
        ['route' => 'admin.doctors', 'label' => 'Doctors', 'icon' => 'bi-person-badge-fill'],
        ['route' => 'admin.triagers', 'label' => 'Triagers', 'icon' => 'bi-clipboard2-pulse-fill'],
        ['route' => 'admin.appointments', 'label' => 'Appointments', 'icon' => 'bi-calendar2-week-fill'],
        ['route' => 'admin.audit-logs', 'label' => 'Audit Logs', 'icon' => 'bi-journal-text'],
        ['route' => 'admin.records', 'label' => 'Records', 'icon' => 'bi-file-earmark-medical-fill'],
        ['route' => 'admin.reports', 'label' => 'Reports', 'icon' => 'bi-bar-chart-fill'],
        ['route' => 'admin.settings', 'label' => 'Settings', 'icon' => 'bi-gear-fill'],
    ];
@endphp

<aside class="admin-sidebar d-none d-lg-flex" aria-label="Admin navigation">
    <div class="admin-sidebar-waves" aria-hidden="true"></div>

    <a class="admin-brand" href="{{ route('admin.dashboard') }}" aria-label="QMMC admin dashboard">
        <span class="admin-brand-mark" aria-hidden="true"><i class="bi bi-shield-fill"></i></span>
        <span class="admin-brand-copy"><strong>QMMC</strong><small>ADMIN PANEL</small></span>
    </a>

    <nav class="admin-sidebar-nav">
        @foreach ($adminLinks as $link)
            @php
                $isActive = request()->routeIs($link['route'])
                    || ($link['route'] === 'admin.doctors' && request()->routeIs('admin.doctors.*'));
            @endphp
            <a class="admin-sidebar-link {{ $isActive ? 'active' : '' }}"
               href="{{ route($link['route']) }}"
               @if ($isActive) aria-current="page" @endif>
                <i class="bi {{ $link['icon'] }}" aria-hidden="true"></i>
                <span>{{ $link['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div class="admin-sidebar-footer">
        <i class="bi bi-heart-pulse-fill" aria-hidden="true"></i>
        <span>Your Health, <em>Our Priority</em></span>
    </div>
</aside>

<div class="offcanvas offcanvas-start admin-mobile-menu" tabindex="-1" id="adminMobileMenu" aria-labelledby="adminMobileMenuLabel">
    <div class="offcanvas-header admin-mobile-menu-header">
        <a class="admin-brand" href="{{ route('admin.dashboard') }}" id="adminMobileMenuLabel">
            <span class="admin-brand-mark" aria-hidden="true"><i class="bi bi-shield-fill"></i></span>
            <span class="admin-brand-copy"><strong>QMMC</strong><small>ADMIN PANEL</small></span>
        </a>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close menu"></button>
    </div>

    <div class="offcanvas-body admin-mobile-menu-body">
        <nav class="admin-sidebar-nav">
            @foreach ($adminLinks as $link)
                @php
                    $isActive = request()->routeIs($link['route'])
                        || ($link['route'] === 'admin.doctors' && request()->routeIs('admin.doctors.*'));
                @endphp
                <a class="admin-sidebar-link {{ $isActive ? 'active' : '' }}"
                   href="{{ route($link['route']) }}"
                   @if ($isActive) aria-current="page" @endif>
                    <i class="bi {{ $link['icon'] }}" aria-hidden="true"></i>
                    <span>{{ $link['label'] }}</span>
                </a>
            @endforeach
        </nav>

        <form class="admin-mobile-logout" action="{{ route('admin.logout') }}" method="POST">
            @csrf
            <button type="submit">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                Logout
            </button>
        </form>
    </div>
</div>