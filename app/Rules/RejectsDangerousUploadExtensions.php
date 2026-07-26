<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Etapa 05 (auditoria A5, ruta de subida): Filament's default naming keeps
 * the *client* original extension (Str::ulid().'.'.$file->getClientOriginalExtension()),
 * and ->preserveFilenames() keeps the client name as-is. Either way, a
 * polyglot upload like "invoice.pdf.php" (valid PDF bytes, so it also
 * passes the ->acceptedFileTypes() mimetypes rule, but named so a
 * misconfigured web server would execute it as PHP) can end up stored with
 * a ".php" extension. This rule:
 *
 * 1. Rejects the value outright if the *raw* string contains a path
 *    traversal token ("..") or a directory separator, before anything else
 *    touches it. Belt-and-suspenders: Symfony's UploadedFile already
 *    reduces getClientOriginalName() to a basename, but this rule is meant
 *    to be the last line of defense for any string reaching this class
 *    directly (e.g. a value read back from a DB column), not just the
 *    Livewire upload flow.
 * 2. Enforces an explicit final-extension whitelist (independent from the
 *    mimetypes check, which only looks at file content).
 * 3. Rejects the whole filename if ANY dot-separated segment other than
 *    the base name matches a dangerous extension, anywhere in the chain
 *    (catches "invoice.pdf.php" and "invoice.php.pdf" alike) — because a
 *    misconfigured Apache AddHandler executes based on any recognized
 *    extension token in a multi-dot filename, not just the last one.
 */
class RejectsDangerousUploadExtensions implements ValidationRule
{
    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar',
        'exe', 'sh', 'bat', 'cmd', 'cgi', 'pl', 'py', 'rb', 'jsp', 'asp',
        'aspx', 'htaccess', 'config', 'js',
    ];

    /**
     * @param  array<int, string>  $allowedExtensions final-extension whitelist, lowercase, no dot.
     */
    public function __construct(private readonly array $allowedExtensions)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $originalName = match (true) {
            $value instanceof UploadedFile => $value->getClientOriginalName(),
            is_string($value) => $value,
            default => null,
        };

        if ($originalName === null || $originalName === '') {
            return;
        }

        if (str_contains($originalName, '..') || str_contains($originalName, '/') || str_contains($originalName, '\\')) {
            $fail(__('wo.invalid_upload_name'));

            return;
        }

        $base = basename($originalName);
        $segments = explode('.', $base);

        if (count($segments) < 2) {
            $fail(__('wo.invalid_upload_extension'));

            return;
        }

        $finalExtension = strtolower((string) end($segments));

        if (! in_array($finalExtension, $this->allowedExtensions, true)) {
            $fail(__('wo.invalid_upload_extension'));

            return;
        }

        foreach (array_slice($segments, 1) as $segment) {
            if (in_array(strtolower($segment), self::DANGEROUS_EXTENSIONS, true)) {
                $fail(__('wo.invalid_upload_extension'));

                return;
            }
        }
    }
}
