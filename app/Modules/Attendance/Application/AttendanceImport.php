<?php

namespace App\Modules\Attendance\Application;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AttendanceImport
{
    public function import(int $actor, UploadedFile $file): array
    {
        Validator::make(compact('file'), ['file' => 'required|file|max:2048|mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'])->validate();
        $stream = fopen($file->getRealPath(), 'rb');
        abort_unless($stream, 422, 'تعذر قراءة ملف الحضور.');
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            if (is_array($header)) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }
            if ($header !== ['employee_id', 'occurred_at', 'kind', 'source_key']) {
                throw ValidationException::withMessages(['file' => 'يلزم قالب employee_id,occurred_at,kind,source_key بالترتيب.']);
            }
            $rows = [];
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== 4 || count($rows) >= 1000) {
                    throw ValidationException::withMessages(['file' => 'حد الملف ١٠٠٠ واقعة بأربعة أعمدة مكتملة.']);
                }
                $rows[] = Validator::make(array_combine($header, $row), ['employee_id' => 'required|integer|min:1', 'occurred_at' => 'required|string', 'kind' => 'required|in:in,out,break_start,break_end', 'source_key' => 'required|uuid'])->validate();
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'ملف الحضور فارغ.']);
            }

            return DB::transaction(function () use ($actor, $rows) {
                app(AttendanceActions::class)->lock();
                $ids = [];
                foreach ($rows as $index => $row) {
                    try {
                        $ids[] = app(AttendanceActions::class)->punch($actor, (int) $row['employee_id'], $row['occurred_at'], $row['kind'], $row['source_key']);
                    } catch (ValidationException $e) {
                        throw ValidationException::withMessages(['file' => 'السطر '.($index + 2).': '.implode(' ', $e->validator->errors()->all())]);
                    }
                }

                return ['rows' => count($rows), 'punch_ids' => $ids];
            }, 3);
        } finally {
            fclose($stream);
        }
    }
}
