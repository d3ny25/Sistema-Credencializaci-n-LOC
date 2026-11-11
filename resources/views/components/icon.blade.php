@props(['name', 'class' => 'h-5 w-5'])

<svg xmlns="http://www.w3.org/2000/svg" class="{{ $class }}" viewBox="0 0 24 24" fill="none"
     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true">
    @switch($name)
        @case('shield')
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            @break
        @case('dashboard')
            <rect x="3" y="3" width="7" height="9" rx="1"/>
            <rect x="14" y="3" width="7" height="5" rx="1"/>
            <rect x="14" y="12" width="7" height="9" rx="1"/>
            <rect x="3" y="16" width="7" height="5" rx="1"/>
            @break
        @case('database')
            <ellipse cx="12" cy="5" rx="9" ry="3"/>
            <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
            <path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>
            @break
        @case('idcard')
            <rect x="2" y="4" width="20" height="16" rx="2"/>
            <circle cx="8" cy="10" r="2"/>
            <path d="M6 16c0-1.5 1.3-2.5 2-2.5s2 1 2 2.5"/>
            <path d="M14 9h5m-5 4h5"/>
            @break
        @case('inbox')
            <path d="M4 4h16l2 9v6H2v-6l2-9z"/>
            <path d="M2 13h6l2 3h4l2-3h6"/>
            @break
        @case('list')
            <path d="M9 6h12M9 12h12M9 18h12"/>
            <path d="M4 6h.01M4 12h.01M4 18h.01"/>
            @break
        @case('users')
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
            @break
        @case('creditcard')
            <rect x="2" y="5" width="20" height="14" rx="2"/>
            <path d="M2 10h20"/>
            @break
        @case('fingerprint')
            <path d="M2 12a10 10 0 0 1 18-6"/>
            <path d="M5 19a30 30 0 0 0 1-7 6 6 0 0 1 10-4"/>
            <path d="M9 22c1-2 1-5 1-10a2 2 0 0 1 4 0c0 4 0 7-1 10"/>
            <path d="M18 13c0 3-.2 5-.6 7"/>
            @break
        @case('clock')
            <circle cx="12" cy="12" r="10"/>
            <path d="M12 6v6l4 2"/>
            @break
        @case('printer')
            <path d="M6 9V2h12v7"/>
            <rect x="6" y="14" width="12" height="8"/>
            <path d="M6 18H4a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2"/>
            @break
        @case('truck')
            <path d="M10 17h4V5H2v12h3"/>
            <path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8h1"/>
            <circle cx="7.5" cy="17.5" r="1.5"/>
            <circle cx="17.5" cy="17.5" r="1.5"/>
            @break
        @case('search')
            <circle cx="11" cy="11" r="7"/>
            <path d="m21 21-4.35-4.35"/>
            @break
        @case('chart')
            <path d="M3 3v18h18"/>
            <rect x="7" y="10" width="3" height="7"/>
            <rect x="12" y="6" width="3" height="11"/>
            <rect x="17" y="13" width="3" height="4"/>
            @break
        @case('settings')
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            @break
        @case('menu')
            <path d="M3 12h18M3 6h18M3 18h18"/>
            @break
        @case('arrowRight')
            <path d="M5 12h14"/>
            <path d="m12 5 7 7-7 7"/>
            @break
        @case('bell')
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            @break
        @case('info')
            <circle cx="12" cy="12" r="10"/>
            <path d="M12 16v-4m0-4h.01"/>
            @break
        @case('plus')
            <path d="M12 5v14M5 12h14"/>
            @break
        @case('x')
            <path d="M18 6 6 18M6 6l12 12"/>
            @break
        @case('upload')
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="17 8 12 3 7 8"/>
            <line x1="12" y1="3" x2="12" y2="15"/>
            @break
        @case('check')
            <polyline points="20 6 9 17 4 12"/>
            @break
        @case('alert')
            <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
            @break
        @case('chevronDown')
            <path d="m6 9 6 6 6-6"/>
            @break
        @case('file')
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
            @break
        @case('trash')
            <polyline points="3 6 5 6 21 6"/>
            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
            @break
    @endswitch
</svg>
