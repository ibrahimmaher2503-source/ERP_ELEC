<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use App\Modules\Organization\Domain\MasterChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SaveCompany
{
    public function execute(User $actor, array $input, ?int $expectedVersion, mixed $logo = null): object
    {
        $storedPath = null;
        try {
            return DB::transaction(function () use ($actor, $input, $expectedVersion, $logo, &$storedPath) {
                DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
                app(OrganizationAccess::class)->authorize($actor, 'M01.company_update');
                DB::select('SELECT pg_advisory_xact_lock(10101)');
                $current = DB::table('companies')->where('id', 1)->lockForUpdate()->first();
                if (($current?->version) !== $expectedVersion) {
                    throw ValidationException::withMessages(['form.name' => 'تغيرت البيانات. أغلق النموذج وافتحه مجددًا.']);
                }
                if (is_string($input['code'] ?? null)) {
                    $input['code'] = \Normalizer::normalize(mb_strtoupper(trim($input['code'])), \Normalizer::FORM_C);
                }
                if (is_string($input['name'] ?? null)) {
                    $input['name'] = trim($input['name']);
                }
                $data = Validator::make($input, [
                    'code' => ['required', 'string', 'max:50', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
                    'name' => ['required', 'string', 'max:200', 'not_regex:/\x00/u'], 'legal_name' => ['nullable', 'string', 'max:200', 'not_regex:/\x00/u'],
                    'tax_registration' => ['nullable', 'string', 'max:100', 'not_regex:/\x00/u'], 'address' => ['nullable', 'string', 'max:2000', 'not_regex:/\x00/u'],
                    'commercial_registration' => ['nullable', 'string', 'max:100', 'not_regex:/\x00/u'],
                    'phone' => ['nullable', 'string', 'max:50', 'not_regex:/\x00/u'],
                    'email' => ['nullable', 'email', 'max:255'],
                ])->validate();
                Validator::make(['logo' => $logo], ['logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=3000,max_height=3000']])->validate();
                if ($logo !== null) {
                    $storedPath = $logo->store('company-logos', 'local');
                    if (! $storedPath) {
                        throw ValidationException::withMessages(['logo' => 'تعذر حفظ الشعار. حاول مرة أخرى.']);
                    }
                    $data['logo_path'] = $storedPath;
                }
                $data += ['currency' => 'EGP', 'setup_complete' => true, 'version' => ($current?->version ?? 0) + 1, 'updated_at' => now()];
                if ($current) {
                    DB::table('companies')->where('id', 1)->update($data);
                } else {
                    DB::table('companies')->insert($data + ['id' => 1, 'created_at' => now()]);
                }
                $saved = DB::table('companies')->where('id', 1)->first();
                event(new MasterChanged($actor->id, 'company', 1, $current ? 'updated' : 'created', $current ? (array) $current : null, (array) $saved));

                return $saved;
            });
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }
}
