<?php

namespace App\Http\Controllers;

use App\Models\CredencialEmitida;
use App\Models\LocPersona;
use App\Models\SolicitudCredencial;
use App\Models\SolicitudElemento;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard', [
            'metrics' => [
                'people' => LocPersona::query()->count(),
                'requests' => SolicitudCredencial::query()->count(),
                'request_elements' => SolicitudElemento::query()->count(),
                'credentials' => CredencialEmitida::query()->count(),
            ],
        ]);
    }
}
