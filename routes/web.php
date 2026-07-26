<?php

use App\Exports\FleetExport;
use App\Http\Middleware\SetLocale;
use App\Livewire\Field\ForemanBoard;
use App\Livewire\Field\FuelLog;
use App\Livewire\Field\Home as FieldHome;
use App\Livewire\Field\Login as FieldLogin;
use App\Livewire\Field\ReportForm;
use App\Models\Machine;
use App\Models\Quote;
use App\Models\WorkOrderAttachment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

Route::get('/', fn () => redirect('/admin'));

/*
|--------------------------------------------------------------------------
| Public quote link
|--------------------------------------------------------------------------
| Administradores generan un link compartible (share_token) para que
| cualquier persona (aunque no tenga cuenta) vea/descargue una cotización.
| Sin middleware "auth" a propósito.
*/
Route::get('/quotes/{token}', function (string $token) {
    $quote = Quote::query()->where('share_token', $token)->firstOrFail();

    return view('quotes.show', [
        'quote' => $quote,
        'expired' => $quote->expires_at !== null && $quote->expires_at->isPast(),
    ]);
})->name('quotes.public');

/*
|--------------------------------------------------------------------------
| Public quote file (hallazgo A5)
|--------------------------------------------------------------------------
| El archivo vive en disk('local') (privado) desde el fix de A5. Esta ruta
| es la unica forma de descargarlo sin cuenta: valida el share_token (no un
| path recibido del cliente -> sin riesgo de path traversal) y respeta el
| vencimiento. Antes, quotes/show.blade.php enlazaba directo a la URL
| publica de Storage::disk('public'), asi que una cotizacion vencida se
| seguia descargando igual; con esta ruta, si expiro no se entrega el
| archivo (404).
*/
Route::get('/quotes/{token}/archivo', function (string $token) {
    $quote = Quote::query()->where('share_token', $token)->firstOrFail();

    $expired = $quote->expires_at !== null && $quote->expires_at->isPast();

    abort_if($expired, 404);
    abort_if(blank($quote->file_path), 404);
    abort_unless(Storage::disk('local')->exists($quote->file_path), 404);

    $downloadName = trim($quote->title !== '' ? $quote->title : 'quote').'.'.pathinfo($quote->file_path, PATHINFO_EXTENSION);

    return Storage::disk('local')->response($quote->file_path, $downloadName);
})->name('quotes.public.file');

/*
|--------------------------------------------------------------------------
| Fleet reports (PDF / Excel)
|--------------------------------------------------------------------------
| Rutas autenticadas normales (no acciones Livewire) para que el navegador
| pueda navegar/descargar directo, abiertas desde una acción de cabecera de
| Filament. Las columnas de costo solo se incluyen para quien tiene
| "view_costs".
*/
Route::middleware(['auth', SetLocale::class])->group(function () {
    /*
     * Hallazgo A5: adjuntos de OT (fotos y FACTURAS = evidencia de costos)
     * vivian en disk('public') sin ninguna capa de autorizacion (200 sin
     * sesion). Se sirven por id/modelo via route model binding -> {attachment}
     * jamas es un path recibido del cliente, sin riesgo de path traversal.
     * Regla: view_fleet es la base (mismo permiso que ve la OT); type=invoice
     * exige ademas view_costs, que es exactamente lo que pide el brief del
     * cliente ("un rol sin view_costs no accede a una factura").
     */
    Route::get('/attachments/{attachment}/archivo', function (WorkOrderAttachment $attachment) {
        $user = Auth::user();

        abort_unless($user?->can('view_fleet'), 403);

        if ($attachment->type === 'invoice') {
            abort_unless($user->can('view_costs'), 403);
        }

        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        $downloadName = $attachment->original_name ?: basename($attachment->path);

        return Storage::disk('local')->response($attachment->path, $downloadName);
    })->name('attachments.download');

    Route::get('/reports/fleet.pdf', function () {
        abort_unless(Auth::user()?->can('view_reports'), 403);

        $includeCosts = Auth::user()?->can('view_costs') ?? false;

        $query = Machine::query()->with(['category', 'make', 'location'])->orderBy('id_code');

        if ($includeCosts) {
            $query
                ->withSum(['workOrders as completed_parts_cost' => fn ($q) => $q->where('status', 'completed')], 'parts_cost')
                ->withCount(['workOrders as completed_service_count' => fn ($q) => $q->where('status', 'completed')]);
        }

        $pdf = Pdf::loadView('exports.fleet-report', [
            'machines' => $query->get(),
            'includeCosts' => $includeCosts,
            'generatedAt' => now()->format('Y-m-d H:i'),
            'logoPath' => public_path('images/dp-logo.jpg'),
        ]);

        return $pdf->download('fleet-status-'.now()->format('Ymd-His').'.pdf');
    })->name('reports.fleet.pdf');

    Route::get('/reports/fleet.xlsx', function () {
        abort_unless(Auth::user()?->can('view_reports'), 403);

        $includeCosts = Auth::user()?->can('view_costs') ?? false;

        return Excel::download(new FleetExport($includeCosts), 'fleet-status-'.now()->format('Ymd-His').'.xlsx');
    })->name('reports.fleet.xlsx');
});

// Cambio de idioma (EN/ES) — guarda preferencia del usuario y en sesión
Route::get('/locale/{locale}', function (string $locale) {
    if (! in_array($locale, ['en', 'es'], true)) {
        abort(404);
    }
    session(['locale' => $locale]);
    if ($user = Auth::user()) {
        $user->forceFill(['locale' => $locale])->save();
    }

    return back();
})->name('locale.switch');

/*
|--------------------------------------------------------------------------
| PWA móvil de campo (/field)
|--------------------------------------------------------------------------
| Pantallas Livewire para los roles de campo (foreman, operador_cisterna,
| personal_mantenimiento), que no tienen acceso al panel Filament. El
| propio componente Login redirige a /admin si el usuario sí tiene acceso
| al panel, por lo que no se protege con middleware "guest".
*/
// Se nombra "login" (y no "field.login") porque el middleware "auth" genérico de
// Illuminate redirige a la ruta con nombre "login" cuando el usuario no está autenticado.
Route::get('/field/login', FieldLogin::class)->name('login');

Route::post('/field/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('field.logout');

Route::middleware(['auth', SetLocale::class])->prefix('field')->name('field.')->group(function () {
    Route::get('/', FieldHome::class)->name('home');
    Route::get('/fuel', FuelLog::class)->name('fuel');
    Route::get('/report', ReportForm::class)->name('report');
    Route::get('/foreman', ForemanBoard::class)->name('foreman');
});

/*
|--------------------------------------------------------------------------
| Fallback (Etapa 05, Bloque 5 — M6, hallazgo de QA sobre 404)
|--------------------------------------------------------------------------
| Una URL que no matchea NINGUNA ruta (ni siquiera un typo dentro de /admin
| o /field) no pasa por el middleware de ningun grupo -> Laravel resuelve
| el 404 directo en el router, ANTES de armar el pipeline de la ruta. Sin
| este fallback, SetLocale nunca corria para una URL inexistente y esa
| pagina 404 quedaba siempre en el locale por defecto de la app, sin
| importar el idioma guardado del usuario autenticado. Se reutiliza el
| mismo SetLocale (nada de logica de idioma duplicada). Debe ser la ULTIMA
| ruta del archivo (asi lo pide Laravel: el fallback solo se intenta cuando
| ninguna otra ruta matcheo).
*/
Route::fallback(fn () => abort(404))->middleware(['web', SetLocale::class]);
